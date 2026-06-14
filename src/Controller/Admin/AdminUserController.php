<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Notification;
use App\Repository\LoginHistoryRepository;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    #[Route('', name: 'admin_users')]
    public function index(Request $request, UserRepository $repo, PaginatorInterface $paginator): Response
    {
        $search = $request->query->get('q', '');
        $roleFilter = $request->query->get('role', '');
        $statusFilter = $request->query->get('status', '');

        $qb = $repo->createQueryBuilder('u')->orderBy('u.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('u.firstname LIKE :q OR u.lastname LIKE :q OR u.email LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }
        if ($roleFilter) {
            $qb->andWhere('u.role = :role')->setParameter('role', $roleFilter);
        }
        if ($statusFilter === 'banned') {
            $qb->andWhere('u.isBanned = true');
        } elseif ($statusFilter === 'inactive') {
            $qb->andWhere('u.isActive = false');
        } elseif ($statusFilter === 'unverified') {
            $qb->andWhere('u.verified = false');
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        // Stats
        $totalUsers = $repo->count([]);
        $bannedUsers = $repo->count(['isBanned' => true]);
        $verifiedUsers = $repo->count(['verified' => true]);

        return $this->render('admin/user/index.html.twig', [
            'pagination' => $pagination,
            'search' => $search,
            'roleFilter' => $roleFilter,
            'statusFilter' => $statusFilter,
            'totalUsers' => $totalUsers,
            'bannedUsers' => $bannedUsers,
            'verifiedUsers' => $verifiedUsers,
        ]);
    }

    #[Route('/new', name: 'admin_users_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $user = new User();
            $this->hydrateUser($user, $request);
            $plainPassword = $request->request->get('password') ?: 'lockedin123';
            $user->setPassword($hasher->hashPassword($user, $plainPassword));
            $user->setVerified(true);

            $errors = $validator->validate($user);
            if (count($errors) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('admin/user/form.html.twig', [
                    'user' => null,
                    'fieldErrors' => $fieldErrors,
                    'formData' => $request->request->all(),
                ]);
            }

            try {
                $em->persist($user);
                $em->flush();
                $this->addFlash('success', "Utilisateur {$user->getEmail()} créé avec succès. Mot de passe: $plainPassword");
                return $this->redirectToRoute('admin_users');
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Erreur lors de la création : ' . $e->getMessage());
                return $this->render('admin/user/form.html.twig', [
                    'user' => null,
                    'fieldErrors' => [],
                    'formData' => $request->request->all(),
                ]);
            }
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_users_edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $this->hydrateUser($user, $request);

            $errors = $validator->validate($user);
            if (count($errors) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('admin/user/form.html.twig', ['user' => $user, 'fieldErrors' => $fieldErrors]);
            }

            $em->flush();
            $this->addFlash('success', 'Utilisateur modifié avec succès.');
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/toggle-ban', name: 'admin_users_toggle_ban', methods: ['POST'])]
    public function toggleBan(User $user, EntityManagerInterface $em): Response
    {
        $user->setIsBanned(!$user->getIsBanned());
        $em->flush();
        $status = $user->getIsBanned() ? 'banni' : 'débanni';
        $this->addFlash('success', "Utilisateur {$status} avec succès.");
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/{id}/toggle-active', name: 'admin_users_toggle_active', methods: ['POST'])]
    public function toggleActive(User $user, EntityManagerInterface $em): Response
    {
        $user->setIsActive(!$user->getIsActive());
        $em->flush();
        $this->addFlash('success', 'Statut mis à jour.');
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/{id}/unlock', name: 'admin_users_unlock', methods: ['POST'])]
    public function unlock(User $user, EntityManagerInterface $em): Response
    {
        $user->setIsActive(true);
        $user->setIsBanned(false);
        $user->resetLoginAttempts();
        $em->flush();
        $this->addFlash('success', 'Compte déverrouillé avec succès.');
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/{id}/delete', name: 'admin_users_delete', methods: ['POST'])]
    public function delete(User $user, EntityManagerInterface $em): Response
    {
        $em->remove($user);
        $em->flush();
        $this->addFlash('success', 'Utilisateur supprimé.');
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/{id}/login-history', name: 'admin_users_login_history')]
    public function loginHistory(User $user, LoginHistoryRepository $loginHistoryRepo): Response
    {
        return $this->render('admin/user/login_history.html.twig', [
            'user' => $user,
            'history' => $loginHistoryRepo->findByUser($user->getId(), 100),
        ]);
    }

    #[Route('/broadcast', name: 'admin_users_broadcast', methods: ['GET', 'POST'])]
    public function broadcast(Request $request, UserRepository $userRepo, EmailService $emailService, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $subject = trim($request->request->get('subject', ''));
            $body = trim($request->request->get('body', ''));
            $targetRole = $request->request->get('target_role', '');

            if (!$subject || !$body) {
                $this->addFlash('danger', 'Sujet et contenu requis.');
                return $this->render('admin/user/broadcast.html.twig');
            }

            $qb = $userRepo->createQueryBuilder('u')
                ->select('u.email')
                ->where('u.isActive = true AND u.isBanned = false');

            if ($targetRole) {
                $qb->andWhere('u.role = :role')->setParameter('role', $targetRole);
            }

            $emails = array_column($qb->getQuery()->getArrayResult(), 'email');

            if (empty($emails)) {
                $this->addFlash('warning', 'Aucun destinataire trouvé.');
                return $this->render('admin/user/broadcast.html.twig');
            }

            try {
                $emailService->sendBroadcast($emails, $subject, $body);
                $this->addFlash('success', 'Email envoyé à ' . count($emails) . ' utilisateur(s).');
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Erreur lors de l\'envoi.');
            }

            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user/broadcast.html.twig');
    }

    #[Route('/export-csv', name: 'admin_users_export_csv')]
    public function exportCsv(UserRepository $userRepo): StreamedResponse
    {
        $users = $userRepo->findAll();

        $response = new StreamedResponse(function () use ($users) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Prénom', 'Nom', 'Email', 'Téléphone', 'Rôle', 'Vérifié', 'Actif', 'Banni', 'Inscrit le']);
            foreach ($users as $user) {
                fputcsv($handle, [
                    $user->getId(),
                    $user->getFirstname(),
                    $user->getLastname(),
                    $user->getEmail(),
                    $user->getPhone(),
                    $user->getRole(),
                    $user->isVerified() ? 'Oui' : 'Non',
                    $user->getIsActive() ? 'Oui' : 'Non',
                    $user->getIsBanned() ? 'Oui' : 'Non',
                    $user->getCreatedAt()->format('d/m/Y H:i'),
                ]);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="users_' . date('Y-m-d') . '.csv"');

        return $response;
    }

    #[Route('/stats', name: 'admin_users_stats')]
    public function stats(UserRepository $userRepo, LoginHistoryRepository $loginHistoryRepo): Response
    {
        $roleCounts = $userRepo->createQueryBuilder('u')
            ->select('u.role, COUNT(u.id) as cnt')
            ->groupBy('u.role')
            ->getQuery()
            ->getArrayResult();

        $recentLogins = $loginHistoryRepo->findAllWithUsers(20);

        return $this->render('admin/user/stats.html.twig', [
            'roleCounts' => $roleCounts,
            'totalUsers' => $userRepo->count([]),
            'verifiedUsers' => $userRepo->count(['verified' => true]),
            'bannedUsers' => $userRepo->count(['isBanned' => true]),
            'recentLogins' => $recentLogins,
        ]);
    }

    private function hydrateUser(User $user, Request $request): void
    {
        $user->setFirstname($request->request->get('firstname'));
        $user->setLastname($request->request->get('lastname'));
        $user->setEmail($request->request->get('email'));
        $user->setPhone($request->request->get('phone'));
        $user->setRole($request->request->get('role', 'ENTREPRENEUR'));
        $user->setBio($request->request->get('bio'));
        $user->setCompanyName($request->request->get('company_name'));
        $user->setAddress($request->request->get('address'));
    }
}
