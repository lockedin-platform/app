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

        $prompt = "You are an expert business consultant specializing in Tunisian entrepreneurship. Generate a structured business plan in English for this project.

Data: {$data}

Reply ONLY with valid JSON using this exact structure:
{
  \"resume_executif\": \"Project summary in 3-4 sentences\",
  \"probleme_solution\": {
    \"probleme\": \"The problem being addressed\",
    \"solution\": \"The proposed solution\",
    \"proposition_valeur\": \"What makes this unique\"
  },
  \"analyse_marche\": {
    \"taille\": \"Market size and potential\",
    \"cible\": \"Target customers\",
    \"tendances\": \"Sector trends\",
    \"concurrence\": \"Competitive analysis\"
  },
  \"modele_economique\": {
    \"sources_revenus\": \"How the project generates revenue\",
    \"structure_couts\": \"Main cost categories\",
    \"prix\": \"Pricing strategy\",
    \"rentabilite\": \"Profitability projection\"
  },
  \"strategie_marketing\": {
    \"positionnement\": \"Market positioning\",
    \"canaux\": \"Distribution and communication channels\",
    \"actions\": [\"Action 1\", \"Action 2\", \"Action 3\"]
  },
  \"plan_operationnel\": {
    \"etapes_cles\": [\"Milestone 1\", \"Milestone 2\", \"Milestone 3\", \"Milestone 4\"],
    \"ressources\": \"Required resources\",
    \"timeline\": \"12-month roadmap\"
  },
  \"analyse_risques\": {
    \"risques\": [{\"risque\": \"Risk 1\", \"mitigation\": \"How to mitigate it\"}],
    \"plan_b\": \"Contingency plan\"
  },
  \"projections_financieres\": {
    \"annee_1\": {\"revenus\": 0, \"couts\": 0, \"benefice\": 0},
    \"annee_2\": {\"revenus\": 0, \"couts\": 0, \"benefice\": 0},
    \"annee_3\": {\"revenus\": 0, \"couts\": 0, \"benefice\": 0},
    \"point_equilibre\": \"When the project reaches break-even\"
  },
  \"conclusion\": \"Conclusion and next steps\"
}

Be realistic and relevant to the Tunisian context (TND currency, local market, regulations). All text values must be in English.";

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

        $sector = $projet->getSecteur() ?? 'the target sector';

        return [
            'resume_executif' => sprintf(
                '%s is a project in the %s sector, currently in the %s phase. With an estimated market size of %s TND and expected revenues of %s TND, this project aims to position itself as a key player in its sector.',
                $projet->getTitre(), $sector, $projet->getEtape(),
                number_format((float) ($db?->getTailleMarche() ?? 0), 0, ',', ' '),
                number_format($revenus, 0, ',', ' ')
            ),
            'probleme_solution' => [
                'probleme' => 'To be defined based on a thorough market study',
                'solution' => $projet->getDescription(),
                'proposition_valeur' => 'To be completed with your competitive advantage',
            ],
            'analyse_marche' => [
                'taille' => ($db?->getTailleMarche() ?? 'N/A') . ' TND',
                'cible' => 'To be defined for the ' . $sector . ' sector',
                'tendances' => 'Growth of the ' . $sector . ' sector in Tunisia',
                'concurrence' => 'Competitive analysis to be completed',
            ],
            'modele_economique' => [
                'sources_revenus' => $db?->getModeleRevenu() ?? 'Not defined',
                'structure_couts' => number_format($couts, 0, ',', ' ') . ' TND estimated',
                'prix' => 'Pricing strategy to be defined',
                'rentabilite' => $marge > 0
                    ? 'Positive margin of ' . number_format($marge, 0, ',', ' ') . ' TND'
                    : 'Negative margin — revision required',
            ],
            'strategie_marketing' => [
                'positionnement' => 'To be defined',
                'canaux' => 'Digital, social media, local partnerships',
                'actions' => [
                    'Build an online presence',
                    'Develop strategic partnerships',
                    'Participate in sector events',
                ],
            ],
            'plan_operationnel' => [
                'etapes_cles' => [
                    'Concept validation (Months 1-2)',
                    'MVP development (Months 3-5)',
                    'Beta launch (Months 6-8)',
                    'Growth phase (Months 9-12)',
                ],
                'ressources' => 'Team strength: ' . ($db?->getForceEquipe() ?? 'N/A') . '/10',
                'timeline' => '12-month roadmap',
            ],
            'analyse_risques' => [
                'risques' => [
                    ['risque' => 'Market risk — slow adoption', 'mitigation' => 'Early user testing and validation'],
                    ['risque' => 'Financial risk — budget overrun', 'mitigation' => 'Monthly budget tracking'],
                ],
                'plan_b' => 'Pivot toward an adjacent market segment if necessary',
            ],
            'projections_financieres' => [
                'annee_1' => ['revenus' => $revenus, 'couts' => $couts, 'benefice' => $marge],
                'annee_2' => ['revenus' => $revenus * 1.5, 'couts' => $couts * 1.2, 'benefice' => ($revenus * 1.5) - ($couts * 1.2)],
                'annee_3' => ['revenus' => $revenus * 2.2, 'couts' => $couts * 1.4, 'benefice' => ($revenus * 2.2) - ($couts * 1.4)],
                'point_equilibre' => $marge > 0 ? 'From year one' : 'Estimated in year 2',
            ],
            'conclusion' => 'This business plan requires a full AI-powered analysis. Configure your Gemini API key to get a personalized and detailed plan tailored to your project.',
        ];
    }
}
