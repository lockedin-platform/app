<?php

namespace App\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class CommunityPostTranslationService
{
    private const ENDPOINT = 'https://api.mymemory.translated.net/get';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /** @return array<string, mixed> */
    public function translate(string $text, string $target): array
    {
        $text = trim($text);
        $target = strtolower(trim($target));

        if (!in_array($target, ['original', 'fr', 'en', 'ar'], true)) {
            throw new RuntimeException('Unsupported translation language.');
        }

        $source = $this->detectLanguage($text);

        if ($text === '' || $target === 'original' || $target === $source) {
            return [
                'text' => $text,
                'source' => $source,
                'source_label' => $this->languageLabel($source),
                'target' => $target === 'original' ? $source : $target,
                'target_label' => $target === 'original' ? $this->languageLabel($source) : $this->languageLabel($target),
                'is_original' => true,
            ];
        }

        try {
            $payload = $this->fetchTranslationPayload($text, $source, $target);
            $translated = trim((string) ($payload['responseData']['translatedText'] ?? ''));
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Translation is unavailable right now.', 0, $exception);
        }

        if ($translated === '') {
            throw new RuntimeException('Translation is unavailable right now.');
        }

        $translated = html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $translated = str_replace(['<br>', '<br/>', '<br />'], "\n", $translated);

        return [
            'text' => $translated,
            'source' => $source,
            'source_label' => $this->languageLabel($source),
            'target' => $target,
            'target_label' => $this->languageLabel($target),
            'is_original' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function fetchTranslationPayload(string $text, string $source, string $target): array
    {
        $options = [
            'query' => [
                'q' => $text,
                'langpair' => $source.'|'.$target,
            ],
            'timeout' => 10,
        ];

        try {
            return $this->decodeResponse('GET', self::ENDPOINT, $options);
        } catch (Throwable $exception) {
            if (!$this->isCertificateIssue($exception)) {
                throw $exception;
            }

            $options['verify_peer'] = false;
            $options['verify_host'] = false;

            return $this->decodeResponse('GET', self::ENDPOINT, $options);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function decodeResponse(string $method, string $url, array $options): array
    {
        $response = $this->httpClient->request($method, $url, $options);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Translation is unavailable right now.');
        }

        $payload = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw new RuntimeException('Translation is unavailable right now.');
        }

        return $payload;
    }

    private function isCertificateIssue(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'certificate')
            || str_contains($message, 'issuer')
            || str_contains($message, 'ssl')
            || str_contains($message, 'tls');
    }

    private function detectLanguage(string $text): string
    {
        if ($text === '') {
            return 'en';
        }

        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'ar';
        }

        $lower = mb_strtolower($text);

        $frenchScore = 0;
        foreach ([
            'bonjour',
            'salut',
            'merci',
            'je',
            'tu',
            'nous',
            'vous',
            'ils',
            'elles',
            'le',
            'la',
            'les',
            'un',
            'une',
            'des',
            'du',
            'de',
            'dans',
            'avec',
            'sans',
            'pour',
            'sur',
            'entre',
            'mais',
            'est',
            'sont',
            'pas',
            'bonjour',
            'projet',
            'presentation',
            'atelier',
            'mentorat',
            'communaute',
            'communautaire',
            'discussion',
            'resume',
            'suggestion',
            'reponse',
            'preparons',
        ] as $word) {
            if (preg_match('/(^|\s)'.preg_quote($word, '/').'(?=\s|$)/u', $lower) === 1) {
                ++$frenchScore;
            }
        }

        $englishScore = 0;
        foreach ([
            'the',
            'and',
            'with',
            'without',
            'for',
            'from',
            'into',
            'this',
            'that',
            'these',
            'those',
            'you',
            'your',
            'we',
            'they',
            'are',
            'is',
            'will',
            'should',
            'project',
            'community',
            'workshop',
            'discussion',
            'summary',
            'reply',
        ] as $word) {
            if (preg_match('/(^|\s)'.preg_quote($word, '/').'(?=\s|$)/u', $lower) === 1) {
                ++$englishScore;
            }
        }

        if (preg_match('/[àâäçéèêëîïôöùûüÿœæ]/u', $lower) === 1) {
            return 'fr';
        }

        if ($frenchScore >= 3 && $frenchScore >= $englishScore) {
            return 'fr';
        }

        if ($englishScore >= 3 && $englishScore > $frenchScore) {
            return 'en';
        }

        if ($frenchScore >= 2 || str_contains($lower, " l'") || str_contains($lower, " d'")) {
            return 'fr';
        }

        return 'en';
    }

    private function languageLabel(string $code): string
    {
        return match ($code) {
            'ar' => 'Arabic',
            'fr' => 'French',
            default => 'English',
        };
    }
}