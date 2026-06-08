<?php

namespace App\Service;

use App\Entity\Projet;
use App\Entity\User;
use App\Repository\ProjetRepository;

class ProjetRecommendationService
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly ProjetRepository $projetRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function getRecommendations(Projet $projet): array
    {
        if ($this->gemini->isConfigured()) {
            return $this->getAiRecommendations($projet);
        }

        return $this->getLocalRecommendations($projet);
    }

    /** @return Projet[] */
    public function getSimilarProjects(Projet $projet, int $limit = 5): array
    {
        $qb = $this->projetRepository->createQueryBuilder('p')
            ->where('p.secteur = :secteur')
            ->andWhere('p.id != :id')
            ->andWhere('p.statutProjet = :statut')
            ->setParameter('secteur', $projet->getSecteur())
            ->setParameter('id', $projet->getId())
            ->setParameter('statut', Projet::STATUT_EVALUE)
            ->orderBy('p.scoreGlobal', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /** @return array<string, mixed> */
    private function getAiRecommendations(Projet $projet): array
    {
        $db = $projet->getDonneesBusiness();
        $data = json_encode([
            'titre' => $projet->getTitre(),
            'description' => $projet->getDescription(),
            'secteur' => $projet->getSecteur(),
            'etape' => $projet->getEtape(),
            'score_global' => $projet->getScoreGlobal(),
            'taille_marche' => $db?->getTailleMarche(),
            'modele_revenu' => $db?->getModeleRevenu(),
            'niveau_risque' => $db?->getNiveauRisque(),
            'force_equipe' => $db?->getForceEquipe(),
        ], JSON_UNESCAPED_UNICODE);

        $prompt = "You are an expert startup advisor specializing in the Tunisian entrepreneurial ecosystem. Analyze this project and provide strategic recommendations entirely in English.

Data: {$data}

Reply ONLY with valid JSON using this exact structure:
{
  \"swot\": {
    \"strengths\": [\"Strength 1\", \"Strength 2\", \"Strength 3\"],
    \"weaknesses\": [\"Weakness 1\", \"Weakness 2\", \"Weakness 3\"],
    \"opportunities\": [\"Opportunity 1\", \"Opportunity 2\", \"Opportunity 3\"],
    \"threats\": [\"Threat 1\", \"Threat 2\", \"Threat 3\"]
  },
  \"mentor_profile\": {
    \"expertise_requise\": \"Ideal mentor expertise type\",
    \"experience_secteur\": \"Desired sector experience\",
    \"competences\": [\"Skill 1\", \"Skill 2\", \"Skill 3\"]
  },
  \"investor_profile\": {
    \"type_investisseur\": \"Suitable investor type\",
    \"montant_recherche\": \"Funding range needed\",
    \"criteres\": [\"Criterion 1\", \"Criterion 2\"]
  },
  \"actions_prioritaires\": [
    {\"action\": \"Action to take\", \"priorite\": \"haute|moyenne|basse\", \"delai\": \"Short/Medium/Long term\"},
    {\"action\": \"Action 2\", \"priorite\": \"haute\", \"delai\": \"Short term\"},
    {\"action\": \"Action 3\", \"priorite\": \"moyenne\", \"delai\": \"Medium term\"}
  ],
  \"ressources_suggerees\": [
    \"Resource or program in Tunisia 1\",
    \"Resource 2\",
    \"Resource 3\"
  ],
  \"kpi_a_suivre\": [
    {\"kpi\": \"Indicator name\", \"objectif\": \"Target value\", \"frequence\": \"Monthly/Quarterly\"}
  ],
  \"score_readiness\": {
    \"investissement\": 70,
    \"marche\": 60,
    \"equipe\": 50,
    \"commentaire\": \"Commentary on the project's maturity and investment readiness\"
  }
}

Be concrete, realistic, and relevant to the Tunisian startup context. All text values must be in English.";

        $result = $this->gemini->generateJson($prompt, 0.4);

        return $result ?? $this->getLocalRecommendations($projet);
    }

    /** @return array<string, mixed> */
    private function getLocalRecommendations(Projet $projet): array
    {
        $db = $projet->getDonneesBusiness();
        $score = $projet->getScoreGlobal();

        $actions = [];
        if ($score < 50) {
            $actions[] = ['action' => 'Revise the business model', 'priorite' => 'haute', 'delai' => 'Short term'];
            $actions[] = ['action' => 'Strengthen the founding team', 'priorite' => 'haute', 'delai' => 'Short term'];
        }
        if ($db && $db->getMargeEstimee() < 0) {
            $actions[] = ['action' => 'Optimize the cost structure', 'priorite' => 'haute', 'delai' => 'Short term'];
        }
        if ($db && $db->getForceEquipe() < 6) {
            $actions[] = ['action' => 'Recruit key competencies', 'priorite' => 'moyenne', 'delai' => 'Medium term'];
        }
        $actions[] = ['action' => 'Apply to an acceleration program', 'priorite' => 'moyenne', 'delai' => 'Medium term'];
        $actions[] = ['action' => 'Build a testable MVP', 'priorite' => 'haute', 'delai' => 'Short term'];

        $sector = $projet->getSecteur() ?? 'entrepreneurship';

        return [
            'swot' => [
                'strengths' => ['Clear value proposition in the ' . $sector . ' sector', 'Motivated founding team', 'Identified target market'],
                'weaknesses' => ['Limited financial resources', 'Brand visibility to build', 'Competitive pressure'],
                'opportunities' => ['Growing Tunisian startup ecosystem', 'Access to regional markets', 'Digital transformation acceleration'],
                'threats' => ['Economic uncertainty', 'Established competitors', 'Regulatory changes'],
            ],
            'mentor_profile' => [
                'expertise_requise' => 'Expert in ' . $sector,
                'experience_secteur' => 'Minimum 5 years in the sector',
                'competences' => ['Business strategy', 'Fundraising', 'Product development'],
            ],
            'investor_profile' => [
                'type_investisseur' => $score > 60 ? 'Business Angel or Seed VC' : 'Pre-seed / Bootstrapping',
                'montant_recherche' => number_format($db?->getCoutsEstimes() ?? 50000, 0, ',', ' ') . ' TND',
                'criteres' => [$sector . ' sector', $projet->getEtape() . ' stage'],
            ],
            'actions_prioritaires' => $actions,
            'ressources_suggerees' => [
                'Startup Tunisia - National support program',
                'BIAT Foundation - Startup financing',
                'Flat6Labs Tunis - Startup accelerator',
                'Wiki Start Up - Incubator',
            ],
            'kpi_a_suivre' => [
                ['kpi' => 'Monthly revenue', 'objectif' => number_format(($db?->getRevenusAttendus() ?? 0) / 12, 0, ',', ' ') . ' TND/month', 'frequence' => 'Monthly'],
                ['kpi' => 'Conversion rate', 'objectif' => '> 3%', 'frequence' => 'Weekly'],
                ['kpi' => 'Burn rate', 'objectif' => '< ' . number_format(($db?->getCoutsEstimes() ?? 0) / 12, 0, ',', ' ') . ' TND/month', 'frequence' => 'Monthly'],
            ],
            'score_readiness' => [
                'investissement' => min(100, max(0, $score - 10)),
                'marche' => $db?->getScoreMarche() ?? 50,
                'equipe' => ($db?->getForceEquipe() ?? 5) * 10,
                'commentaire' => $score >= 60
                    ? 'The project shows good maturity. Ready for the next step.'
                    : 'The project still needs work before approaching investors.',
            ],
        ];
    }
}
