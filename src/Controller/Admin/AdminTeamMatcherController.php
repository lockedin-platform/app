<?php

namespace App\Controller\Admin;

use App\Repository\UserConnectionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/team-matcher')]
#[IsGranted('ROLE_ADMIN')]
class AdminTeamMatcherController extends AbstractController
{
    #[Route('', name: 'admin_team_matcher')]
    public function index(UserConnectionRepository $connectionRepo, UserRepository $userRepo): Response
    {
        $connections = $connectionRepo->findBy([], ['createdAt' => 'DESC'], 50);

        $topConnected = $userRepo->createQueryBuilder('u')
            ->select('u, COUNT(c.id) as HIDDEN connCount')
            ->leftJoin(\App\Entity\UserConnection::class, 'c', 'WITH', 'c.followed = u')
            ->groupBy('u.id')
            ->orderBy('connCount', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->render('admin/team_matcher/index.html.twig', [
            'connections' => $connections,
            'totalConnections' => $connectionRepo->count([]),
            'topConnected' => $topConnected,
        ]);
    }
}
