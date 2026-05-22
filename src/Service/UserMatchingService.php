<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;

class UserMatchingService
{
    public function __construct(
        private readonly GeminiService $ai,
        private readonly UserRepository $userRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Find matching users based on profile similarity using AI.
     *
     * @return array<int, array{user: User, reason: string, score: int}>
     */
    public function findMatches(User $currentUser, int $limit = 5): array
    {
        // Get candidate users (exclude current, banned, inactive)
        $candidates = $this->getCandidates($currentUser, $limit * 3);

        if (empty($candidates)) {
            return [];
        }

        // Build profiles summary for AI matching
        $currentProfile = $this->buildProfileSummary($currentUser);
        $candidateProfiles = [];
        foreach ($candidates as $candidate) {
            $candidateProfiles[] = [
                'id' => $candidate->getId(),
                'summary' => $this->buildProfileSummary($candidate),
            ];
        }

        // Ask AI to rank matches
        $prompt = $this->buildMatchingPrompt($currentProfile, $candidateProfiles, $limit);
        $result = $this->ai->generateJson($prompt, 0.3);

        if (!$result || !isset($result['matches'])) {
            // Fallback: return candidates by role similarity
            return $this->fallbackMatching($currentUser, $candidates, $limit);
        }

        // Map AI results back to User entities
        $matches = [];
        $candidateMap = [];
        foreach ($candidates as $c) {
            $candidateMap[$c->getId()] = $c;
        }

        foreach ($result['matches'] as $match) {
            $id = (int) ($match['id'] ?? 0);
            if (isset($candidateMap[$id])) {
                $matches[] = [
                    'user' => $candidateMap[$id],
                    'reason' => $match['reason'] ?? 'Profil compatible',
                    'score' => min(100, max(0, (int) ($match['score'] ?? 70))),
                ];
            }
        }

        return array_slice($matches, 0, $limit);
    }

    /**
     * @return User[]
     */
    private function getCandidates(User $currentUser, int $limit): array
    {
        $qb = $this->userRepository->createQueryBuilder('u')
            ->where('u.id != :currentId')
            ->andWhere('u.isActive = true')
            ->andWhere('u.isBanned = false')
            ->setParameter('currentId', $currentUser->getId())
            ->setMaxResults($limit)
            ->orderBy('u.totalXp', 'DESC');

        // Exclude users already connected
        $connectedIds = $this->getConnectedUserIds($currentUser->getId());
        if (!empty($connectedIds)) {
            $qb->andWhere('u.id NOT IN (:connected)')
               ->setParameter('connected', $connectedIds);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return int[] */
    private function getConnectedUserIds(int $userId): array
    {
        $sql = 'SELECT CASE WHEN follower_id = ? THEN followed_id ELSE follower_id END as cid 
                FROM user_connection 
                WHERE (follower_id = ? OR followed_id = ?)';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, [$userId, $userId, $userId]);
            return array_map(fn($r) => (int) $r['cid'], $rows);
        } catch (\Exception) {
            return [];
        }
    }

    private function buildProfileSummary(User $user): string
    {
        $parts = [];
        $parts[] = "Nom: {$user->getFirstname()} {$user->getLastname()}";
        $parts[] = "Rôle: {$user->getRole()}";

        if ($user->getCompanyName()) {
            $parts[] = "Entreprise: {$user->getCompanyName()}";
        }
        if ($user->getBio()) {
            $parts[] = "Bio: " . mb_substr($user->getBio(), 0, 200);
        }
        if ($user->getAddress()) {
            $parts[] = "Localisation: {$user->getAddress()}";
        }

        $parts[] = "XP: {$user->getTotalXp()}";

        return implode(' | ', $parts);
    }

    /**
     * @param array<int, array{id: int, summary: string}> $candidateProfiles
     */
    private function buildMatchingPrompt(string $currentProfile, array $candidateProfiles, int $limit): string
    {
        $candidatesText = '';
        foreach ($candidateProfiles as $cp) {
            $candidatesText .= "- ID:{$cp['id']} → {$cp['summary']}\n";
        }

        return <<<PROMPT
Tu es un algorithme de matching pour une plateforme d'entrepreneuriat tunisienne (Najahni).
Trouve les {$limit} meilleurs profils compatibles pour l'utilisateur ci-dessous.

UTILISATEUR ACTUEL:
{$currentProfile}

CANDIDATS DISPONIBLES:
{$candidatesText}

Critères de matching:
- Complémentarité des rôles (entrepreneur ↔ mentor, entrepreneur ↔ investisseur)
- Secteur d'activité similaire ou complémentaire
- Localisation proche
- Niveau d'expérience compatible

Retourne UNIQUEMENT un JSON avec cette structure:
{
  "matches": [
    {"id": <ID_CANDIDAT>, "score": <0-100>, "reason": "<raison courte en français>"}
  ]
}

Trie par score décroissant. Maximum {$limit} résultats.
PROMPT;
    }

    /**
     * Fallback when AI is unavailable: match by role complementarity.
     *
     * @param User[] $candidates
     * @return array<int, array{user: User, reason: string, score: int}>
     */
    private function fallbackMatching(User $currentUser, array $candidates, int $limit): array
    {
        $roleWeights = [
            'ENTREPRENEUR' => ['MENTOR' => 90, 'INVESTISSEUR' => 85, 'ENTREPRENEUR' => 60],
            'MENTOR' => ['ENTREPRENEUR' => 90, 'INVESTISSEUR' => 70, 'MENTOR' => 50],
            'INVESTISSEUR' => ['ENTREPRENEUR' => 85, 'MENTOR' => 70, 'INVESTISSEUR' => 55],
            'ADMIN' => ['ENTREPRENEUR' => 60, 'MENTOR' => 60, 'INVESTISSEUR' => 60],
        ];

        $reasons = [
            'MENTOR' => 'Peut vous accompagner dans votre parcours',
            'INVESTISSEUR' => 'Potentiel investisseur pour vos projets',
            'ENTREPRENEUR' => 'Entrepreneur avec un profil similaire',
        ];

        $scored = [];
        foreach ($candidates as $candidate) {
            $score = $roleWeights[$currentUser->getRole()][$candidate->getRole()] ?? 50;

            // Bonus for having a bio (more complete profile)
            if ($candidate->getBio()) {
                $score = min(100, $score + 5);
            }
            // Bonus for company name match
            if ($currentUser->getCompanyName() && $candidate->getCompanyName()) {
                $score = min(100, $score + 5);
            }

            $scored[] = [
                'user' => $candidate,
                'reason' => $reasons[$candidate->getRole()] ?? 'Profil compatible',
                'score' => $score,
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }
}
