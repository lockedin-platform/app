<?php

namespace App\Service\Investment;

class EconomicRiskEngine
{
    // Max target amount for normalization (500 000)
    private const MAX_AMOUNT = 500000;
    // Reference GDP in billions (OECD average ~2000)
    private const REFERENCE_GDP = 2000.0;
    // Target inflation (central bank ideal)
    private const TARGET_INFLATION = 2.0;
    // Critical inflation threshold
    private const CRITICAL_INFLATION = 15.0;
    // Reference EUR/USD rate
    private const REFERENCE_EUR_USD = 1.10;

    // Final score weights
    private const WEIGHT_AMOUNT = 0.3;
    private const WEIGHT_DURATION = 0.2;
    private const WEIGHT_ECONOMIC = 0.5;

    // Economic factor weights
    private const WEIGHT_EXCHANGE = 0.3;
    private const WEIGHT_GDP = 0.3;
    private const WEIGHT_INFLATION = 0.4;

    // Risk thresholds
    public const THRESHOLD_LOW = 33;
    public const THRESHOLD_MEDIUM = 66;

    /** @param array<string, mixed> $economicData */
    public function computeEconomicFactor(array $economicData): float
    {
        if (empty($economicData) || !($economicData['dataAvailable'] ?? false)) {
            return 50.0; // default when no data
        }

        $factorExchange = $this->normalizeExchangeRate($economicData['exchangeRateEurUsd'] ?? 1.08);
        $factorGdp = $this->normalizeGdp($economicData['gdpBillions'] ?? 0);
        $factorInflation = $this->normalizeInflation($economicData['inflationRate'] ?? 5.0);

        $composite = ($factorExchange * self::WEIGHT_EXCHANGE)
            + ($factorGdp * self::WEIGHT_GDP)
            + ($factorInflation * self::WEIGHT_INFLATION);

        return self::clamp($composite, 0.0, 100.0);
    }

    /** @param array<string, mixed> $economicData */
    public function calculateFullRisk(float $targetAmount, ?\DateTimeInterface $deadline, array $economicData): int
    {
        if ($targetAmount <= 0) {
            throw new \InvalidArgumentException('Le montant cible doit etre superieur a zero.');
        }

        $factorAmount = $this->normalizeAmount($targetAmount);
        $factorDuration = $this->normalizeDuration($deadline);
        $factorEconomic = $this->computeEconomicFactor($economicData);

        $score = ($factorAmount * self::WEIGHT_AMOUNT)
            + ($factorDuration * self::WEIGHT_DURATION)
            + ($factorEconomic * self::WEIGHT_ECONOMIC);

        return (int) round(self::clamp($score, 0.0, 100.0));
    }

    public function normalizeExchangeRate(float $eurUsdRate): float
    {
        if ($eurUsdRate <= 0) {
            return 50.0;
        }
        $deviation = (self::REFERENCE_EUR_USD - $eurUsdRate) / self::REFERENCE_EUR_USD;
        $factor = 50.0 + ($deviation * 200.0);
        return self::clamp($factor, 0.0, 100.0);
    }

    public function normalizeGdp(float $gdpBillions): float
    {
        if ($gdpBillions <= 0) {
            return 80.0;
        }
        $ratio = $gdpBillions / self::REFERENCE_GDP;

        if ($ratio >= 1.0) return self::clamp(20.0 - ($ratio - 1.0) * 10.0, 5.0, 30.0);
        if ($ratio >= 0.5) return self::clamp(40.0 - ($ratio - 0.5) * 40.0, 20.0, 50.0);
        if ($ratio >= 0.1) return self::clamp(70.0 - ($ratio - 0.1) * 75.0, 40.0, 75.0);
        return self::clamp(90.0 - $ratio * 100.0, 75.0, 95.0);
    }

    public function normalizeInflation(float $inflationRate): float
    {
        $abs = abs($inflationRate);

        if ($abs <= self::TARGET_INFLATION) return 15.0;
        if ($abs <= 4.0) return 15.0 + ($abs - self::TARGET_INFLATION) * 12.5;
        if ($abs <= 7.0) return 40.0 + ($abs - 4.0) * 10.0;
        if ($abs <= 10.0) return 70.0 + ($abs - 7.0) * 5.0;
        if ($abs <= self::CRITICAL_INFLATION) return 85.0 + ($abs - 10.0) * 2.0;
        return 95.0;
    }

