<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ProjetRepository;
use App\Repository\UserRepository;
use App\Service\Investment\InvestmentChatbotService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/team-matcher')]
#[IsGranted('ROLE_USER')]
class TeamMatcherController extends AbstractController
{
    #[Route('', name: 'app_team_matcher_index')]
    public function index(ProjetRepository $projetRepo, UserRepository $userRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $projects = $projetRepo->findBy(['user' => $user], ['dateCreation' => 'DESC'], 5);

        $score = $this->computeTeamScore($user, $projects);

        // Fetch some potential matches (mentors, investors, entrepreneurs)
        $potentialMatches = $this->buildPotentialMatches($user, $userRepo, $projects);

        return $this->render('front/team_matcher/index.html.twig', [
            'projects' => $projects,
            'teamScore' => $score,
            'potentialMatches' => $potentialMatches,
        ]);
    }

    #[Route('/chat', name: 'app_team_matcher_chat', methods: ['POST'])]
    public function chat(Request $request, InvestmentChatbotService $chatbot, ProjetRepository $projetRepo, \App\Service\SimpleRateLimiter $rateLimiter): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Rate-limit per user to protect the external LLM quota/cost.
        if ($rateLimiter->tooManyAttempts('ai_teammatch_' . $user->getUserIdentifier(), 20, 60)) {
            return $this->json(['error' => 'Too many requests. Try again in a minute.'], 429);
        }

        $message = trim($request->request->get('message', ''));
        $history = json_decode($request->request->get('history', '[]'), true);
        if (!is_array($history)) {
            $history = [];
        }

        if ($message === '') {
            return $this->json(['error' => 'Empty message'], 400);
        }
        if (mb_strlen($message) > 2000) {
            return $this->json(['error' => 'Message too long'], 400);
        }

        $projects = $projetRepo->findBy(['user' => $user], ['dateCreation' => 'DESC'], 5);
        $projectList = implode(', ', array_map(
            fn($p) => $p->getTitre() . ' (' . ($p->getSecteur() ?? 'general') . ')',
            $projects
        ));

        $context = [
            'mode' => 'team_matcher',
            'userName' => trim($user->getFullName()) ?: ($user->getFirstname() ?? 'User'),
            'role' => $this->isGranted('ROLE_ENTREPRENEUR') ? 'entrepreneur' : 'investor',
            'projects' => $projectList ?: 'No projects yet',
            'projectCount' => (string) count($projects),
        ];

        try {
            $response = $chatbot->chatWithContext($message, $context, $history);
            return $this->json(['response' => $response]);
        } catch (\Throwable) {
            return $this->json(['error' => 'AI service temporarily unavailable.'], 500);
        }
    }

    /** @param \App\Entity\Projet[] $projects */
    private function computeTeamScore(User $user, array $projects): int
    {
        $score = 0;

        if ($user->getFirstname() && $user->getLastname()) $score += 10;
        if ($user->getBio() && mb_strlen((string) $user->getBio()) > 30) $score += 15;
        if ($user->getProfilePicture()) $score += 10;
        if ($user->getLinkedinUrl()) $score += 15;
        if ($user->getCompanyName()) $score += 10;
        if ($user->getPhone()) $score += 5;
        if (count($projects) > 0) $score += 20;
        if (count($projects) >= 2) $score += 10;
        foreach ($projects as $p) {
            if ($p->getStatut() === 'PUBLIE') { $score += 5; break; }
        }

        return min(100, $score);
    }

    /**
     * @param \App\Entity\Projet[] $userProjects
     * @return array<int, array<string, mixed>>
     */
    private function buildPotentialMatches(User $user, UserRepository $userRepo, array $userProjects): array
    {
        $matches = [];
        $userSectors = array_unique(array_filter(array_map(fn($p) => $p->getSecteur(), $userProjects)));

        $candidates = $userRepo->createQueryBuilder('u')
            ->where('u != :me')
            ->setParameter('me', $user)
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            $compatScore = $this->computeCompatibility($user, $candidate, $userSectors);
            if ($compatScore < 20) continue;

            $role = $candidate->getRole();
            $roleLabel = match ($role) {
                'ENTREPRENEUR' => 'Entrepreneur',
                'INVESTISSEUR' => 'Investor',
                'MENTOR' => 'Mentor',
                default => 'Member',
            };

            $matches[] = [
                'user' => $candidate,
                'score' => $compatScore,
                'roleLabel' => $roleLabel,
                'matchReason' => $this->buildMatchReason($user, $candidate, $compatScore),
            ];
        }

        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($matches, 0, 6);
    }

    /** @param string[] $userSectors */
    private function computeCompatibility(User $me, User $other, array $userSectors): int
    {
        $score = 30; // base score

        // Different roles = more complementary
        if ($me->getRole() !== $other->getRole()) {
            $score += 25;
        }

        // Both have bios → visible profile
        if ($other->getBio() && mb_strlen((string) $other->getBio()) > 20) {
            $score += 15;
        }

        // Has LinkedIn
        if ($other->getLinkedinUrl()) {
            $score += 15;
        }

        // Has profile picture
        if ($other->getProfilePicture()) {
            $score += 10;
        }

        // Has company name
        if ($other->getCompanyName()) {
            $score += 5;
        }

        return min(100, $score);
    }

    private function buildMatchReason(User $me, User $other, int $score): string
    {
        $reasons = [];

        if ($me->getRole() !== $other->getRole()) {
            $reasons[] = 'Complementary role';
        }
        if ($other->getLinkedinUrl()) {
            $reasons[] = 'Verified LinkedIn';
        }
        if ($other->getBio() && mb_strlen((string) $other->getBio()) > 50) {
            $reasons[] = 'Detailed profile';
        }
        if ($other->getCompanyName()) {
            $reasons[] = 'Company: ' . $other->getCompanyName();
        }

        return implode(' · ', array_slice($reasons, 0, 2));
    }
}
