<?php

namespace App\Service\Investment;

use App\Entity\InvestmentOpportunity;
use App\Entity\InvestorProfile;
use App\Repository\InvestmentOpportunityRepository;

class InvestmentMatchingService
{
    public function __construct(
        private readonly InvestmentOpportunityRepository $opportunityRepo,
    ) {
    }

    /**
     * Finds and scores all OPEN opportunities against the investor's profile.
     * Returns sorted by compatibility score (descending).
     *
     * @return array<array{opportunity: InvestmentOpportunity, score: int, explanation: string}>
     */
    public function findMatches(InvestorProfile $profile): array
    {
        $allOpen = $this->opportunityRepo->findBy(['status' => InvestmentOpportunity::STATUS_OPEN]);

        $results = [];
        foreach ($allOpen as $opp) {
            $score = $this->computeCompatibility($profile, $opp);
            $explanation = $this->buildExplanation($profile, $opp, $score);
            $results[] = [
                'opportunity' => $opp,
                'score' => $score,
                'explanation' => $explanation,
            ];
        }

        usort($results, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Computes compatibility score (0-100) between a profile and an opportunity.
     *
     * Weights: Sector 35%, Budget 25%, Risk 25%, Horizon 15%
     */
    private function computeCompatibility(InvestorProfile $profile, InvestmentOpportunity $opp): int
    {
        $sectorScore = $this->computeSectorScore($profile, $opp);
        $budgetScore = $this->computeBudgetScore($profile, $opp);
        $riskScore = $this->computeRiskAlignment($profile, $opp);
        $horizonScore = $this->computeHorizonScore($profile, $opp);

        $total = $sectorScore * 0.35
            + $budgetScore * 0.25
            + $riskScore * 0.25
            + $horizonScore * 0.15;

        // Geography adjustment (Model 2 v0): only applies when both the investor's
        // preferred country and the project's country are known — otherwise 0 (no change).
        $total += $this->computeGeographyAdjustment($profile, $opp);

        return (int) round(max(0, min(100, $total)));
    }

    /** +10 same country, -5 different, 0 when either side has no geography set. */
    private function computeGeographyAdjustment(InvestorProfile $profile, InvestmentOpportunity $opp): float
    {
        $pref = mb_strtolower(trim((string) $profile->getPreferredCountry()));
        $projectCountry = mb_strtolower(trim((string) $opp->getProject()?->getPays()));
        if ($pref === '' || $projectCountry === '') {
            return 0.0;
        }
        return $pref === $projectCountry ? 10.0 : -5.0;
    }

    private function computeSectorScore(InvestorProfile $profile, InvestmentOpportunity $opp): float
    {
        $project = $opp->getProject();
        if ($project === null || $project->getSecteur() === null) {
            return 50.0;
        }

        $preferred = $profile->getSectorArray();
        if (empty($preferred)) {
            return 60.0; // no preference = mildly positive
        }

        $projectSector = mb_strtolower(trim($project->getSecteur()));
        $projectDesc = mb_strtolower($project->getDescription() ?? '');

        foreach ($preferred as $sector) {
            $s = mb_strtolower(trim($sector));
            if ($s === '') continue;
            if (str_contains($projectSector, $s) || str_contains($s, $projectSector)) return 100.0;
            if (str_contains($projectDesc, $s)) return 80.0;
        }

        // Partial matching via character overlap
        foreach ($preferred as $sector) {
            $s = mb_strtolower(trim($sector));
            if ($s === '') continue;
            $sectorChars = array_unique(mb_str_split($s));
            $common = 0;
            foreach ($sectorChars as $c) {
                if (mb_strpos($projectSector, $c) !== false) {
                    $common++;
                }
            }
            if ($common >= 3 && $common >= count($sectorChars) * 0.5) return 60.0;
        }

        return 20.0;
    }

    private function computeBudgetScore(InvestorProfile $profile, InvestmentOpportunity $opp): float
    {
        $target = (float) $opp->getTargetAmount();
        if ($target <= 0) return 50.0;

        $min = (float) $profile->getBudgetMin();
        $max = (float) $profile->getBudgetMax();

        if ($target >= $min && $target <= $max) {
            return 100.0;
        }

        $range = $max - $min;
        if ($range <= 0) $range = 1.0;

        $distance = $target < $min ? ($min - $target) : ($target - $max);
        $ratio = $distance / $range;

        return max(0.0, 100.0 - $ratio * 100.0);
    }

    private function computeRiskAlignment(InvestorProfile $profile, InvestmentOpportunity $opp): float
    {
        if ($opp->getRiskScore() === null) return 60.0;

        $oppRisk = $opp->getRiskScore(); // 0-100
        $tolerance = $profile->getRiskTolerance(); // 1-10

        $expectedRisk = $tolerance * 10.0;
        $diff = abs($oppRisk - $expectedRisk);

        return max(0.0, 100.0 - $diff * 1.5);
    }

    private function computeHorizonScore(InvestorProfile $profile, InvestmentOpportunity $opp): float
    {
        if ($opp->getDeadline() === null) return 50.0;

        $now = new \DateTimeImmutable();
        $days = (int) $now->diff($opp->getDeadline())->format('%r%a');
        $monthsUntil = $days / 30.0;
        $preferredMonths = $profile->getHorizonMonths();

        if ($monthsUntil <= 0) return 10.0;

        $diff = abs($monthsUntil - $preferredMonths);
        return max(0.0, 100.0 - $diff * 8.0);
    }

    private function buildExplanation(InvestorProfile $profile, InvestmentOpportunity $opp, int $score): string
    {
        $reasons = [];

        $sectorScore = $this->computeSectorScore($profile, $opp);
        if ($sectorScore >= 80) $reasons[] = 'Secteur correspondant';
        elseif ($sectorScore >= 50) $reasons[] = 'Secteur partiellement compatible';
        else $reasons[] = 'Secteur different';

        $budgetScore = $this->computeBudgetScore($profile, $opp);
        if ($budgetScore >= 80) $reasons[] = 'Budget adapte';
        elseif ($budgetScore >= 40) $reasons[] = 'Budget proche de vos criteres';
        else $reasons[] = 'Hors budget';

        $riskScore = $this->computeRiskAlignment($profile, $opp);
        if ($riskScore >= 70) $reasons[] = 'Risque aligne avec votre tolerance';
        elseif ($riskScore >= 40) $reasons[] = 'Risque moderement compatible';
        else $reasons[] = 'Risque non compatible';

        return implode(' | ', $reasons);
    }
}
