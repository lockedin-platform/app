<?php

namespace App\Service;

use App\Entity\Projet;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class ProjetBusinessPlanService
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly Environment $twig,
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(Projet $projet): array
    {
        $db = $projet->getDonneesBusiness();

        if ($this->gemini->isConfigured()) {
            return $this->generateWithAi($projet);
        }

        return $this->generateLocal($projet);
    }

    /** @param array<string, mixed> $businessPlan */
    public function generatePdf(Projet $projet, array $businessPlan): string
    {
        $html = $this->twig->render('front/projet/business_plan_pdf.html.twig', [
            'projet' => $projet,
            'plan' => $businessPlan,
        ]);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @return array<string, mixed> */
    private function generateWithAi(Projet $projet): array
    {
        $db = $projet->getDonneesBusiness();
        $data = json_encode([
            'titre' => $projet->getTitre(),
            'description' => $projet->getDescription(),
            'secteur' => $projet->getSecteur(),
            'etape' => $projet->getEtape(),
            'taille_marche' => $db?->getTailleMarche(),
            'modele_revenu' => $db?->getModeleRevenu(),
            'couts_estimes' => $db?->getCoutsEstimes(),
            'revenus_attendus' => $db?->getRevenusAttendus(),
            'niveau_risque' => $db?->getNiveauRisque(),
            'force_equipe' => $db?->getForceEquipe(),
            'score_global' => $projet->getScoreGlobal(),
        ], JSON_UNESCAPED_UNICODE);

        $prompt = "Tu es un consultant expert en entrepreneuriat en Tunisie. Génère un business plan structuré en français pour ce projet.

Données : {$data}

Réponds UNIQUEMENT en JSON valide avec cette structure exacte :
{
  \"resume_executif\": \"Résumé du projet en 3-4 phrases\",
  \"probleme_solution\": {
    \"probleme\": \"Le problème adressé\",
    \"solution\": \"La solution proposée\",
    \"proposition_valeur\": \"Ce qui rend unique\"
  },
  \"analyse_marche\": {
    \"taille\": \"Taille et potentiel du marché\",
    \"cible\": \"Clientèle cible\",
    \"tendances\": \"Tendances du secteur\",
    \"concurrence\": \"Analyse concurrentielle\"
  },
  \"modele_economique\": {
    \"sources_revenus\": \"Comment le projet génère des revenus\",
    \"structure_couts\": \"Principales postes de coûts\",
    \"prix\": \"Stratégie de pricing\",
    \"rentabilite\": \"Projection de rentabilité\"
  },
  \"strategie_marketing\": {
    \"positionnement\": \"Positionnement sur le marché\",
    \"canaux\": \"Canaux de distribution et communication\",
    \"actions\": [\"Action 1\", \"Action 2\", \"Action 3\"]
  },
  \"plan_operationnel\": {
    \"etapes_cles\": [\"Étape 1\", \"Étape 2\", \"Étape 3\", \"Étape 4\"],
    \"ressources\": \"Ressources nécessaires\",
    \"timeline\": \"Planning sur 12 mois\"
  },
  \"analyse_risques\": {
    \"risques\": [{\"risque\": \"Risque 1\", \"mitigation\": \"Comment le mitiger\"}],
    \"plan_b\": \"Plan de contingence\"
  },
  \"projections_financieres\": {
    \"annee_1\": {\"revenus\": 0, \"couts\": 0, \"benefice\": 0},
    \"annee_2\": {\"revenus\": 0, \"couts\": 0, \"benefice\": 0},
    \"annee_3\": {\"revenus\": 0, \"couts\": 0, \"benefice\": 0},
    \"point_equilibre\": \"Quand le projet atteint l'équilibre\"
  },
  \"conclusion\": \"Conclusion et prochaines étapes\"
}

