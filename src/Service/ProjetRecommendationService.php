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

        $prompt = "Tu es un conseiller expert en entrepreneuriat tunisien. Analyse ce projet et donne des recommandations.

Données : {$data}

Réponds UNIQUEMENT en JSON valide :
{
  \"mentor_profile\": {
    \"expertise_requise\": \"Type d'expertise idéale du mentor\",
    \"experience_secteur\": \"Expérience sectorielle souhaitée\",
    \"competences\": [\"Compétence 1\", \"Compétence 2\", \"Compétence 3\"]
  },
  \"investor_profile\": {
    \"type_investisseur\": \"Type d'investisseur adapté\",
    \"montant_recherche\": \"Fourchette de montant\",
    \"criteres\": [\"Critère 1\", \"Critère 2\"]
  },
  \"actions_prioritaires\": [
    {\"action\": \"Action à faire\", \"priorite\": \"haute|moyenne|basse\", \"delai\": \"Court/Moyen/Long terme\"},
    {\"action\": \"Action 2\", \"priorite\": \"haute\", \"delai\": \"Court terme\"},
    {\"action\": \"Action 3\", \"priorite\": \"moyenne\", \"delai\": \"Moyen terme\"}
  ],
  \"ressources_suggerees\": [
    \"Ressource ou programme en Tunisie 1\",
    \"Ressource 2\",
    \"Ressource 3\"
  ],
  \"kpi_a_suivre\": [
    {\"kpi\": \"Indicateur\", \"objectif\": \"Valeur cible\", \"frequence\": \"Mensuel/Trimestriel\"}
  ],
  \"score_readiness\": {
    \"investissement\": 70,
    \"marche\": 60,
    \"equipe\": 50,
    \"commentaire\": \"Commentaire sur la maturité du projet\"
  }
}

Sois concret et adapté au contexte tunisien.";

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
            $actions[] = ['action' => 'Revoir le modèle économique', 'priorite' => 'haute', 'delai' => 'Court terme'];
            $actions[] = ['action' => 'Renforcer l\'équipe fondatrice', 'priorite' => 'haute', 'delai' => 'Court terme'];
        }
        if ($db && $db->getMargeEstimee() < 0) {
            $actions[] = ['action' => 'Optimiser la structure de coûts', 'priorite' => 'haute', 'delai' => 'Court terme'];
        }
        if ($db && $db->getForceEquipe() < 6) {
            $actions[] = ['action' => 'Recruter des compétences clés', 'priorite' => 'moyenne', 'delai' => 'Moyen terme'];
        }
        $actions[] = ['action' => 'Participer à un programme d\'accélération', 'priorite' => 'moyenne', 'delai' => 'Moyen terme'];
        $actions[] = ['action' => 'Développer un MVP testable', 'priorite' => 'haute', 'delai' => 'Court terme'];

        return [
            'mentor_profile' => [
                'expertise_requise' => 'Expert en ' . ($projet->getSecteur() ?? 'entrepreneuriat'),
                'experience_secteur' => 'Minimum 5 ans dans le secteur',
                'competences' => ['Stratégie business', 'Levée de fonds', 'Développement produit'],
            ],
            'investor_profile' => [
                'type_investisseur' => $score > 60 ? 'Business Angel ou VC Seed' : 'Pré-seed / Love money',
                'montant_recherche' => number_format($db?->getCoutsEstimes() ?? 50000, 0, ',', ' ') . ' DT',
                'criteres' => ['Secteur ' . $projet->getSecteur(), 'Stade ' . $projet->getEtape()],
            ],
            'actions_prioritaires' => $actions,
            'ressources_suggerees' => [
                'Startup Tunisia - Programme national d\'accompagnement',
                'BIAT Foundation - Financement de startups',
                'Flat6Labs Tunis - Accélérateur de startups',
                'Wiki Start Up - Incubateur',
            ],
            'kpi_a_suivre' => [
                ['kpi' => 'Chiffre d\'affaires mensuel', 'objectif' => number_format(($db?->getRevenusAttendus() ?? 0) / 12, 0, ',', ' ') . ' DT/mois', 'frequence' => 'Mensuel'],
                ['kpi' => 'Taux de conversion', 'objectif' => '> 3%', 'frequence' => 'Hebdomadaire'],
                ['kpi' => 'Burn rate', 'objectif' => '< ' . number_format(($db?->getCoutsEstimes() ?? 0) / 12, 0, ',', ' ') . ' DT/mois', 'frequence' => 'Mensuel'],
            ],
            'score_readiness' => [
                'investissement' => min(100, max(0, $score - 10)),
                'marche' => $db?->getScoreMarche() ?? 50,
                'equipe' => ($db?->getForceEquipe() ?? 5) * 10,
                'commentaire' => $score >= 60
                    ? 'Le projet montre une bonne maturité. Prêt pour la prochaine étape.'
                    : 'Le projet nécessite encore du travail avant de chercher des investisseurs.',
            ],
        ];
    }
}
