<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Lightweight fixed-window rate limiter built on Symfony's existing cache pool —
 * NO extra package needed, active immediately. Protects expensive/abusable endpoints
 * (AI chat -> external LLM cost/quota; login -> brute force).
 *
 * Fail-open by design: if the cache misbehaves it never blocks a legitimate request.
 */
class SimpleRateLimiter
{
    public function __construct(private readonly CacheItemPoolInterface $cache) {}

    /**
     * @return bool true if the caller has EXCEEDED the limit (block the request).
     */
    public function tooManyAttempts(string $key, int $max, int $windowSeconds): bool
    {
        try {
            $cacheKey = 'ratelimit_' . preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
            $item = $this->cache->getItem($cacheKey);
            $count = (int) $item->get();

            if ($count >= $max) {
                return true;
            }

            $item->set($count + 1);
            $item->expiresAfter($windowSeconds);
            $this->cache->save($item);

            return false;
        } catch (\Throwable) {
            return false; // never block because of a limiter/cache error
        }
    }
}
