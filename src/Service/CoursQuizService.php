<?php

namespace App\Service;

use App\Entity\Cours;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CoursQuizService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $groqApiKey,
        private readonly string $groqUrl,
        private readonly string $groqModel
    ) {
    }

    /**
     * @return array<int, array{question:string, options:array<int, string>, answer_index:int}>
     */
    public function generateQuizForCours(Cours $cours): array
    {
        if ($this->groqApiKey === '') {
            $this->logger->warning('GROQ_API_KEY is empty, fallback quiz used.');

            return $this->buildFallbackQuiz($cours);
        }

        try {
            $response = $this->httpClient->request('POST', $this->groqUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->groqApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->groqModel,
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Tu generes un quiz pedagogique en francais. Retourne uniquement un JSON valide.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->buildPrompt($cours),
                        ],
                    ],
                ],
                'timeout' => 18,
                'verify_peer' => !$this->insecureTlsEnabled(),
                'verify_host' => !$this->insecureTlsEnabled(),
            ]);

            $payload = $response->toArray(false);
            $content = (string) ($payload['choices'][0]['message']['content'] ?? '');
            $parsed = $this->extractQuizFromResponse($content);

            if ($parsed !== []) {
                return $parsed;
            }

            $this->logger->warning('Could not parse quiz JSON from Groq response.', [
                'response' => $content,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Groq quiz request failed.', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->buildFallbackQuiz($cours);
    }

    /** TLS verification stays ON unless COMMUNITY_GROQ_INSECURE is explicitly set (dev-only). */
    private function insecureTlsEnabled(): bool
    {
        $v = $_SERVER['COMMUNITY_GROQ_INSECURE'] ?? $_ENV['COMMUNITY_GROQ_INSECURE'] ?? getenv('COMMUNITY_GROQ_INSECURE');
        return in_array(mb_strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    private function buildPrompt(Cours $cours): string
    {
        $description = trim((string) $cours->getDescription());
        if ($description === '') {
            $description = 'Aucune description detaillee fournie.';
        }

        return sprintf(
            "Cours: %s\nCategorie: %s\nNiveau: %s\nDescription: %s\n\nGenere entre 3 et 5 questions (QCM) sur ce cours, pour entrainement.\nContraintes JSON strictes:\n{\n  \"questions\": [\n    {\n      \"question\": \"...\",\n      \"options\": [\"...\", \"...\", \"...\", \"...\"],\n      \"answer_index\": 0\n    }\n  ]\n}\nanswer_index doit etre un entier entre 0 et 3.\nAucune explication hors JSON.",
            (string) $cours->getTitre(),
            $cours->getCategorie(),
            $cours->getNiveauDifficulte(),
            $description
        );
    }

    /**
     * @return array<int, array{question:string, options:array<int, string>, answer_index:int}>
     */
    private function extractQuizFromResponse(string $content): array
    {
        $json = trim($content);
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/', '', $json) ?? $json;
            $json = preg_replace('/\s*```$/', '', $json) ?? $json;
            $json = trim($json);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['questions']) || !is_array($decoded['questions'])) {
            return [];
        }

        $normalized = [];
        foreach ($decoded['questions'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $options = $item['options'] ?? null;
            $answerIndex = $item['answer_index'] ?? null;

            if ($question === '' || !is_array($options) || count($options) < 2 || !is_numeric($answerIndex)) {
                continue;
            }

            $options = array_values(array_map(static fn($opt) => trim((string) $opt), $options));
            $answerIndex = (int) $answerIndex;

            if ($answerIndex < 0 || $answerIndex >= count($options)) {
                continue;
            }

            $normalized[] = [
                'question' => $question,
                'options' => array_slice($options, 0, 4),
                'answer_index' => min($answerIndex, 3),
            ];
        }

        if (count($normalized) < 3) {
            return [];
        }

        return array_slice($normalized, 0, 5);
    }

    /**
     * @return array<int, array{question:string, options:array<int, string>, answer_index:int}>
     */
    private function buildFallbackQuiz(Cours $cours): array
    {
        return [
            [
                'question' => sprintf('Quel est le theme principal du cours "%s" ?', (string) $cours->getTitre()),
                'options' => [
                    'La redaction d\'un plan structure',
                    'La cuisine professionnelle',
                    'Le montage video avance',
                    'La maintenance automobile',
                ],
                'answer_index' => 0,
            ],
            [
                'question' => 'Quel element est essentiel dans un bon business plan ?',
                'options' => [
                    'Une analyse du marche',
                    'Une liste de films preferes',
                    'Des blagues uniquement',
                    'Aucun objectif clair',
                ],
                'answer_index' => 0,
            ],
            [
                'question' => 'Pourquoi suivre ce cours peut aider un entrepreneur ?',
                'options' => [
                    'Pour structurer ses idees et convaincre',
                    'Pour eviter toute planification',
                    'Pour remplacer son produit',
                    'Pour ignorer les clients',
                ],
                'answer_index' => 0,
            ],
        ];
    }
}
