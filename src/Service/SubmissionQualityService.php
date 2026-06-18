<?php

namespace App\Service;

use App\Entity\Projet;
use App\Repository\ProjetRepository;

/**
 * Model 5 v0 — rule-based submission quality / fraud detection.
 *
 * Works on DAY ONE with zero data (no ML needed). Flags duplicate plans, empty/gibberish
 * text, and absurd numbers on a project submission. Returns a quality score + flags so the
 * admin moderation queue (and later the trained anomaly-detection model) can use them.
 *
 * Upgrade path: once enough real submissions exist, train an unsupervised anomaly detector
 * and blend its score with these rules. The contract (score + flags) stays the same.
 */
class SubmissionQualityService
{
    public function __construct(private readonly ProjetRepository $projetRepository) {}

    /**
     * @return array{score:int, suspicious:bool, flags:array<int,string>}
     */
    public function analyze(Projet $projet): array
    {
        $flags = [];
        $score = 100;

        $title = trim((string) $projet->getTitre());
        $desc = trim((string) $projet->getDescription());
        $db = $projet->getDonneesBusiness();

        // 1) Missing / too-short critical content
        if (mb_strlen($title) < 3) {
            $flags[] = 'title_too_short';
            $score -= 20;
        }
        if (mb_strlen($desc) < 30) {
            $flags[] = 'description_too_short';
            $score -= 20;
        }

        // 2) Gibberish / low-information text
        if ($desc !== '' && $this->looksLikeGibberish($desc)) {
            $flags[] = 'description_gibberish';
            $score -= 25;
        }

        // 3) Duplicate plan (exact title or description already on the platform)
        if ($this->isDuplicate($projet, $title, $desc)) {
            $flags[] = 'possible_duplicate';
            $score -= 35;
        }

        // 4) Absurd / inconsistent numbers
        if ($db !== null) {
            $costs = (float) $db->getCoutsEstimes();
            $revenue = (float) $db->getRevenusAttendus();
            $target = (float) ($db->getObjectifFinancement() ?? 0);
            $team = (int) ($db->getTailleEquipe() ?? 0);

            if ($costs < 0 || $revenue < 0 || $target < 0) {
                $flags[] = 'negative_financials';
                $score -= 20;
            }
            // implausible 100x+ revenue vs costs (common in fake/spam submissions)
            if ($costs > 0 && $revenue > 0 && $revenue / $costs > 100) {
                $flags[] = 'implausible_revenue_ratio';
                $score -= 15;
            }
            if ($target > 1_000_000_000) {
                $flags[] = 'absurd_funding_target';
                $score -= 15;
            }
            if ($team > 10000) {
                $flags[] = 'absurd_team_size';
                $score -= 10;
            }
        }

        $score = max(0, min(100, $score));
        return [
            'score' => $score,
            'suspicious' => $score < 50 || in_array('possible_duplicate', $flags, true),
            'flags' => $flags,
        ];
    }

    /** Heuristic: real prose has spaces, vowels, and isn't one char/word repeated. */
    private function looksLikeGibberish(string $text): bool
    {
        $t = mb_strtolower($text);
        $len = mb_strlen($t);
        if ($len === 0) {
            return true;
        }
        $words = preg_split('/\s+/', trim($t)) ?: [];
        if (count($words) < 3) {
            return true; // not enough words to be a description
        }
        // vowel ratio — almost no vowels = keyboard mashing
        $vowels = preg_match_all('/[aeiouàâéèêëîïôûùœ]/u', $t);
        if ($vowels / max($len, 1) < 0.15) {
            return true;
        }
        // a single character repeated (e.g. "aaaaaaaa") or the same word over and over
        if (preg_match('/(.)\1{9,}/u', $t)) {
            return true;
        }
        $unique = array_unique($words);
        if (count($words) >= 6 && count($unique) / count($words) < 0.3) {
            return true;
        }
        return false;
    }

    private function isDuplicate(Projet $projet, string $title, string $desc): bool
    {
        try {
            foreach ([['titre' => $title], ['description' => $desc]] as $criteria) {
                if (reset($criteria) === '') {
                    continue;
                }
                foreach ($this->projetRepository->findBy($criteria) as $other) {
                    if ($other->getId() !== $projet->getId()) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            // duplicate check is best-effort; never block a submission on it
        }
        return false;
    }
}
