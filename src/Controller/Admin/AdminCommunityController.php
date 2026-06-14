<?php

namespace App\Controller\Admin;

use App\Entity\EventParticipant;
use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\Post;
use App\Entity\PostReaction;
use App\Entity\Event;
use App\Entity\Thread;
use App\Repository\GroupRepository;
use App\Repository\PostRepository;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/community')]
#[IsGranted('ROLE_ADMIN')]
class AdminCommunityController extends AbstractController
{
    #[Route('', name: 'admin_community_dashboard')]
    public function index(
        GroupRepository $groupRepo,
        EventRepository $eventRepo,
        PostRepository $postRepo,
        EntityManagerInterface $em,
    ): Response {
        return $this->render('admin/community/index.html.twig', [
            'section' => 'overview',
            'communityTotals' => $this->buildCommunityTotals($groupRepo, $eventRepo, $postRepo),
            'groupStats' => $this->buildGroupStats($groupRepo, $em),
            'eventStats' => $this->buildEventStats($eventRepo, $em),
            'postStats' => $this->buildPostStats($postRepo, $em),
        ]);
    }

    #[Route('/groups', name: 'admin_community_groups')]
    public function groups(
        Request $request,
        GroupRepository $repo,
        EventRepository $eventRepo,
        PostRepository $postRepo,
        EntityManagerInterface $em,
        PaginatorInterface $paginator,
    ): Response
    {
        $qb = $repo->createQueryBuilder('g')
            ->leftJoin('g.groupAdmin', 'a')->addSelect('a')
            ->orderBy('g.createdAt', 'DESC');
        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        return $this->render('admin/community/groups.html.twig', [
            'pagination' => $pagination,
            'section' => 'groups',
            'communityTotals' => $this->buildCommunityTotals($repo, $eventRepo, $postRepo),
            'stats' => $this->buildGroupStats($repo, $em),
        ]);
    }

    #[Route('/groups/{id}/delete', name: 'admin_community_groups_delete', methods: ['POST'])]
    public function deleteGroup(Group $group, EntityManagerInterface $em): Response
    {
        $em->remove($group);
        $em->flush();
        $this->addFlash('success', 'Groupe supprimé.');
        return $this->redirectToRoute('admin_community_groups');
    }

    #[Route('/posts', name: 'admin_community_posts')]
    public function posts(
        Request $request,
        PostRepository $repo,
        GroupRepository $groupRepo,
        EventRepository $eventRepo,
        EntityManagerInterface $em,
        PaginatorInterface $paginator,
    ): Response
    {
        $qb = $repo->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->orderBy('p.createdAt', 'DESC');
        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        return $this->render('admin/community/posts.html.twig', [
            'pagination' => $pagination,
            'section' => 'posts',
            'communityTotals' => $this->buildCommunityTotals($groupRepo, $eventRepo, $repo),
            'stats' => $this->buildPostStats($repo, $em),
        ]);
    }

    #[Route('/posts/{id}/delete', name: 'admin_community_posts_delete', methods: ['POST'])]
    public function deletePost(Post $post, EntityManagerInterface $em): Response
    {
        $em->remove($post);
        $em->flush();
        $this->addFlash('success', 'Publication supprimée.');
        return $this->redirectToRoute('admin_community_posts');
    }

    #[Route('/events', name: 'admin_community_events')]
    public function events(
        Request $request,
        EventRepository $repo,
        GroupRepository $groupRepo,
        PostRepository $postRepo,
        EntityManagerInterface $em,
        PaginatorInterface $paginator,
    ): Response
    {
        $qb = $repo->createQueryBuilder('e')
            ->leftJoin('e.createdBy', 'u')->addSelect('u')
            ->orderBy('e.eventDate', 'DESC');
        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        return $this->render('admin/community/events.html.twig', [
            'pagination' => $pagination,
            'section' => 'events',
            'communityTotals' => $this->buildCommunityTotals($groupRepo, $repo, $postRepo),
            'stats' => $this->buildEventStats($repo, $em),
        ]);
    }

    #[Route('/events/{id}/delete', name: 'admin_community_events_delete', methods: ['POST'])]
    public function deleteEvent(Event $event, EntityManagerInterface $em): Response
    {
        $em->remove($event);
        $em->flush();
        $this->addFlash('success', 'Événement supprimé.');
        return $this->redirectToRoute('admin_community_events');
    }

    private function buildCommunityTotals(
        GroupRepository $groupRepo,
        EventRepository $eventRepo,
        PostRepository $postRepo,
    ): array {
        return [
            'groups' => $groupRepo->count([]),
            'events' => $eventRepo->count([]),
            'posts' => $postRepo->count([]),
        ];
    }

    private function buildGroupStats(GroupRepository $repo, EntityManagerInterface $em): array
    {
        return [
            'total' => $repo->count([]),
            'private' => $repo->count(['isPrivate' => true]),
            'public' => $repo->count(['isPrivate' => false]),
            'memberships' => $em->getRepository(GroupMember::class)->count([]),
            'threads' => $em->getRepository(Thread::class)->count([]),
        ];
    }

    private function buildPostStats(PostRepository $repo, EntityManagerInterface $em): array
    {
        return [
            'total' => $repo->count([]),
            'with_images' => $this->countWhere($repo, 'p', 'p.imageUrl IS NOT NULL AND p.imageUrl <> :empty', ['empty' => '']),
            'today' => $this->countWhere($repo, 'p', 'p.createdAt >= :today', ['today' => new \DateTimeImmutable('today')]),
            'reactions' => $em->getRepository(PostReaction::class)->count([]),
        ];
    }

    private function buildEventStats(EventRepository $repo, EntityManagerInterface $em): array
    {
        $now = new \DateTimeImmutable('now');

        return [
            'total' => $repo->count([]),
            'upcoming' => $this->countWhere($repo, 'e', 'e.eventDate >= :now', ['now' => $now]),
            'past' => $this->countWhere($repo, 'e', 'e.eventDate < :now', ['now' => $now]),
            'limited' => $this->countWhere($repo, 'e', 'e.capacity > 0'),
            'registrations' => $em->getRepository(EventParticipant::class)->count([]),
        ];
    }

    private function countWhere(object $repo, string $alias, string $condition, array $parameters = []): int
    {
        $qb = $repo->createQueryBuilder($alias)
            ->select("COUNT($alias.id)")
            ->andWhere($condition);

        foreach ($parameters as $name => $value) {
            $qb->setParameter($name, $value);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