    public function normalizeAmount(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }
        $ratio = $amount / self::MAX_AMOUNT;
        return self::clamp($ratio * 100.0, 0.0, 100.0);
    }

    public function normalizeDuration(?\DateTimeInterface $deadline): float
    {
        if ($deadline === null) {
            return 50.0;
        }
        $now = new \DateTimeImmutable();
        $days = (int) $now->diff($deadline)->format('%r%a');

        if ($days <= 0) return 100.0;
        if ($days <= 7) return 90.0;
        if ($days <= 30) return 70.0;
        if ($days <= 90) return 50.0;
        if ($days <= 180) return 35.0;
        if ($days <= 365) return 20.0;
        return 10.0;
    }

    public static function getRiskLevel(int $score): string
    {
        if ($score <= self::THRESHOLD_LOW) return 'Faible';
        if ($score <= self::THRESHOLD_MEDIUM) return 'Modere';
        return 'Eleve';
    }

    public static function getRiskColor(int $score): string
    {
        if ($score <= self::THRESHOLD_LOW) return '#27ae60';
        if ($score <= self::THRESHOLD_MEDIUM) return '#f39c12';
        return '#e74c3c';
    }

    public static function getRecommendation(int $score): string
    {
        if ($score <= 20) return 'Excellent climat economique. Investissement fortement recommande.';
        if ($score <= self::THRESHOLD_LOW) return 'Conditions favorables. Bon moment pour investir.';
        if ($score <= 50) return 'Risque modere. Investissement viable avec precautions.';
        if ($score <= self::THRESHOLD_MEDIUM) return 'Prudence recommandee. Diversifiez vos investissements.';
        if ($score <= 80) return 'Risque eleve. Investissement deconseille sauf profil agressif.';
        return 'Risque critique. Report de l\'investissement fortement recommande.';
    }

    /** @param array<string, mixed> $economicData */
    public function buildDeterministicVerdict(
        int $score,
        array $economicData,
        float $targetAmount,
        ?\DateTimeInterface $deadline,
    ): string {
        $country = (string) ($economicData['countryName'] ?? $economicData['country'] ?? 'ce marche');
        $inflation = (float) ($economicData['inflationRate'] ?? 0.0);
        $gdp = (float) ($economicData['gdpBillions'] ?? 0.0);
        $daysRemaining = $deadline ? (int) (new \DateTimeImmutable())->diff($deadline)->format('%r%a') : null;
        $monthsRemaining = $daysRemaining !== null ? max(0, (int) ceil($daysRemaining / 30)) : null;

        if ($inflation >= 8.0) {
            $macroContext = sprintf('Ce projet opere dans un environnement inflationniste tendu (%s, %.1f%% d\'inflation)', $country, $inflation);
        } elseif ($inflation <= 3.0 && $gdp >= 500.0) {
            $macroContext = sprintf('Ce projet beneficie d\'un cadre macroeconomique relativement stable (%s, inflation %.1f%%)', $country, $inflation);
        } else {
            $macroContext = sprintf('Ce projet evolue dans un contexte economique intermediaire (%s, inflation %.1f%%)', $country, $inflation);
        }

        if ($targetAmount >= 250000) {
            $amountContext = sprintf('avec un objectif de financement eleve de %.0f DT', $targetAmount);
        } elseif ($targetAmount >= 100000) {
            $amountContext = sprintf('avec un ticket de financement significatif de %.0f DT', $targetAmount);
        } else {
            $amountContext = sprintf('avec un besoin de financement contenu de %.0f DT', $targetAmount);
        }

        if ($monthsRemaining === null) {
            $deadlineContext = 'Sans echeance clairement definie, la discipline de suivi devra etre maintenue sur la duree.';
        } elseif ($monthsRemaining <= 1) {
            $deadlineContext = sprintf('La fenetre de levee est tres courte (%d jours restants), ce qui augmente la pression d\'execution immediate.', max(0, $daysRemaining));
        } elseif ($monthsRemaining <= 6) {
            $deadlineContext = sprintf('La fenetre de levee est de %d mois, ce qui exige un pilotage actif des jalons et de la tresorerie.', $monthsRemaining);
        } else {
            $deadlineContext = sprintf('L\'horizon de %d mois laisse davantage de marge pour absorber les ajustements d\'execution.', $monthsRemaining);
        }

        if ($score <= self::THRESHOLD_LOW) {
            $investorProfile = 'Le profil convient plutot a un investisseur prudent recherchant une exposition raisonnablement encadree.';
        } elseif ($score <= self::THRESHOLD_MEDIUM) {
            $investorProfile = 'Le dossier reste defendable pour un investisseur au profil modere, a condition de suivre les points d\'avancement de facon rigoureuse.';
        } else {
            $investorProfile = 'A ce niveau de risque, l\'investissement vise surtout un profil offensif capable de tolerer des retards, des renegociations et un suivi etroit.';
        }

        return sprintf('%s %s. %s %s', $macroContext, $amountContext, $deadlineContext, $investorProfile);
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
