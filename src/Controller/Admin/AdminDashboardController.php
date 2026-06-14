<?php

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use App\Repository\ProjetRepository;
use App\Repository\UserConnectionRepository;
use App\Repository\GroupRepository;
use App\Repository\EventRepository;
use App\Repository\PostRepository;
use App\Repository\InvestmentOpportunityRepository;
use App\Repository\MentorshipRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminDashboardController extends AbstractController
{
    #[Route('/preview/enter', name: 'admin_preview_enter')]
    public function enterPreview(): RedirectResponse
    {
        $response = new RedirectResponse('/');
        $response->headers->setCookie(Cookie::create('nj_admin_preview', '1', 0, '/', null, false, false, false, 'lax'));
        return $response;
    }

    #[Route('/preview/exit', name: 'admin_preview_exit')]
    public function exitPreview(): RedirectResponse
    {
        $response = $this->redirectToRoute('admin_dashboard');
        $response->headers->clearCookie('nj_admin_preview', '/');
        return $response;
    }

    #[Route('', name: 'admin_dashboard')]
    public function index(
        UserRepository $userRepo,
        ProjetRepository $projetRepo,
        UserConnectionRepository $connectionRepo,
        GroupRepository $groupRepo,
        EventRepository $eventRepo,
        PostRepository $postRepo,
        InvestmentOpportunityRepository $investRepo,
        MentorshipRequestRepository $mentorRepo,
    ): Response {
        return $this->render('admin/dashboard/index.html.twig', [
            'totalUsers' => $userRepo->count([]),
            'totalEntrepreneurs' => $userRepo->countByRole('ENTREPRENEUR'),
            'totalMentors' => $userRepo->countByRole('MENTOR'),
            'totalInvestisseurs' => $userRepo->countByRole('INVESTISSEUR'),
            'bannedUsers' => $userRepo->countBanned(),
            'verifiedUsers' => $userRepo->countVerified(),
            'totalProjets' => $projetRepo->count([]),
            'totalConnections' => $connectionRepo->count([]),
            'totalGroups' => $groupRepo->count([]),
            'totalEvents' => $eventRepo->count([]),
            'totalPosts' => $postRepo->count([]),
            'totalOpportunities' => $investRepo->count([]),
            'totalMentorships' => $mentorRepo->count([]),
            'upcomingEvents' => $eventRepo->findUpcoming(5),
        ]);
    }
}
