<?php

namespace App\Service\Support;

/**
 * Shared TLS verification policy for outbound API calls.
 *
 * Verification is ON by default (secure). It is only disabled when the explicit
 * COMMUNITY_GROQ_INSECURE env flag is truthy — a dev-only escape hatch for machines
 * with a broken local CA bundle. Production (real CA certs) stays verified, so API
 * keys / data are never sent over an unverified channel (MITM protection).
 */
final class Tls
{
    /** @return bool true = verify the peer/host (secure). */
    public static function verify(): bool
    {
        $v = $_SERVER['COMMUNITY_GROQ_INSECURE'] ?? $_ENV['COMMUNITY_GROQ_INSECURE'] ?? getenv('COMMUNITY_GROQ_INSECURE');
        return !in_array(mb_strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }
}
