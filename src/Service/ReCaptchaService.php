<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReCaptchaService
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const PLACEHOLDER_VALUES = [
        '',
        'your_recaptcha_secret_key',
        'your_recaptcha_site_key',
    ];

    // Google's official test keys (always pass)
    private const TEST_SECRET_KEY = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe'; // @phpstan-ignore classConstant.unused

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $secretKey,
        private float $scoreThreshold = 0.5,
        private bool $enabled = true,
    ) {}

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        if (empty($token)) {
            return false;
        }

        try {
            $params = [
                'secret' => $this->secretKey,
                'response' => $token,
            ];

            if ($remoteIp) {
                $params['remoteip'] = $remoteIp;
            }

            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => $params,
            ]);

            $data = $response->toArray();

            if (empty($data['success'])) {
                $this->logger->warning('reCAPTCHA verification failed', [
                    'error-codes' => $data['error-codes'] ?? [],
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('reCAPTCHA verification error: ' . $e->getMessage());
            // Fail open in case of API issues to not block users
            return true;
        }
    }

    public function isEnabled(): bool
    {
        if (!$this->enabled || $this->isPlaceholderValue($this->secretKey)) {
            return false;
        }
        return true;
    }

    private function isPlaceholderValue(string $value): bool
    {
        return in_array(trim($value), self::PLACEHOLDER_VALUES, true);
    }
}
