<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserFollow;
use App\Service\WebSocketNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class PublicProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WebSocketNotifier $wsNotifier,
    ) {}

    #[Route('/user/{id}', name: 'app_user_profile', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $isOwn = $currentUser->getId() === $user->getId();

        // Check if current user follows this user
        $isFollowing = (bool) $this->em->getRepository(UserFollow::class)->findOneBy([
            'follower' => $currentUser,
            'followed' => $user,
        ]);

        // Follower/following counts
        $followersCount = $this->em->getRepository(UserFollow::class)->count(['followed' => $user]);
        $followingCount = $this->em->getRepository(UserFollow::class)->count(['follower' => $user]);

        // Activity stats
        $stats = $this->getUserStats($user);

        return $this->render('front/profile/public_profile.html.twig', [
            'profileUser' => $user,
            'isOwn' => $isOwn,
            'isFollowing' => $isFollowing,
            'followersCount' => $followersCount,
            'followingCount' => $followingCount,
            'stats' => $stats,
        ]);
    }

    #[Route('/user/{id}/follow', name: 'app_user_follow', methods: ['POST'])]
    public function follow(int $id, Request $request): JsonResponse
    {
        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->getId() === $user->getId()) {
            return new JsonResponse(['error' => 'Cannot follow yourself'], 400);
        }

        $repo = $this->em->getRepository(UserFollow::class);
        $existing = $repo->findOneBy(['follower' => $currentUser, 'followed' => $user]);

        if ($existing) {
            // Unfollow
            $this->em->remove($existing);
            $this->em->flush();

            $followersCount = $repo->count(['followed' => $user]);
            return new JsonResponse(['action' => 'unfollowed', 'followersCount' => $followersCount]);
        }

        // Follow
        $follow = new UserFollow();
        $follow->setFollower($currentUser);
        $follow->setFollowed($user);
        $this->em->persist($follow);

        // Send notification
        $notif = new Notification();
        $notif->setUser($user);
        $notif->setTitle('Nouveau follower');
        $notif->setMessage($currentUser->getFirstname() . ' ' . $currentUser->getLastname() . ' vous suit maintenant.');
        $notif->setType('SUCCESS');
        $this->em->persist($notif);

        $this->em->flush();

        // Push real-time notification via WebSocket
        $this->wsNotifier->pushNotification($user->getId(), [
            'id' => $notif->getId(),
            'title' => $notif->getTitle(),
            'message' => $notif->getMessage(),
            'notifType' => $notif->getType(),
            'typeIcon' => $notif->getTypeIcon(),
            'typeColor' => $notif->getTypeColor(),
            'isRead' => false,
            'createdAt' => $notif->getCreatedAt()->format('d/m H:i'),
        ]);

        $followersCount = $repo->count(['followed' => $user]);
        return new JsonResponse(['action' => 'followed', 'followersCount' => $followersCount]);
    }

    #[Route('/api/notifications', name: 'app_api_notifications', methods: ['GET'])]
    public function apiNotifications(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $notifications = $this->em->getRepository(Notification::class)
            ->findBy(['user' => $user], ['createdAt' => 'DESC'], 15);

        $unreadCount = $this->em->getRepository(Notification::class)
            ->count(['user' => $user, 'read' => false]);

        $data = [];
        foreach ($notifications as $n) {
            $data[] = [
                'id' => $n->getId(),
                'title' => $n->getTitle(),
                'message' => $n->getMessage(),
                'type' => $n->getType(),
                'typeIcon' => $n->getTypeIcon(),
                'typeColor' => $n->getTypeColor(),
                'isRead' => $n->isRead(),
                'createdAt' => $n->getCreatedAt()->format('d/m H:i'),
            ];
        }

        return new JsonResponse(['notifications' => $data, 'unreadCount' => $unreadCount]);
    }

    #[Route('/api/notifications/read-all', name: 'app_api_notifications_read_all', methods: ['POST'])]
    public function apiMarkAllRead(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->em->createQueryBuilder()
            ->update(Notification::class, 'n')
            ->set('n.read', 'true')
            ->where('n.user = :user')
            ->andWhere('n.read = false')
            ->setParameter('user', $user)
            ->getQuery()->execute();

        return new JsonResponse(['success' => true]);
    }

    /** @return array<string, int> */
    private function getUserStats(User $user): array
    {
        $conn = $this->em->getConnection();

        $posts = (int) $conn->fetchOne('SELECT COUNT(*) FROM posts WHERE user_id = ?', [$user->getId()]);
        $groups = (int) $conn->fetchOne('SELECT COUNT(*) FROM group_members WHERE user_id = ?', [$user->getId()]);
        $projets = (int) $conn->fetchOne('SELECT COUNT(*) FROM projet WHERE user_id = ?', [$user->getId()]);
        $comments = (int) $conn->fetchOne('SELECT COUNT(*) FROM comments WHERE user_id = ?', [$user->getId()]);

        return [
            'posts' => $posts,
            'groups' => $groups,
            'projets' => $projets,
            'comments' => $comments,
        ];
    }
}
