<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client to the Python FastAPI ML service (ml-service/) that serves the trained models:
 *   /score  -> Model 6 (Deal Outcome) — success probability 0-100
 *   /detect -> Model 3 (Fraud/Quality) — anomaly check
 *
 * Fail-soft: if the ML service is down/slow it returns null and the caller falls back to the
 * rule-based scores, so the website never depends on the Python process being up.
 * URL via ML_SERVICE_URL env (default http://127.0.0.1:8001).
 */
class MlScoreClient
{
    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        $url = $_ENV['ML_SERVICE_URL'] ?? getenv('ML_SERVICE_URL') ?: 'http://127.0.0.1:8001';
        $this->baseUrl = rtrim((string) $url, '/');
    }

    /** @param array<string,mixed> $features @return array<string,mixed>|null */
    public function score(array $features): ?array
    {
        return $this->call('/score', $features);
    }

    /** @param array<string,mixed> $features @return array<string,mixed>|null */
    public function detect(array $features): ?array
    {
        return $this->call('/detect', $features);
    }

    /** @param array<string,mixed> $features @return array<string,mixed>|null */
    private function call(string $path, array $features): ?array
    {
        try {
            return $this->httpClient->request('POST', $this->baseUrl . $path, [
                'json' => $features,
                'timeout' => 3,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->info('ML service ' . $path . ' unavailable: ' . $e->getMessage());
            return null;
        }
    }
}
