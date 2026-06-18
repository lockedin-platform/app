<?php

namespace App\Service;

/**
 * Sector taxonomy — PHP twin of scripts/sector_map.py.
 *
 * Maps the platform's submission sectors into the SAME canonical buckets the
 * Model 1 scoring data was trained on, so training vocabulary == inference vocabulary.
 * Call SectorTaxonomy::canonicalize($projet->getSecteur()) before sending features
 * to the ML service.
 *
 * Keep this in sync with scripts/sector_map.py.
 */
final class SectorTaxonomy
{
    public const CANONICAL = [
        'TECH', 'FINTECH', 'HEALTH', 'COMMERCE', 'AGRI_FOOD',
        'ENERGY_CLIMATE', 'EDUCATION', 'OTHER',
    ];

    /** lower-cased raw sector => canonical bucket */
    private const MAP = [
        // Kaggle training sectors
        'saas' => 'TECH', 'ai' => 'TECH', 'crypto' => 'FINTECH', 'fintech' => 'FINTECH',
        'health' => 'HEALTH', 'ecommerce' => 'COMMERCE', 'climate' => 'ENERGY_CLIMATE',
        // platform form sectors (English)
        'technology' => 'TECH', 'finance' => 'FINTECH', 'education' => 'EDUCATION',
        'commerce' => 'COMMERCE', 'agriculture' => 'AGRI_FOOD', 'food & beverage' => 'AGRI_FOOD',
        'energy' => 'ENERGY_CLIMATE', 'tourism' => 'OTHER', 'real estate' => 'OTHER',
        'transport' => 'OTHER', 'fashion & textile' => 'OTHER', 'industry' => 'OTHER',
        'services' => 'OTHER', 'crafts' => 'OTHER',
        // platform form sectors (French — ProjetController::SECTEURS)
        'technologie' => 'TECH', 'sante' => 'HEALTH', 'santé' => 'HEALTH',
        'éducation' => 'EDUCATION', 'tourisme' => 'OTHER', 'immobilier' => 'OTHER',
        'energie' => 'ENERGY_CLIMATE', 'énergie' => 'ENERGY_CLIMATE', 'alimentation' => 'AGRI_FOOD',
        'mode & textile' => 'OTHER', 'industrie' => 'OTHER', 'artisanat' => 'OTHER',
    ];

    /**
     * Data-backed historical success rate per bucket (from build_sector_priors.py:
     * Kaggle + Crunchbase, smoothed). Keep in sync with data/processed/sector_priors.json.
     */
    private const PRIORS = [
        'TECH' => 0.467, 'FINTECH' => 0.445, 'HEALTH' => 0.477, 'COMMERCE' => 0.446,
        'AGRI_FOOD' => 0.436, 'ENERGY_CLIMATE' => 0.447, 'EDUCATION' => 0.495, 'OTHER' => 0.396,
    ];
    private const GLOBAL_PRIOR = 0.454;

    public static function canonicalize(?string $sector): string
    {
        if ($sector === null || trim($sector) === '') {
            return 'OTHER';
        }
        return self::MAP[mb_strtolower(trim($sector))] ?? 'OTHER';
    }

    /** Success prior for a raw platform sector — feed as the `sector_success_prior` model feature. */
    public static function successPrior(?string $sector): float
    {
        return self::PRIORS[self::canonicalize($sector)] ?? self::GLOBAL_PRIOR;
    }
}
