<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Model 8 (Sector Timing) — v0 LOOKUP, no training.
 *
 * Answers "is now a good time for this sector?" from the Tunisian Stock Exchange sector
 * performance (ml.tse_sectors) + macro context (ml.macro_indicators) — the data Mahdi's
 * scrapers feed into the `ml` schema. Rule-based momentum signal for now; swap for a trained
 * time-series model once enough history accumulates. The contract (score + signal) stays the same.
 *
 * Fail-safe: the `ml` schema / tables may not exist yet on every environment (e.g. Neon before
 * the Tunisian data is migrated) — every method degrades to {available:false} instead of throwing.
 */
class SectorTimingService
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Timing signal for a sector. @return array<string,mixed>
     */
    public function getSectorTiming(?string $sector): array
    {
        if (!$sector || trim($sector) === '') {
            return ['available' => false, 'note' => 'No sector provided.'];
        }
        try {
            // latest TSE row for this sector (flexible match on the friend's naming)
            $row = $this->connection->fetchAssociative(
                "SELECT sector, index_value, change_pct, volume, period
                 FROM ml.tse_sectors
                 WHERE sector ILIKE :s OR :s ILIKE '%' || sector || '%'
                 ORDER BY period DESC NULLS LAST LIMIT 1",
                ['s' => trim($sector)]
            );
        } catch (\Throwable) {
            return ['available' => false, 'note' => 'Sector timing data not available yet.'];
        }
        if (!$row) {
            return ['available' => false, 'note' => 'No TSE data for this sector yet.'];
        }

        $change = $row['change_pct'] !== null ? (float) $row['change_pct'] : null;
        $score = $this->timingScore($change);
        return [
            'available' => true,
            'sector' => $row['sector'],
            'score' => $score,                 // 0..100, higher = better time to enter the sector
            'signal' => $this->signal($score),
            'change_pct' => $change,
            'index_value' => $row['index_value'] !== null ? (float) $row['index_value'] : null,
            'period' => $row['period'],
        ];
    }

    /** Momentum -> timing score (no change data => neutral 50). */
    private function timingScore(?float $changePct): int
    {
        if ($changePct === null) {
            return 50;
        }
        return (int) round(max(0, min(100, match (true) {
            $changePct >= 5  => 82,
            $changePct >= 1  => 66,
            $changePct > -1  => 50,
            $changePct > -5  => 34,
            default          => 20,
        })));
    }

    private function signal(int $score): string
    {
        return $score >= 66 ? 'Favorable' : ($score >= 40 ? 'Neutral' : 'Unfavorable');
    }
}
