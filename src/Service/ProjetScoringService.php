<?php

namespace App\Service;

use App\Entity\DonneesBusiness;
use App\Entity\Projet;

class ProjetScoringService
{
    public function __construct(private readonly GeminiService $gemini)
    {
    }

    public function calculateScores(Projet $projet): void
    {
        $db = $projet->getDonneesBusiness();
        if (!$db) {
            return;
        }

        $db->calculerIndicateurs();

        $scoreFinancier = $this->computeFinancialScore($db);
        $scoreMarche = $this->computeMarketScore($db);
        $scoreEquipe = $this->computeTeamScore($db);
        $scoreRisque = $this->computeRiskScore($db);

        $db->setScoreFinancier(round($scoreFinancier, 1));
        $db->setScoreMarche(round($scoreMarche, 1));
        $db->setScoreEquipeCalcule(round($scoreEquipe, 1));
        $db->setScoreRisqueCalcule(round($scoreRisque, 1));

        $scoreGlobal = ($scoreFinancier * 0.30) + ($scoreMarche * 0.25) + ($scoreEquipe * 0.20) + ($scoreRisque * 0.25);
        $projet->setScoreGlobal(round($scoreGlobal, 1));
    }

    public function generateDiagnostic(Projet $projet): ?string
    {
        if (!$this->gemini->isConfigured()) {
            return $this->generateLocalDiagnostic($projet);
        }

        $db = $projet->getDonneesBusiness();
        $prompt = $this->buildDiagnosticPrompt($projet, $db);
        $result = $this->gemini->generate($prompt, 0.4);

        if ($result === null) {
            return $this->generateLocalDiagnostic($projet);
        }

        $projet->setDiagnosticIa($result);
        return $result;
    }

    /** @return array<string, mixed> */
    public function evaluateWithAi(Projet $projet): array
    {
        $this->calculateScores($projet);
        $diagnostic = $this->generateDiagnostic($projet);

        $projet->setStatutProjet(Projet::STATUT_EVALUE);
        $projet->setDateEvaluation(new \DateTime());

        return [
            'scoreGlobal' => $projet->getScoreGlobal(),
            'diagnostic' => $diagnostic,
            'scores' => [
                'financier' => $projet->getDonneesBusiness()?->getScoreFinancier(),
                'marche' => $projet->getDonneesBusiness()?->getScoreMarche(),
                'equipe' => $projet->getDonneesBusiness()?->getScoreEquipeCalcule(),
                'risque' => $projet->getDonneesBusiness()?->getScoreRisqueCalcule(),
            ],
        ];
    }

    private function computeFinancialScore(DonneesBusiness $db): float
    {
        $marge = $db->getMargeEstimee();
        $ratio = $db->getRatioRentabilite();
        $couts = $db->getCoutsEstimes();
        $revenus = $db->getRevenusAttendus();

        $score = 50.0;

        if ($revenus > 0 && $couts > 0) {
            $roiPercent = ($marge / $couts) * 100;
            if ($roiPercent > 100) $score += 25;
            elseif ($roiPercent > 50) $score += 20;
            elseif ($roiPercent > 20) $score += 10;
            elseif ($roiPercent > 0) $score += 5;
            else $score -= 15;
        }

        if ($ratio > 1.5) $score += 15;
        elseif ($ratio > 1.0) $score += 10;
        elseif ($ratio > 0.5) $score += 5;

        if ($marge > 100000) $score += 10;
        elseif ($marge > 50000) $score += 5;

        return max(0, min(100, $score));
    }

    private function computeMarketScore(DonneesBusiness $db): float
    {
        $taille = (float) $db->getTailleMarche();
        $score = 50.0;

        if ($taille > 10000000) $score += 30;
        elseif ($taille > 5000000) $score += 25;
        elseif ($taille > 1000000) $score += 20;
        elseif ($taille > 500000) $score += 15;
        elseif ($taille > 100000) $score += 10;
        else $score += 5;

        $modele = strtolower($db->getModeleRevenu() ?? '');
        if (str_contains($modele, 'abonnement') || str_contains($modele, 'subscription') || str_contains($modele, 'saas') || str_contains($modele, 'récurrent') || str_contains($modele, 'recurring') || str_contains($modele, 'freemium')) {
            $score += 15;
        } elseif (str_contains($modele, 'marketplace') || str_contains($modele, 'commission') || str_contains($modele, 'b2b') || str_contains($modele, 'b2c')) {
            $score += 10;
        } else {
            $score += 5;
        }

        return max(0, min(100, $score));
    }

