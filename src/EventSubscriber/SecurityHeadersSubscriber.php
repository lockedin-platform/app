<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds standard hardening headers to every response.
 *
 * Deliberately conservative — only headers that can't break the existing UI:
 *   - X-Content-Type-Options: nosniff   (stop MIME sniffing)
 *   - X-Frame-Options: SAMEORIGIN       (clickjacking protection)
 *   - Referrer-Policy                    (don't leak full URLs cross-origin)
 *
 * NOT setting a strict Content-Security-Policy or Permissions-Policy here: the app uses
 * inline styles, CDN assets, and the camera (Face ID / future pitch analysis), so a strict
 * policy would break it. Those should be introduced deliberately + tested (see audit doc).
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        // don't override anything already set
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
    }
}
