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
        if (str_contains($modele, 'abonnement') || str_contains($modele, 'saas') || str_contains($modele, 'récurrent')) {
            $score += 15;
        } elseif (str_contains($modele, 'marketplace') || str_contains($modele, 'commission')) {
            $score += 10;
        } else {
            $score += 5;
        }

        return max(0, min(100, $score));
    }

    private function computeTeamScore(DonneesBusiness $db): float
    {
        return max(0, min(100, ($db->getForceEquipe() ?? 5) * 10));
    }

    private function computeRiskScore(DonneesBusiness $db): float
    {
        $risque = strtolower($db->getNiveauRisque() ?? '');

        return match (true) {
            str_contains($risque, 'faible') => 85,
            str_contains($risque, 'modéré'), str_contains($risque, 'modere') => 65,
            str_contains($risque, 'élevé'), str_contains($risque, 'eleve') => 40,
            str_contains($risque, 'très'), str_contains($risque, 'tres') => 20,
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

        return "Tu es un expert en évaluation de projets entrepreneuriaux en Tunisie. Analyse ce projet et fournis un diagnostic complet en français.

Données du projet :
" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "

Fournis un diagnostic structuré avec :
1. **Analyse SWOT** (Forces, Faiblesses, Opportunités, Menaces) - 2-3 points par catégorie
2. **Points critiques** à surveiller
3. **Recommandations concrètes** pour améliorer le projet (3-5 actions prioritaires)
4. **Score de viabilité** : commentaire sur le score global de {$projet->getScoreGlobal()}/100

Sois direct, concret et adapté au contexte tunisien. Limite ta réponse à 500 mots.";
    }

    private function generateLocalDiagnostic(Projet $projet): string
    {
        $db = $projet->getDonneesBusiness();
        $score = $projet->getScoreGlobal();
        $parts = [];

        if ($score >= 75) {
            $parts[] = "✅ **Projet prometteur** (Score : {$score}/100). Ce projet présente de solides indicateurs.";
        } elseif ($score >= 50) {
            $parts[] = "⚠️ **Projet à potentiel** (Score : {$score}/100). Des améliorations sont nécessaires.";
        } else {
            $parts[] = "❌ **Projet à risque** (Score : {$score}/100). Une révision profonde est recommandée.";
        }

        if ($db) {
            if ($db->getMargeEstimee() < 0) {
                $parts[] = "📉 La marge estimée est négative. Revoir la structure de coûts ou le pricing.";
            }
            if ($db->getForceEquipe() < 5) {
                $parts[] = "👥 La force de l'équipe est faible ({$db->getForceEquipe()}/10). Envisagez de renforcer l'équipe.";
            }
            $risque = strtolower($db->getNiveauRisque() ?? '');
            if (str_contains($risque, 'élevé') || str_contains($risque, 'très')) {
                $parts[] = "⚡ Risque élevé détecté. Préparez un plan de mitigation des risques.";
            }
        }

        $parts[] = "💡 **Prochaines étapes** : Soumettez votre projet pour une évaluation IA complète avec une clé API Groq configurée.";

        $diagnostic = implode("\n\n", $parts);
        $projet->setDiagnosticIa($diagnostic);
        return $diagnostic;
    }
}