    private function computeTeamScore(DonneesBusiness $db): float
    {
        $score = ($db->getForceEquipe() ?? 5) * 10.0;

        // Founding team experience (Model 1 input) — strong predictor of success
        $exp = $db->getExperienceEquipe();
        if ($exp !== null) {
            if ($exp >= 10) $score += 15;
            elseif ($exp >= 5) $score += 10;
            elseif ($exp >= 2) $score += 5;
        }

        // Team size — a real team scores higher than a solo founder
        $size = $db->getTailleEquipe();
        if ($size !== null) {
            if ($size >= 5) $score += 8;
            elseif ($size >= 2) $score += 4;
        }

        return max(0, min(100, $score));
    }

    private function computeRiskScore(DonneesBusiness $db): float
    {
        $risque = strtolower($db->getNiveauRisque() ?? '');

        return match (true) {
            str_contains($risque, 'low') || str_contains($risque, 'faible') => 85,
            str_contains($risque, 'moderate') || str_contains($risque, 'modéré') || str_contains($risque, 'modere') => 65,
            str_contains($risque, 'very high') || str_contains($risque, 'très') || str_contains($risque, 'tres') => 20,
            str_contains($risque, 'high') || str_contains($risque, 'élevé') || str_contains($risque, 'eleve') => 40,
            default => 50,
        };
    }

    private function buildDiagnosticPrompt(Projet $projet, ?DonneesBusiness $db): string
    {
        $data = [
            'titre' => $projet->getTitre(),
            'description' => $projet->getDescription(),
            'secteur' => $projet->getSecteur(),
            'etape' => $projet->getEtape(),
            'score_global' => $projet->getScoreGlobal(),
        ];

        if ($db) {
            $data += [
                'taille_marche' => $db->getTailleMarche(),
                'modele_revenu' => $db->getModeleRevenu(),
                'couts_estimes' => $db->getCoutsEstimes(),
                'revenus_attendus' => $db->getRevenusAttendus(),
                'marge_estimee' => $db->getMargeEstimee(),
                'niveau_risque' => $db->getNiveauRisque(),
                'force_equipe' => $db->getForceEquipe(),
                'score_financier' => $db->getScoreFinancier(),
                'score_marche' => $db->getScoreMarche(),
                'score_equipe' => $db->getScoreEquipeCalcule(),
                'score_risque' => $db->getScoreRisqueCalcule(),
            ];
        }

        return "You are an expert evaluator of entrepreneurial projects in Tunisia. Analyze this project and provide a complete diagnostic in English.

Project data:
" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "

Provide a structured diagnostic covering:
1. **SWOT Analysis** (Strengths, Weaknesses, Opportunities, Threats) - 2-3 points per category
2. **Critical watch points**
3. **Concrete recommendations** to improve the project (3-5 priority actions)
4. **Viability assessment**: commentary on the global score of {$projet->getScoreGlobal()}/100

Be direct, concrete, and relevant to the Tunisian startup context. Limit your response to 500 words. Reply entirely in English.";
    }

    private function generateLocalDiagnostic(Projet $projet): string
    {
        $db = $projet->getDonneesBusiness();
        $score = $projet->getScoreGlobal();
        $parts = [];

        if ($score >= 75) {
            $parts[] = "✅ **Promising project** (Score: {$score}/100). This project shows solid indicators across the board.";
        } elseif ($score >= 50) {
            $parts[] = "⚠️ **Project with potential** (Score: {$score}/100). Some improvements are needed before seeking investment.";
        } else {
            $parts[] = "❌ **At-risk project** (Score: {$score}/100). A deep revision is recommended before moving forward.";
        }

        if ($db) {
            if ($db->getMargeEstimee() < 0) {
                $parts[] = "📉 The estimated margin is negative. Review the cost structure or pricing strategy.";
            }
            if ($db->getForceEquipe() < 5) {
                $parts[] = "👥 Team strength is low ({$db->getForceEquipe()}/10). Consider strengthening the founding team.";
            }
            $risque = strtolower($db->getNiveauRisque() ?? '');
            if (str_contains($risque, 'high') || str_contains($risque, 'very')) {
                $parts[] = "⚡ High risk detected. Prepare a solid risk mitigation plan before approaching investors.";
            }
        }

        $parts[] = "💡 **Next steps**: Configure your Gemini API key to get a full AI-powered diagnostic with SWOT analysis and personalized recommendations.";

        $diagnostic = implode("\n\n", $parts);
        $projet->setDiagnosticIa($diagnostic);
        return $diagnostic;
    }
}
