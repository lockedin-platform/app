<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lightweight health/liveness endpoint for uptime monitoring + platform health checks
 * (e.g. Render). Public, fast, no side effects. Returns 200 when healthy, 503 when the DB
 * is unreachable so a monitor can detect outages.
 */
class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(Connection $connection): JsonResponse
    {
        $db = 'ok';
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $db = 'down';
        }

        return new JsonResponse(
            ['status' => $db === 'ok' ? 'ok' : 'degraded', 'db' => $db, 'time' => date('c')],
            $db === 'ok' ? 200 : 503,
        );
    }
}
