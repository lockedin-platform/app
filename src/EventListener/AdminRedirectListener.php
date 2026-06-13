<?php

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
class AdminRedirectListener
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Already on admin, login, logout, api, or security pages — leave alone
        if (str_starts_with($path, '/admin') ||
            str_starts_with($path, '/login') ||
            str_starts_with($path, '/logout') ||
            str_starts_with($path, '/api') ||
            str_starts_with($path, '/_') ||
            str_starts_with($path, '/face-') ||
            str_starts_with($path, '/auth')) {
            return;
        }

        $user = $this->security->getUser();

        if ($user && $this->security->isGranted('ROLE_ADMIN')) {
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('admin_dashboard')
            ));
        }
    }
}
