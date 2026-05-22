<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class CommunityTextModerationService
{
    /** @var string[] */
    private array $fallbackWords = [
        'fuck',
        'shit',
        'bitch',
        'asshole',
        'bastard',
        'merde',
        'putain',
        'salope',
        'conard',
        'conasse',
        'puta',
    ];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /** @return array{text: string, changed: bool} */
    public function moderate(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['text' => '', 'changed' => false];
        }

        $remote = $this->remoteModerate($text);
        $moderated = $remote ?? $this->localModerate($text);

        return [
            'text' => $moderated,
            'changed' => $moderated !== $text,
        ];
    }

    private function remoteModerate(string $text): ?string
    {
        try {
            $response = $this->httpClient->request('GET', 'https://www.purgomalum.com/service/plain', [
                'query' => ['text' => $text],
                'timeout' => 3,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return null;
            }

            $content = trim($response->getContent(false));

            return $content !== '' ? $content : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function localModerate(string $text): string
    {
        $pattern = '/\b('.implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), $this->fallbackWords)).')\b/i';

        return preg_replace_callback($pattern, static function (array $matches): string {
            $word = $matches[0];
            $length = strlen($word);

            if ($length <= 2) {
                return str_repeat('*', $length);
            }

            return substr($word, 0, 1).str_repeat('*', max(1, $length - 2)).substr($word, -1);
        }, $text) ?? $text;
    }
}