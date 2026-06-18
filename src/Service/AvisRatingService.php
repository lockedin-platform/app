<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AvisRatingService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $groqApiKey,
        private readonly string $groqUrl,
        private readonly string $groqModel
    ) {
    }

    public function rateAvis(string $avis): string
    {
        $avis = trim($avis);
        if ($avis === '') {
            return '3.0';
        }

        if ($this->groqApiKey === '') {
            $this->logger->warning('GROQ_API_KEY is empty, fallback rating used.');

            return '3.0';
        }

        try {
            $response = $this->httpClient->request('POST', $this->groqUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->groqApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->groqModel,
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Tu es un evaluateur d\'avis. Note l\'avis de 1 a 5 etoiles. Reponds uniquement avec un entier entre 1 et 5.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Avis: \n\n" . $avis,
                        ],
                    ],
                ],
                'timeout' => 12,
                'verify_peer' => !$this->insecureTlsEnabled(),
                'verify_host' => !$this->insecureTlsEnabled(),
            ]);

            $payload = $response->toArray(false);
            $content = (string) ($payload['choices'][0]['message']['content'] ?? '');

            if (preg_match('/\b([1-5])\b/', $content, $matches) === 1) {
                return $matches[1] . '.0';
            }

            $this->logger->warning('Could not parse Groq rating response.', [
                'response' => $content,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Groq rating request failed.', [
                'error' => $e->getMessage(),
            ]);
        }

        return '3.0';
    }

    /** TLS verification stays ON unless COMMUNITY_GROQ_INSECURE is explicitly set (dev-only). */
    private function insecureTlsEnabled(): bool
    {
        $v = $_SERVER['COMMUNITY_GROQ_INSECURE'] ?? $_ENV['COMMUNITY_GROQ_INSECURE'] ?? getenv('COMMUNITY_GROQ_INSECURE');
        return in_array(mb_strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }
}
