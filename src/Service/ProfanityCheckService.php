<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ProfanityCheckService
{
    /** @phpstan-ignore property.onlyWritten */
    private readonly LoggerInterface $logger;
    /** @phpstan-ignore property.onlyWritten */
    private readonly string $rapidApiKey;
    /** @phpstan-ignore property.onlyWritten */
    private readonly string $rapidApiHost;
    /** @phpstan-ignore property.onlyWritten */
    private readonly string $rapidApiUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        LoggerInterface $logger,
        string $rapidApiKey,
        string $rapidApiHost,
        string $rapidApiUrl
    ) {
        $this->logger = $logger;
        $this->rapidApiKey = $rapidApiKey;
        $this->rapidApiHost = $rapidApiHost;
        $this->rapidApiUrl = $rapidApiUrl;
    }

    public function containsProfanity(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        // Try direct PurgoMalum API first (free, no key needed)
        $response = $this->httpClient->request('GET', 'https://www.purgomalum.com/service/containsprofanity', [
            'query' => ['text' => $text],
            'timeout' => 10,
            'verify_peer' => false,
            'verify_host' => false,
        ]);

        $result = trim($response->getContent(false));

        return strtolower($result) === 'true';
    }
}
