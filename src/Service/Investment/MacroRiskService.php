<?php

namespace App\Service\Investment;

use Doctrine\DBAL\Connection;

/**
 * Model 4 (Macroeconomic Risk) — v0 LOOKUP, no training.
 *
 * Reads the REAL World Bank indicators we loaded into ml_data.worldbank_wide
 * (inflation, GDP growth, FX, political stability, FDI, unemployment) for a project's
 * country and produces a dynamic risk score WITH a breakdown by factor — exactly the
 * report's Model 4 output. Replaces the hardcoded/placeholder economic inputs with real,
 * local, current data; no slow external API call.
 *
 * Upgrade path: when enough deals close, swap the weighted formula for a trained
 * regression on the same indicators. The contract (score + factors) stays identical.
 */
class MacroRiskService
{
    /** Map the submission form's country names (+ 2-letter codes) to World Bank ISO3. */
    private const ISO3 = [
        'tunisia' => 'TUN', 'tn' => 'TUN',
        'algeria' => 'DZA', 'dz' => 'DZA',
        'morocco' => 'MAR', 'ma' => 'MAR',
        'egypt' => 'EGY', 'eg' => 'EGY',
        'libya' => 'LBY', 'ly' => 'LBY',
        'nigeria' => 'NGA', 'ng' => 'NGA',
        'kenya' => 'KEN', 'ke' => 'KEN',
        'south africa' => 'ZAF', 'za' => 'ZAF',
        'ghana' => 'GHA', 'gh' => 'GHA',
        'senegal' => 'SEN', 'sn' => 'SEN',
        'ivory coast' => 'CIV', 'ci' => 'CIV',
        'saudi arabia' => 'SAU', 'sa' => 'SAU',
        'uae' => 'ARE', 'ae' => 'ARE',
        'jordan' => 'JOR', 'jo' => 'JOR',
        'lebanon' => 'LBN', 'lb' => 'LBN',
    ];

    public function __construct(private readonly Connection $connection) {}

    public function resolveIso3(?string $countryRef): ?string
    {
        if (!$countryRef) {
            return null;
        }
        $k = mb_strtolower(trim($countryRef));
        if (isset(self::ISO3[$k])) {
            return self::ISO3[$k];
        }
        // already an ISO3?
        return preg_match('/^[A-Za-z]{3}$/', $countryRef) ? strtoupper($countryRef) : null;
    }

    /**
     * Latest-available real indicators for a country.
     * @return array<string,mixed>|null  null if the country isn't in the dataset
     */
    public function getIndicators(?string $countryRef): ?array
    {
        $iso3 = $this->resolveIso3($countryRef);
        if ($iso3 === null) {
            return null;
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT year, inflation_cpi_pct, gdp_growth_pct, gdp_per_capita_usd,
                        unemployment_pct, official_exchange_rate_usd,
                        political_stability_estimate, fdi_net_inflows_pct_gdp
                 FROM ml_data.worldbank_wide WHERE country_code = :c ORDER BY year DESC',
                ['c' => $iso3]
            );
        } catch (\Throwable) {
            return null; // ml_data not present (e.g. local without the schema) — caller falls back
        }
        if (!$rows) {
            return null;
        }
        // take the most recent non-null value for each indicator
        $fields = ['inflation_cpi_pct', 'gdp_growth_pct', 'gdp_per_capita_usd', 'unemployment_pct',
                   'official_exchange_rate_usd', 'political_stability_estimate', 'fdi_net_inflows_pct_gdp'];
        $out = ['country_code' => $iso3];
        foreach ($fields as $f) {
            $out[$f] = null;
            $out[$f . '_year'] = null;
            foreach ($rows as $r) {
                if ($r[$f] !== null && $r[$f] !== '') {
                    $out[$f] = (float) $r[$f];
                    $out[$f . '_year'] = (int) $r['year'];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Dynamic macro risk score (0-100, higher = riskier) with a per-factor breakdown.
     * @return array<string,mixed>
     */
    public function getRiskBreakdown(?string $countryRef): array
    {
        $ind = $this->getIndicators($countryRef);
        if ($ind === null) {
            return ['available' => false, 'score' => 50, 'level' => 'Unknown',
                    'factors' => [], 'note' => 'No macro data for this country.'];
        }

        $factors = [];
        if ($ind['inflation_cpi_pct'] !== null) {
            $factors['inflation'] = $this->inflationRisk($ind['inflation_cpi_pct']);
        }
        if ($ind['gdp_growth_pct'] !== null) {
            $factors['gdp_growth'] = $this->growthRisk($ind['gdp_growth_pct']);
        }
        if ($ind['political_stability_estimate'] !== null) {
            $factors['political_stability'] = $this->stabilityRisk($ind['political_stability_estimate']);
        }
        if ($ind['fdi_net_inflows_pct_gdp'] !== null) {
            $factors['fdi'] = $this->fdiRisk($ind['fdi_net_inflows_pct_gdp']);
        }
        if ($ind['unemployment_pct'] !== null) {
            $factors['unemployment'] = $this->unemploymentRisk($ind['unemployment_pct']);
        }

        $score = $factors ? (int) round(array_sum($factors) / count($factors)) : 50;
        return [
            'available' => true,
            'country_code' => $ind['country_code'],
            'score' => $score,
            'level' => $this->level($score),
            'factors' => $factors,
            'indicators' => $ind,
        ];
    }

    private function inflationRisk(float $v): int
    {
        $a = abs($v);
        return (int) round(max(0, min(100, $a <= 2 ? 15 : ($a <= 5 ? 30 : ($a <= 10 ? 55 : ($a <= 15 ? 78 : 92))))));
    }

    private function growthRisk(float $g): int
    {
        // higher growth = lower risk; recession = high risk
        return (int) round(max(0, min(100, $g >= 5 ? 15 : ($g >= 3 ? 30 : ($g >= 1 ? 50 : ($g >= 0 ? 65 : 85))))));
    }

    private function stabilityRisk(float $est): int
    {
        // WGI estimate ~ -2.5 (worst) .. +2.5 (best) -> map to 0..100 risk
        return (int) round(max(0, min(100, 50 - $est * 20)));
    }

    private function fdiRisk(float $pct): int
    {
        // more FDI (% GDP) = more investor confidence = lower risk
        return (int) round(max(0, min(100, $pct >= 5 ? 20 : ($pct >= 2 ? 40 : ($pct >= 0.5 ? 60 : 75)))));
    }

    private function unemploymentRisk(float $u): int
    {
        return (int) round(max(0, min(100, $u <= 5 ? 25 : ($u <= 10 ? 45 : ($u <= 15 ? 65 : 85)))));
    }

    private function level(int $score): string
    {
        return $score <= 33 ? 'Low' : ($score <= 66 ? 'Moderate' : 'High');
    }
}