Sois réaliste et adapté au contexte tunisien (DT, réglementations, marché local).";

        $result = $this->gemini->generateJson($prompt, 0.4);

        return $result ?? $this->generateLocal($projet);
    }

    /** @return array<string, mixed> */
    private function generateLocal(Projet $projet): array
    {
        $db = $projet->getDonneesBusiness();
        $couts = $db?->getCoutsEstimes() ?? 0;
        $revenus = $db?->getRevenusAttendus() ?? 0;
        $marge = $revenus - $couts;

        return [
            'resume_executif' => sprintf(
                '%s est un projet dans le secteur %s, actuellement en phase de %s. Avec un marché estimé à %s DT et des revenus attendus de %s DT, ce projet vise à se positionner comme un acteur clé de son secteur.',
                $projet->getTitre(), $projet->getSecteur(), $projet->getEtape(),
                number_format((float) ($db?->getTailleMarche() ?? 0), 0, ',', ' '),
                number_format($revenus, 0, ',', ' ')
            ),
            'probleme_solution' => [
                'probleme' => 'À définir selon l\'étude de marché approfondie',
                'solution' => $projet->getDescription(),
                'proposition_valeur' => 'À compléter avec votre avantage concurrentiel',
            ],
            'analyse_marche' => [
                'taille' => number_format((float) ($db?->getTailleMarche() ?? 0), 0, ',', ' ') . ' DT',
                'cible' => 'À définir selon le secteur ' . $projet->getSecteur(),
                'tendances' => 'Croissance du secteur ' . $projet->getSecteur() . ' en Tunisie',
                'concurrence' => 'Analyse concurrentielle à compléter',
            ],
            'modele_economique' => [
                'sources_revenus' => $db?->getModeleRevenu() ?? 'Non défini',
                'structure_couts' => number_format($couts, 0, ',', ' ') . ' DT estimés',
                'prix' => 'Stratégie de prix à définir',
                'rentabilite' => $marge > 0
                    ? 'Marge positive de ' . number_format($marge, 0, ',', ' ') . ' DT'
                    : 'Marge négative - révision nécessaire',
            ],
            'strategie_marketing' => [
                'positionnement' => 'À définir',
                'canaux' => 'Digital, réseaux sociaux, partenariats locaux',
                'actions' => [
                    'Lancer une présence en ligne',
                    'Développer des partenariats stratégiques',
                    'Participer aux événements du secteur',
                ],
            ],
            'plan_operationnel' => [
                'etapes_cles' => [
                    'Validation du concept (Mois 1-2)',
                    'Développement MVP (Mois 3-5)',
                    'Lancement beta (Mois 6-8)',
                    'Croissance (Mois 9-12)',
                ],
                'ressources' => 'Équipe de ' . ($db?->getForceEquipe() ?? 'N/A') . '/10',
                'timeline' => 'Plan sur 12 mois',
            ],
            'analyse_risques' => [
                'risques' => [
                    ['risque' => 'Risque de marché - adoption lente', 'mitigation' => 'Tests utilisateurs précoces'],
                    ['risque' => 'Risque financier - dépassement de budget', 'mitigation' => 'Suivi budgétaire mensuel'],
                ],
                'plan_b' => 'Pivot vers un segment de marché adjacent si nécessaire',
            ],
            'projections_financieres' => [
                'annee_1' => ['revenus' => $revenus, 'couts' => $couts, 'benefice' => $marge],
                'annee_2' => ['revenus' => $revenus * 1.5, 'couts' => $couts * 1.2, 'benefice' => ($revenus * 1.5) - ($couts * 1.2)],
                'annee_3' => ['revenus' => $revenus * 2.2, 'couts' => $couts * 1.4, 'benefice' => ($revenus * 2.2) - ($couts * 1.4)],
                'point_equilibre' => $marge > 0 ? 'Dès la première année' : 'Estimé en année 2',
            ],
            'conclusion' => 'Ce business plan nécessite une analyse IA approfondie. Configurez votre clé API Gemini pour un plan personnalisé et détaillé.',
        ];
    }
}
