<?php

namespace App\Security;

use App\Entity\LoginHistory;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $em,
        private RouterInterface $router,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $hasher,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_google_callback';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client, $request) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $googleId = $googleUser->getId();
                $email = $googleUser->getEmail();

                // 1. Check by Google ID
                $user = $this->userRepository->findOneBy(['googleProviderId' => $googleId]);
                if ($user) {
                    return $this->handleGoogleLogin($user, $request);
                }

                // 2. Check by email — link Google account
                $user = $this->userRepository->findOneBy(['email' => $email]);
                if ($user) {
                    $user->setGoogleProviderId($googleId);
                    $this->em->flush();
                    return $this->handleGoogleLogin($user, $request);
                }

                // 3. Create new user
                $user = new User();
                $user->setEmail($email);
                $user->setFirstname($googleUser->getFirstName() ?? '');
                $user->setLastname($googleUser->getLastName() ?? '');
                $user->setGoogleProviderId($googleId);
                $user->setProfilePicture($googleUser->getAvatar());
                $user->setRole(User::ROLE_ENTREPRENEUR);
                $user->setVerified(true);
                $user->setIsActive(true);
                $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(16))));

                $this->em->persist($user);
                $this->em->flush();

                $this->recordLogin($user, $request);
                return $user;
            })
        );
    }

    private function handleGoogleLogin(User $user, Request $request): User
    {
        if ($user->getIsBanned()) {
            throw new AuthenticationException('Votre compte a été banni.');
        }
        $user->resetLoginAttempts();
        $this->em->flush();
        $this->recordLogin($user, $request);
        return $user;
    }

    private function recordLogin(User $user, Request $request): void
    {
        $history = new LoginHistory();
        $history->setUser($user);
        $history->setIpAddress($request->getClientIp());
        $history->setDeviceInfo($request->headers->get('User-Agent', 'Unknown'));
        $history->setLoginMethod('GOOGLE');
        $history->setSuccess(true);
        $history->setLocation('Web');
        $this->em->persist($history);
        $this->em->flush();
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User $user */
        $user = $token->getUser();

        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return new RedirectResponse($this->router->generate('admin_dashboard'));
        }

        return new RedirectResponse($this->router->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
        $session = $request->getSession();
        $session->getFlashBag()->add('danger', 'Échec de la connexion Google: ' . $exception->getMessage());
        return new RedirectResponse($this->router->generate('app_login'));
    }
}
