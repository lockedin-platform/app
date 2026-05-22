<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\LoginHistoryRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\EmailService;
use App\Service\GeminiService;
use App\Service\UserMatchingService;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly GeminiService $ai,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    #[Route('/profile', name: 'app_profile')]
    public function index(
        LoginHistoryRepository $loginHistoryRepo,
        NotificationRepository $notifRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        return $this->render('front/profile/index.html.twig', [
            'loginHistory' => $loginHistoryRepo->findByUser($user->getId(), 10),
            'unreadNotifs' => $notifRepo->countUnread($user->getId()),
            'aiEnabled' => $this->ai->isConfigured(),
        ]);
    }

    #[Route('/profile/ai/bio', name: 'app_profile_ai_generate_bio', methods: ['POST'])]
    public function generateProfileBio(EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->ai->isConfigured()) {
            return new JsonResponse(['error' => 'IA non configurée'], 503);
        }

        $prompt = $this->buildProfileBioPrompt($user);
        $bio = $this->ai->generate($prompt, 0.6);
        if (!$bio) {
            return new JsonResponse(['error' => 'Impossible de générer la bio pour le moment.'], 500);
        }

        $user->setBio($bio);
        $em->flush();

        return new JsonResponse(['bio' => $bio, 'saved' => true]);
    }



    #[Route('/profile/ai/chat', name: 'app_profile_ai_chat', methods: ['POST'])]
    public function chatProfileAi(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        try {
            $body = $request->toArray();
        } catch (\Throwable $e) {
            $body = [];
        }
        $question = trim((string) ($body['message'] ?? ''));
        if ($question === '') {
            return new JsonResponse(['error' => 'Message requis'], 400);
        }
        if (!$this->ai->isConfigured()) {
            return new JsonResponse(['error' => 'IA non configurée'], 503);
        }

        $prompt = $this->buildProfileChatPrompt($user, $question);
        $answer = $this->ai->generate($prompt, 0.7);
        if (!$answer) {
            return new JsonResponse(['error' => 'Impossible de répondre pour le moment.'], 500);
        }

        return new JsonResponse(['answer' => $answer]);
    }

    #[Route('/profile/ai/recommendations', name: 'app_profile_ai_recommendations', methods: ['POST'])]
    public function profileRecommendations(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->ai->isConfigured()) {
            return new JsonResponse(['error' => 'IA non configurée'], 503);
        }

        $stats = $this->getUserStats($user);
        $prompt = $this->buildProfileRecommendationPrompt($user, $stats);
        $recommendationText = $this->ai->generate($prompt, 0.45);
        if (!$recommendationText) {
            return new JsonResponse(['error' => 'Impossible de générer les recommandations.'], 500);
        }

        return new JsonResponse(['recommendations' => $recommendationText]);
    }

    #[Route('/profile/ai/matching', name: 'app_profile_ai_matching', methods: ['POST'])]
    public function aiMatching(UserMatchingService $matchingService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->ai->isConfigured()) {
            return new JsonResponse(['error' => 'IA non configurée'], 503);
        }

        $matches = $matchingService->findMatches($user, 5);

        $result = array_map(fn($m) => [
            'id' => $m['user']->getId(),
            'name' => $m['user']->getFullName(),
            'role' => $m['user']->getRole(),
            'company' => $m['user']->getCompanyName(),
            'picture' => $m['user']->getProfilePicture(),
            'reason' => $m['reason'],
            'score' => $m['score'],
        ], $matches);

        return new JsonResponse(['matches' => $result]);
    }

    private function buildProfileBioPrompt(User $user): string
    {
        $bio = $user->getBio() ? "Bio actuelle : {$user->getBio()}" : 'Bio actuelle : aucune bio disponible.';
        $company = $user->getCompanyName() ? "Entreprise : {$user->getCompanyName()}" : '';
        $linkedin = $user->getLinkedinUrl() ? "LinkedIn : {$user->getLinkedinUrl()}" : '';
        $role = $user->getRole();
        $context = "Name: {$user->getFullName()}\nRole: {$role}\n{$company}\n{$linkedin}\n{$bio}";

        return <<<PROMPT
Tu es un assistant IA de profil pour Najahni. En te basant sur le profil utilisateur suivant, rédige une bio professionnelle en français en 2 à 3 phrases. Le ton doit être clair, engageant et orienté vers l’action, en mettant en avant le rôle et la valeur apportée.

{$context}

Réponds uniquement avec le texte final de la bio, sans explications.
PROMPT;
    }

    private function buildProfileChatPrompt(User $user, string $question): string
    {
        $company = $user->getCompanyName() ? "Entreprise : {$user->getCompanyName()}\n" : '';
        $bio = $user->getBio() ? "Bio : {$user->getBio()}\n" : '';
        return <<<PROMPT
Tu es un assistant personnel pour un utilisateur de Najahni.
Fais des réponses utiles, concises et adaptées à un profil d'entrepreneur ou mentor en Tunisie.
Voici le contexte du profil:
- Nom: {$user->getFullName()}
- Rôle: {$user->getRole()}
{$company}{$bio}
Question: {$question}
PROMPT;
    }

    /** @param array<string, int> $stats */
    private function buildProfileRecommendationPrompt(User $user, array $stats): string
    {
        $bio = $user->getBio() ? "Bio actuelle : {$user->getBio()}" : 'Aucune bio fournie.';
        $company = $user->getCompanyName() ? "Entreprise : {$user->getCompanyName()}" : 'Entreprise non précisée.';
        return <<<PROMPT
Tu es un coach IA de carrière pour Najahni. Donne quatre recommandations pratiques à cet utilisateur afin d'améliorer son profil, augmenter sa visibilité et mieux tirer parti de la plateforme.
Informations du profil :
- Nom: {$user->getFullName()}
- Rôle: {$user->getRole()}
- {$company}
- {$bio}
- Publications: {$stats['posts']}
- Commentaires: {$stats['comments']}
- Groupes: {$stats['groups']}
- Projets: {$stats['projets']}

Réponds en français avec un paragraphe clair pour chaque recommandation. Ne renvoie pas de JSON.
PROMPT;
    }

    /** @return array<string, int> */
    private function getUserStats(User $user): array
    {
        $conn = $this->doctrine->getConnection();
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

    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, SluggerInterface $slugger, ValidatorInterface $validator): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $oldEmail = $user->getEmail();
            $oldPhone = $user->getPhone();

            $user->setFirstname(trim($request->request->get('firstname', '')));
            $user->setLastname(trim($request->request->get('lastname', '')));
            $newEmail = trim($request->request->get('email', ''));
            if ($newEmail !== $oldEmail) {
                $user->setEmail($newEmail);
                $user->setVerified(false);
            }
            $newPhone = $request->request->get('phone');
            if ($newPhone !== $oldPhone) {
                $user->setPhone($newPhone);
                $user->setPhoneVerified(false);
            }
            $user->setBio($request->request->get('bio'));
            $user->setCompanyName($request->request->get('company_name'));
            $user->setLinkedinUrl($request->request->get('linkedin_url'));
            $user->setAddress($request->request->get('address'));
            $user->setPreferredLanguage($request->request->get('preferred_language', 'fr'));
            $user->setPreferredTheme($request->request->get('preferred_theme', 'light'));

            $dob = $request->request->get('date_of_birth');
            if ($dob) {
                $user->setDateOfBirth(new \DateTime($dob));
            }

            $file = $request->files->get('profile_picture');
            if ($file) {
                $safeName = $slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                $newFilename = $safeName . '-' . uniqid() . '.' . $file->guessExtension();
                try {
                    $file->move($this->getParameter('kernel.project_dir') . '/public/uploads/profiles', $newFilename);
                    $user->setProfilePicture('/uploads/profiles/' . $newFilename);
                } catch (FileException $e) {
                    $this->addFlash('danger', 'Erreur lors du téléchargement de l\'image.');
                }
            }

            $errors = $validator->validate($user);
            if (count($errors) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('front/profile/edit.html.twig', ['fieldErrors' => $fieldErrors]);
            }

            $em->flush();
            $this->addFlash('success', 'Profil mis à jour avec succès !');
            return $this->redirectToRoute('app_profile');
        }

        return $this->render('front/profile/edit.html.twig');
    }

    #[Route('/profile/password', name: 'app_profile_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        EmailService $emailService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $current = $request->request->get('current_password');
        $new = $request->request->get('new_password');
        $confirm = $request->request->get('confirm_password');

        if (!$hasher->isPasswordValid($user, $current)) {
            $this->addFlash('danger', 'Mot de passe actuel incorrect.');
            return $this->redirectToRoute('app_profile_edit');
        }
        if ($new !== $confirm) {
            $this->addFlash('danger', 'Les mots de passe ne correspondent pas.');
            return $this->redirectToRoute('app_profile_edit');
        }
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{6,}$/', $new)) {
            $this->addFlash('danger', 'Le mot de passe doit contenir au moins 6 caractères, une majuscule, une minuscule et un chiffre.');
            return $this->redirectToRoute('app_profile_edit');
        }

        $user->setPassword($hasher->hashPassword($user, $new));
        $em->flush();

        try {
            $emailService->sendPasswordChangeConfirmation($user->getEmail(), $user->getFirstname());
        } catch (\Exception $e) {}

        $this->addFlash('success', 'Mot de passe modifié avec succès !');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/verify-email', name: 'app_profile_verify_email', methods: ['POST'])]
    public function verifyEmailFromProfile(
        Request $request,
        EntityManagerInterface $em,
        EmailService $emailService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $isAjax = $request->isXmlHttpRequest();

        $action = $request->request->get('action');
        if ($action === 'send') {
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $user->setVerificationCode($code);
            $user->setVerificationCodeExpiresAt(new \DateTime('+5 minutes'));
            $em->flush();

            try {
                $emailService->sendVerificationCode($user->getEmail(), $code, $user->getFirstname());
                if ($isAjax) {
                    return new JsonResponse(['success' => true, 'message' => 'Code envoyé à ' . $user->getEmail()]);
                }
                $this->addFlash('success', 'Code de vérification envoyé à ' . $user->getEmail());
            } catch (\Exception $e) {
                if ($isAjax) {
                    return new JsonResponse(['success' => false, 'message' => 'Erreur lors de l\'envoi de l\'email.'], 500);
                }
                $this->addFlash('danger', 'Erreur lors de l\'envoi de l\'email.');
            }
        } elseif ($action === 'verify') {
            $code = $request->request->get('code', '');
            if ($code === $user->getVerificationCode()
                && $user->getVerificationCodeExpiresAt() > new \DateTime()) {
                $user->setVerified(true);
                $user->setVerificationCode(null);
                $user->setVerificationCodeExpiresAt(null);
                $em->flush();
                if ($isAjax) {
                    return new JsonResponse(['success' => true, 'message' => 'Email vérifié avec succès !']);
                }
                $this->addFlash('success', 'Email vérifié avec succès !');
            } else {
                if ($isAjax) {
                    return new JsonResponse(['success' => false, 'message' => 'Code incorrect ou expiré.'], 400);
                }
                $this->addFlash('danger', 'Code incorrect ou expiré.');
            }
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/notifications', name: 'app_profile_notifications')]
    public function notifications(NotificationRepository $notifRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        return $this->render('front/profile/notifications.html.twig', [
            'notifications' => $notifRepo->findByUser($user->getId(), 50),
        ]);
    }

    #[Route('/profile/notifications/read-all', name: 'app_profile_notifications_read_all', methods: ['POST'])]
    public function markAllNotificationsRead(NotificationRepository $notifRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $notifRepo->markAllAsRead($user->getId());
        $this->addFlash('success', 'Toutes les notifications marquées comme lues.');
        return $this->redirectToRoute('app_profile_notifications');
    }

    #[Route('/profile/login-history', name: 'app_profile_login_history')]
    public function loginHistory(LoginHistoryRepository $loginHistoryRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        return $this->render('front/profile/login_history.html.twig', [
            'history' => $loginHistoryRepo->findByUser($user->getId(), 50),
        ]);
    }
}
