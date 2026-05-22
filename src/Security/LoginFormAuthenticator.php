<?php

namespace App\Security;

use App\Entity\LoginHistory;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ReCaptchaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const MAX_LOGIN_ATTEMPTS = 3;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private ReCaptchaService $reCaptchaService,
    ) {}

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('_username', '');
        $password = $request->request->get('_password', '');

        // Verify reCAPTCHA v2 checkbox
        $recaptchaToken = $request->request->get('g-recaptcha-response', '');
        if (!$this->reCaptchaService->verify($recaptchaToken, $request->getClientIp())) {
            throw new CustomUserMessageAuthenticationException('Vérification reCAPTCHA échouée. Veuillez réessayer.');
        }

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, function (string $userIdentifier) {
                $user = $this->userRepository->findOneBy(['email' => $userIdentifier]);
                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('Email ou mot de passe incorrect.');
                }
                if ($user->getIsBanned()) {
                    throw new CustomUserMessageAuthenticationException('Votre compte a été banni. Contactez l\'administration.');
                }
                if (!$user->getIsActive()) {
                    throw new CustomUserMessageAuthenticationException('Votre compte est désactivé. Réinitialisez votre mot de passe pour le réactiver.');
                }
                return $user;
            }),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User $user */
        $user = $token->getUser();

        // Reset login attempts on success
        $user->resetLoginAttempts();
        $this->em->flush();

        // Record successful login
        $this->recordLogin($user, $request, 'PASSWORD', true);

        // Redirect admin to admin dashboard
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $email = $request->request->get('_username', '');
        $user = $this->userRepository->findOneBy(['email' => $email]);

        if ($user && !$user->getIsBanned()) {
            $user->incrementLoginAttempts();

            // Record failed login
            $this->recordLogin($user, $request, 'PASSWORD', false);

            // Lock account after max attempts (except admin)
            if ($user->getLoginAttempts() >= self::MAX_LOGIN_ATTEMPTS && $user->getRole() !== User::ROLE_ADMIN) {
                $user->setIsActive(false);
                $user->setIsBanned(true);
                $this->em->flush();

                $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);
                /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
                $session = $request->getSession();
                $session->getFlashBag()->add('danger',
                    'Votre compte a été verrouillé après ' . self::MAX_LOGIN_ATTEMPTS . ' tentatives échouées. Réinitialisez votre mot de passe pour le débloquer.'
                );
                return new RedirectResponse($this->urlGenerator->generate('app_login'));
            }

            $this->em->flush();
            $remaining = self::MAX_LOGIN_ATTEMPTS - $user->getLoginAttempts();
            if ($remaining > 0 && $remaining < self::MAX_LOGIN_ATTEMPTS) {
                /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
                $session = $request->getSession();
                $session->getFlashBag()->add('warning',
                    "Mot de passe incorrect. Il vous reste {$remaining} tentative(s)."
                );
            }
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }

    private function recordLogin(User $user, Request $request, string $method, bool $success): void
    {
        $history = new LoginHistory();
        $history->setUser($user);
        $history->setIpAddress($request->getClientIp());
        $history->setDeviceInfo($request->headers->get('User-Agent', 'Unknown'));
        $history->setLoginMethod($method);
        $history->setSuccess($success);
        $history->setLocation('Web');
        $this->em->persist($history);
        $this->em->flush();
    }
}
