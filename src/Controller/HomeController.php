<?php

namespace App\Controller;

use Doctrine\DBAL\Exception as DbalException;
use App\Entity\InvestmentOffer;
use App\Entity\InvestmentOpportunity;
use App\Entity\User;
use App\Repository\CoursRepository;
use App\Repository\InvestmentOfferRepository;
use App\Repository\InvestmentOpportunityRepository;
use App\Repository\MentorAvailabilityRepository;
use App\Repository\MentorshipSessionRepository;
use App\Repository\PostRepository;
use App\Repository\ProjetRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        UserRepository $userRepo,
        ProjetRepository $projetRepo,
        InvestmentOpportunityRepository $oppRepo,
        InvestmentOfferRepository $offerRepo,
        CoursRepository $coursRepo,
        MentorAvailabilityRepository $mentorAvailRepo,
        MentorshipSessionRepository $sessionRepo,
        PostRepository $postRepo,
    ): Response {
        $platformStats = [
            'totalUsers' => 0,
            'totalInvestors' => 0,
            'totalEntrepreneurs' => 0,
            'totalMentors' => 0,
            'activeProjects' => 0,
            'totalProjects' => 0,
            'openOpportunities' => 0,
            'fundedDeals' => 0,
            'totalCours' => 0,
            'totalMentorsAvail' => 0,
            'totalSessions' => 0,
            'totalPosts' => 0,
            'latestProjectName' => '',
            'latestProjectSector' => '',
        ];
        $activityFeed = [];

        try {
            // ── Platform stats ──
            $totalUsers = $userRepo->count([]);
            $totalInvestors = $userRepo->countByRole(User::ROLE_INVESTISSEUR);
            $totalEntrepreneurs = $userRepo->countByRole(User::ROLE_ENTREPRENEUR);
            $totalMentors = $userRepo->countByRole(User::ROLE_MENTOR);

            $activeProjects = $projetRepo->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.statut = :s')
                ->setParameter('s', 'ACTIF')
                ->getQuery()->getSingleScalarResult();

            $totalProjects = $projetRepo->count([]);

            $openOpportunities = $oppRepo->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->where('o.status = :s')
                ->setParameter('s', InvestmentOpportunity::STATUS_OPEN)
                ->getQuery()->getSingleScalarResult();

            $fundedDeals = $offerRepo->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->where('o.paid = :p')
                ->setParameter('p', true)
                ->getQuery()->getSingleScalarResult();

            $totalCours = $coursRepo->count([]);
            $totalMentorsAvailable = $mentorAvailRepo->createQueryBuilder('a')
                ->select('COUNT(DISTINCT a.mentor)')
                ->getQuery()->getSingleScalarResult();
            $totalSessions = $sessionRepo->count([]);
            $totalPosts = $postRepo->count([]);

            $latestProject = $projetRepo->createQueryBuilder('p')
                ->orderBy('p.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();

            $platformStats = [
                'totalUsers' => (int) $totalUsers,
                'totalInvestors' => (int) $totalInvestors,
                'totalEntrepreneurs' => (int) $totalEntrepreneurs,
                'totalMentors' => (int) $totalMentors,
                'activeProjects' => (int) $activeProjects,
                'totalProjects' => (int) $totalProjects,
                'openOpportunities' => (int) $openOpportunities,
                'fundedDeals' => (int) $fundedDeals,
                'totalCours' => (int) $totalCours,
                'totalMentorsAvail' => (int) $totalMentorsAvailable,
                'totalSessions' => (int) $totalSessions,
                'totalPosts' => (int) $totalPosts,
                'latestProjectName' => $latestProject?->getTitre() ?? '',
                'latestProjectSector' => $latestProject?->getSecteur() ?? '',
            ];

            // ── Activity feed (10 most recent events) ──
            $activityFeed = $this->buildActivityFeed($projetRepo, $offerRepo, $oppRepo, $sessionRepo, $userRepo, $postRepo);
        } catch (DbalException | \PDOException) {
            // Keep the public homepage available when the local database is offline.
        }

        return $this->render('front/home/index.html.twig', [
            'platformStats' => $platformStats,
            'activityFeed' => $activityFeed,
        ]);
    }

    private function buildActivityFeed(
        ProjetRepository $projetRepo,
        InvestmentOfferRepository $offerRepo,
        InvestmentOpportunityRepository $oppRepo,
        MentorshipSessionRepository $sessionRepo,
        UserRepository $userRepo,
        PostRepository $postRepo,
    ): array {
        $events = [];
        $now = new \DateTimeImmutable();

        // Recent projects
        $recentProjects = $projetRepo->createQueryBuilder('p')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(3)
            ->getQuery()->getResult();
        foreach ($recentProjects as $p) {
            $events[] = [
                'type' => 'project',
                'text' => 'Nouveau projet dans le secteur ' . ($p->getSecteur() ?? 'divers'),
                'date' => $p->getDateCreation() ?? $now,
            ];
        }

        // Recent offers submitted
        $recentOffers = $offerRepo->createQueryBuilder('o')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults(3)
            ->getQuery()->getResult();
        foreach ($recentOffers as $o) {
            $label = $o->getStatus() === InvestmentOffer::STATUS_ACCEPTED && $o->isPaid()
                ? 'Un contrat d\'investissement a ete finance'
                : 'Un investisseur a soumis une offre';
            $events[] = [
                'type' => 'investment',
                'text' => $label,
                'date' => $o->getCreatedAt() ?? $now,
            ];
        }

        // Recent mentorship sessions
        $recentSessions = $sessionRepo->createQueryBuilder('s')
            ->orderBy('s.scheduledAt', 'DESC')
            ->setMaxResults(2)
            ->getQuery()->getResult();
        foreach ($recentSessions as $s) {
            $events[] = [
                'type' => 'mentorship',
                'text' => 'Une session de mentorat a ete reservee',
                'date' => $s->getScheduledAt() ?? $now,
            ];
        }

        // Recent users
        $recentUsers = $userRepo->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults(2)
            ->getQuery()->getResult();
        foreach ($recentUsers as $u) {
            $events[] = [
                'type' => 'user',
                'text' => 'Un nouvel entrepreneur a rejoint la plateforme',
                'date' => $u->getCreatedAt() ?? $now,
            ];
        }

        // Recent community posts
        $recentPosts = $postRepo->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(2)
            ->getQuery()->getResult();
        foreach ($recentPosts as $p) {
            $events[] = [
                'type' => 'community',
                'text' => 'Nouvelle publication dans la communaute',
                'date' => $p->getCreatedAt() ?? $now,
            ];
        }

        // Sort all events by date descending, take 10
        usort($events, fn($a, $b) => $this->toTimestamp($b['date']) - $this->toTimestamp($a['date']));
        $events = array_slice($events, 0, 10);

        // Compute relative time strings
        foreach ($events as &$e) {
            $e['ago'] = $this->relativeTime($e['date'], $now);
            unset($e['date']);
        }

        return $events;
    }

    private function toTimestamp(mixed $date): int
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->getTimestamp();
        }
        return 0;
    }

    private function relativeTime(mixed $date, \DateTimeImmutable $now): string
    {
        if (!$date instanceof \DateTimeInterface) {
            return '';
        }
        $diff = $now->getTimestamp() - $date->getTimestamp();
        if ($diff < 60) return 'a l\'instant';
        if ($diff < 3600) return 'il y a ' . (int) ($diff / 60) . ' min';
        if ($diff < 86400) return 'il y a ' . (int) ($diff / 3600) . ' h';
        $days = (int) ($diff / 86400);
        if ($days === 0) return 'aujourd\'hui';
        if ($days === 1) return 'hier';
        if ($days < 30) return 'il y a ' . $days . ' jours';
        return 'il y a ' . (int) ($days / 30) . ' mois';
    }
}
