<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AnalyticsService;
use App\Service\EmailService;
use App\Service\ReCaptchaService;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error' => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        EmailService $emailService,
        ReCaptchaService $reCaptchaService,
        AnalyticsService $analytics,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            // Verify reCAPTCHA
            $recaptchaToken = $request->request->get('g-recaptcha-response', '');
            if (!$reCaptchaService->verify($recaptchaToken, $request->getClientIp())) {
                $this->addFlash('danger', 'Vérification reCAPTCHA échouée. Veuillez réessayer.');
                return $this->render('security/register.html.twig', ['user' => new User()]);
            }

            $user = new User();
            $user->setFirstname(trim($request->request->get('firstname', '')));
            $user->setLastname(trim($request->request->get('lastname', '')));
            $user->setEmail(trim($request->request->get('email', '')));
            $user->setPhone($request->request->get('phone') ?: null);
            $user->setRole($request->request->get('role', User::ROLE_ENTREPRENEUR));

            $plainPassword = $request->request->get('password', '');
            $confirmPassword = $request->request->get('confirm_password', '');

            // Validation
            $fieldErrors = [];
            if ($plainPassword !== $confirmPassword) {
                $fieldErrors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
                return $this->render('security/register.html.twig', ['user' => $user, 'fieldErrors' => $fieldErrors]);
            }

            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{6,}$/', $plainPassword)) {
                $fieldErrors['password'] = 'Le mot de passe doit contenir au moins 6 caractères, une majuscule, une minuscule et un chiffre.';
                return $this->render('security/register.html.twig', ['user' => $user, 'fieldErrors' => $fieldErrors]);
            }

            // Check role - prevent self-registration as admin
            if ($user->getRole() === User::ROLE_ADMIN) {
                $user->setRole(User::ROLE_ENTREPRENEUR);
            }

            $user->setPassword($hasher->hashPassword($user, $plainPassword));

            $errors = $validator->validate($user);
            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('security/register.html.twig', ['user' => $user, 'fieldErrors' => $fieldErrors]);
            }

            // Generate verification code
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $user->setVerificationCode($code);
            $user->setVerificationCodeExpiresAt(new \DateTime('+5 minutes'));

            $em->persist($user);
            $em->flush();
            $analytics->logUserRegistered($user);

            // Send verification email
            try {
                $emailService->sendVerificationCode($user->getEmail(), $code, $user->getFirstname());
            } catch (\Exception $e) {
                // Continue even if mail fails
            }

            // Store user ID in session for verification
            $request->getSession()->set('verify_user_id', $user->getId());

            return $this->redirectToRoute('app_verify_email');
        }

        return $this->render('security/register.html.twig', ['user' => new User()]);
    }

    #[Route('/verify-email', name: 'app_verify_email')]
    public function verifyEmail(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        EmailService $emailService,
    ): Response {
        $userId = $request->getSession()->get('verify_user_id');
        if (!$userId) {
            return $this->redirectToRoute('app_login');
        }

        $user = $userRepo->find($userId);
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'verify') {
                $code = $request->request->get('code', '');
                if ($code === $user->getVerificationCode()
                    && $user->getVerificationCodeExpiresAt() > new \DateTime()) {
                    $user->setVerified(true);
                    $user->setVerificationCode(null);
                    $user->setVerificationCodeExpiresAt(null);
                    $em->flush();

                    try {
                        $emailService->sendWelcomeEmail($user->getEmail(), $user->getFirstname());
                    } catch (\Exception $e) {}

                    $request->getSession()->remove('verify_user_id');
                    $this->addFlash('success', 'Email vérifié avec succès ! Connectez-vous maintenant.');
                    return $this->redirectToRoute('app_login');
                }
                $this->addFlash('danger', 'Code incorrect ou expiré.');
            } elseif ($action === 'resend') {
                $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                $user->setVerificationCode($code);
                $user->setVerificationCodeExpiresAt(new \DateTime('+5 minutes'));
                $em->flush();

                try {
                    $emailService->sendVerificationCode($user->getEmail(), $code, $user->getFirstname());
                    $this->addFlash('success', 'Nouveau code envoyé par email.');
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Erreur lors de l\'envoi de l\'email.');
                }
            } elseif ($action === 'skip') {
                $request->getSession()->remove('verify_user_id');
                $this->addFlash('success', 'Compte créé ! Vous pouvez vérifier votre email plus tard.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/verify_email.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        EmailService $emailService,
    ): Response {
        if ($request->isMethod('POST')) {
            $email = trim($request->request->get('email', ''));
            $user = $userRepo->findOneBy(['email' => $email]);

            if (!$user) {
                $this->addFlash('danger', 'Aucun compte trouvé avec cet email.');
                return $this->render('security/forgot_password.html.twig');
            }

            // Generate 4-digit code
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $user->setResetToken($code);
            $user->setResetTokenExpiresAt(new \DateTime('+5 minutes'));
            $em->flush();

            try {
                $emailService->sendPasswordResetCode($email, $code, $user->getFirstname());
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Erreur lors de l\'envoi de l\'email.');
                return $this->render('security/forgot_password.html.twig');
            }

            $request->getSession()->set('reset_email', $email);
            return $this->redirectToRoute('app_reset_code');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/reset-code', name: 'app_reset_code')]
    public function resetCode(
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        EmailService $emailService,
    ): Response {
        $email = $request->getSession()->get('reset_email');
        if (!$email) {
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action', 'verify');
            $user = $userRepo->findOneBy(['email' => $email]);

            if (!$user) {
                return $this->redirectToRoute('app_forgot_password');
            }

            if ($action === 'resend') {
                $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                $user->setResetToken($code);
                $user->setResetTokenExpiresAt(new \DateTime('+5 minutes'));
                $em->flush();

                try {
                    $emailService->sendPasswordResetCode($email, $code, $user->getFirstname());
                    $this->addFlash('success', 'Nouveau code envoyé.');
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Erreur lors de l\'envoi.');
                }
            } else {
                $code = $request->request->get('code', '');
                if ($code === $user->getResetToken()
                    && $user->getResetTokenExpiresAt() > new \DateTime()) {
                    $request->getSession()->set('reset_validated', true);
                    return $this->redirectToRoute('app_reset_password');
                }
                $this->addFlash('danger', 'Code incorrect ou expiré.');
            }
        }

        return $this->render('security/reset_code.html.twig');
    }

    #[Route('/reset-password', name: 'app_reset_password')]
    public function resetPassword(
        Request $request,
        UserRepository $userRepo,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        EmailService $emailService,
    ): Response {
        $email = $request->getSession()->get('reset_email');
        $validated = $request->getSession()->get('reset_validated');
        if (!$email || !$validated) {
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $user = $userRepo->findOneBy(['email' => $email]);
            if (!$user) {
                return $this->redirectToRoute('app_forgot_password');
            }

            $password = $request->request->get('password', '');
            $confirm = $request->request->get('confirm_password', '');

            if ($password !== $confirm) {
                return $this->render('security/reset_password.html.twig', [
                    'fieldErrors' => ['confirm_password' => 'Les mots de passe ne correspondent pas.'],
                ]);
            }

            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{6,}$/', $password)) {
                return $this->render('security/reset_password.html.twig', [
                    'fieldErrors' => ['password' => 'Le mot de passe doit contenir au moins 6 caractères, une majuscule, une minuscule et un chiffre.'],
                ]);
            }

            $user->setPassword($hasher->hashPassword($user, $password));
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);
            $user->setIsActive(true);
            $user->setIsBanned(false);
            $user->resetLoginAttempts();
            $em->flush();

            try {
                $emailService->sendPasswordChangeConfirmation($email, $user->getFirstname());
            } catch (\Exception $e) {}

            $request->getSession()->remove('reset_email');
            $request->getSession()->remove('reset_validated');

            $this->addFlash('success', 'Mot de passe réinitialisé avec succès ! Connectez-vous avec votre nouveau mot de passe.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig');
    }

    #[Route('/connect/google', name: 'app_google_connect')]
    public function connectGoogle(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry->getClient('google')->redirect(
            ['openid', 'email', 'profile'],
            []
        );
    }

    #[Route('/connect/google/callback', name: 'app_google_callback')]
    public function googleCallback(): Response
    {
        // This is handled by GoogleAuthenticator
        return $this->redirectToRoute('app_home');
    }

    #[Route('/face-login', name: 'app_face_login')]
    public function faceLogin(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/face_login.html.twig');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
