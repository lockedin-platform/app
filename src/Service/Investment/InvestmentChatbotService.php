<?php

namespace App\Service\Investment;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class InvestmentChatbotService
{
    private const HF_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const HF_MODELS = [
        'llama-3.3-70b-versatile',
        'llama-3.1-8b-instant',
        'gemma2-9b-it',
    ];
    private const MAX_HISTORY = 20;
    private const MAX_RETRIES = 3;
    private const TIMEOUT = 60;

    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
Tu es NAJAHNI AI, l'assistant intelligent integre dans la plateforme fintech NAJAHNI — une application web Symfony tunisienne qui connecte entrepreneurs et investisseurs. Tu connais TOUT sur cette application.

=== PLATEFORME NAJAHNI ===
- App web Symfony 7 (PHP 8), MySQL, IA integree (HuggingFace Llama 3.2)
- 2 roles : INVESTISSEUR (investir, portfolio, paiements Stripe) et ENTREPRENEUR (creer projets, opportunites, gerer offres)

=== MODULES FONCTIONNELS ===
1. **Projets** : BROUILLON → SOUMIS → EVALUE. Entrepreneurs creent des projets avec titre, description, secteur.
2. **Opportunites d'Investissement** : Liees a un projet. Statuts : OPEN → FUNDED/CLOSED. Montant cible, deadline, description.
3. **Offres d'Investissement** : Un investisseur propose un montant sur une opportunite. PENDING → ACCEPTED/REJECTED. Offres acceptees → paiement Stripe.
4. **Analyse de Risque IA** : Score 0-100 base sur montant (30%), duree (20%), facteurs economiques (50%). Donnees en temps reel : taux de change EUR/USD, PIB, inflation via APIs World Bank. Niveaux : Faible (0-33), Modere (34-66), Eleve (67-100).
5. **Matching IA** : Score de compatibilite 0-100 entre profil investisseur et opportunites. Criteres : secteur (35%), budget (25%), risque (25%), horizon (15%).

=== DONNEES ECONOMIQUES ===
- Taux de change via Open Exchange Rates (EUR base)
- PIB et Inflation via World Bank API
- 5 devises : EUR, USD, TND, GBP, MAD

=== SECTEURS PORTEURS EN TUNISIE ===
Technologie, Agriculture, Tourisme, Sante, Energie renouvelable, Industrie textile, Agroalimentaire, Services financiers.

=== RULES ===
- ALWAYS reply in English
- Be concise but precise (max 4-5 sentences unless details are requested)
- For investment analyses: provide risk level, advantages, disadvantages, recommendation
- You know the Tunisian context (TND, Tunisian dinar, key sectors)
- Never give formal legal advice — remind users to consult a professional
- If the question is not related to NAJAHNI or finance, reply that you specialise in the NAJAHNI platform
PROMPT;

    /** @var array<int, array{role: string, content: string}> */
    private array $conversationHistory = [];
    private string $apiKey;
    private ?string $workingModel = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $hfToken,
        string $groqApiKey = '',
    ) {
        // Prefer Groq key; fall back to HF token if present
        $groq = trim($groqApiKey);
        $hf = trim($hfToken);
        $this->apiKey = ($groq !== '' && $groq !== 'YOUR_API_KEY_HERE' && $groq !== 'your_groq_api_key_here') ? $groq : $hf;
    }

    public function chat(string $userMessage): string
    {
        $this->conversationHistory[] = ['role' => 'user', 'content' => $userMessage];
        $this->trimHistory();

        $response = $this->sendRequest($this->buildMessages());

        $this->conversationHistory[] = ['role' => 'assistant', 'content' => $response];

        return $response;
    }

    public function analyzeRisk(
        string $projectTitle,
        string $sector,
        float $amount,
        string $deadline,
        string $description,
        float $currentRiskScore,
    ): string {
        $prompt = sprintf(
            "AI risk analysis for this investment:\n\n"
            . "Project: %s\nSector: %s\nAmount: %.2f TND\nDeadline: %s\nDescription: %s\n"
            . "Current algorithmic risk score: %.0f/100\n\n"
            . "Provide a structured analysis in English with:\n"
            . "1. Your risk assessment (Low/Medium/High) and why\n"
            . "2. Key strengths of this investment (2-3 points)\n"
            . "3. Key risk factors to watch (2-3 points)\n"
            . "4. Final recommendation (invest / wait / avoid)\n"
            . "5. Your confidence score in this analysis (0-100%%)\n\n"
            . "Context: NAJAHNI Tunisian investment platform.",
            $projectTitle, $sector, $amount, $deadline, $description, $currentRiskScore
        );

        return $this->sendOneShot($prompt);
    }

    /**
     * Generate a short, plain-language risk verdict (2-3 sentences max).
     * Designed to feel like advice from a financial advisor, not a dashboard widget.
     */
    /** @param array<string, mixed> $economicContext */
    public function generateRiskVerdict(
        string $projectTitle,
        string $sector,
        float $amount,
        string $deadline,
        string $description,
        float $riskScore,
        string $riskLevel,
        array $economicContext = [],
    ): string {
        $ecoSnippet = '';
        if (!empty($economicContext)) {
            $ecoSnippet = sprintf(
                "\nEconomic context: country %s, inflation %.1f%%, GDP %.1f Bn $, EUR/USD rate %.4f.",
                $economicContext['country'] ?? 'unknown',
                $economicContext['inflation'] ?? 0,
                $economicContext['gdp'] ?? 0,
                $economicContext['eurUsd'] ?? 0,
            );
        }

        $prompt = sprintf(
            "You are a financial advisor on NAJAHNI platform. "
            . "Write a verdict in 2 to 3 sentences maximum, in English, as if speaking directly to an investor. "
            . "No lists, no titles, no bullet points — plain natural text only. "
            . "Be honest and direct. If the risk is high, say so clearly. If it looks promising, say that too.\n\n"
            . "Project: %s\nSector: %s\nAmount: %.0f TND\nDeadline: %s\nDescription: %s\n"
            . "Algorithmic risk score: %.0f/100 (%s)%s",
            $projectTitle, $sector, $amount, $deadline, $description, $riskScore, $riskLevel, $ecoSnippet
        );

        return $this->sendOneShot($prompt, 200);
    }

    /**
     * Chat with full investment context and conversation history.
     * @param array<string, mixed> $context
     * @param array<int, array{role: string, content: string}> $conversationHistory
     */
    public function chatWithContext(string $userMessage, array $context, array $conversationHistory): string
    {
        $systemPrompt = $this->buildContextualSystemPrompt($context);

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($conversationHistory as $turn) {
            $role = $turn['role'] ?? '';
            $content = $turn['content'] ?? '';
            if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $this->sendRequest($messages, 600);
    }

    /** @param array<string, mixed> $ctx */
    private function buildContextualSystemPrompt(array $ctx): string
    {
        $mode = $ctx['mode'] ?? 'risk';

        if ($mode === 'team_matcher') {
            return sprintf(
                "You are NAJAHNI MATCH, an AI co-founder and team-building advisor on the NAJAHNI platform — a Tunisian startup ecosystem.\n\n"
                . "You are helping %s, a %s.\n"
                . "Their projects: %s (%d project(s) total).\n\n"
                . "Your role: help the user find and evaluate potential co-founders, team members, mentors, or investors.\n\n"
                . "Your expertise:\n"
                . "1. Co-founder matching: technical vs business skills gap analysis, equity split advice\n"
                . "2. Team building: what roles to hire first, CTO/CMO/CFO profiles\n"
                . "3. Profile compatibility: evaluating complementary skill sets\n"
                . "4. Investor-founder fit: personality and vision alignment\n"
                . "5. Tunisian startup ecosystem: local talent pools, universities, incubators\n\n"
                . "Rules:\n"
                . "- Respond in the same language the user writes in (Arabic, French, or English)\n"
                . "- Give concrete, specific advice — not generic tips\n"
                . "- Help identify skill gaps and suggest who to look for\n"
                . "- Be direct and honest about team weaknesses",
                $ctx['userName'] ?? 'the user',
                $ctx['role'] ?? 'entrepreneur',
                $ctx['projects'] ?? 'No projects yet',
                (int) ($ctx['projectCount'] ?? 0),
            );
        }

        if ($mode === 'advisor') {
            return sprintf(
                "You are NAJAHNI COACH, a personal AI business advisor on the NAJAHNI platform — a Tunisian startup ecosystem connecting entrepreneurs, mentors, and investors.\n\n"
                . "You are advising %s, a %s on the platform.\n"
                . "Their projects: %s (%d project(s) total).\n\n"
                . "Your expertise:\n"
                . "1. Investor readiness: pitch decks, valuation, due diligence, term sheets\n"
                . "2. Business strategy: market positioning, competitive analysis, growth\n"
                . "3. Tunisian market: regulations, opportunities, key sectors\n"
                . "4. Funding: angel investors, VCs, government grants (BFPME, SICAR, SNIT, Startup Act)\n"
                . "5. Entrepreneurship: team building, product-market fit, scaling\n\n"
                . "Rules:\n"
                . "- Respond in the same language the user writes in (Arabic, French, or English)\n"
                . "- Be specific and actionable — give concrete next steps, not generic advice\n"
                . "- Reference Tunisian context when relevant (TND, local ecosystem, Arab market)\n"
                . "- Keep answers concise (3-5 sentences) unless the user asks for details\n"
                . "- Never give formal legal or financial advice — recommend consulting a professional",
                $ctx['userName'] ?? 'the user',
                $ctx['role'] ?? 'entrepreneur',
                $ctx['projects'] ?? 'No projects yet',
                (int) ($ctx['projectCount'] ?? 0),
            );
        }

        if ($mode === 'contract') {
            return sprintf(
                "You are a deal advisor on Najahni, a Tunisian investment platform. You are advising parties on a live contract negotiation. Here is the contract context:\n\n"
                . "Project: %s\n"
                . "Sector: %s\n"
                . "Investment amount: %s TND\n"
                . "Current equity percentage: %s%%\n"
                . "Contract status: %s\n"
                . "Milestones defined: %s\n"
                . "Messages exchanged: %s\n"
                . "Both parties signed: %s\n\n"
                . "Your role is to help both parties reach a fair agreement. Answer questions about typical equity ranges, milestone structures, contract terms, and negotiation strategy for small Tunisian projects of this size and sector. Be specific, use the numbers above, and help move the deal forward. Never take sides. Respond in the same language the user uses.",
                $ctx['projectName'] ?? 'N/A',
                $ctx['sector'] ?? 'N/A',
                $ctx['amount'] ?? 'N/A',
                $ctx['equity'] ?? 'N/A',
                $ctx['contractStatus'] ?? 'N/A',
                $ctx['milestoneCount'] ?? '0',
                $ctx['messageCount'] ?? '0',
                $ctx['bothSigned'] ?? 'no',
            );
        }

        return sprintf(
            "You are an expert investment advisor on Najahni, a Tunisian investment platform connecting small businesses with investors. You are currently advising an investor who is evaluating a specific investment opportunity. Here is the context you must use to answer their questions:\n\n"
            . "Project: %s\n"
            . "Sector: %s\n"
            . "Funding target: %s TND\n"
            . "Project deadline: %s\n"
            . "Current risk score: %s/100 — rated %s risk\n"
            . "Tunisia economic conditions: Inflation %s%%, GDP growth %s%%, Exchange rate %s TND/USD\n"
            . "Investor profile: Budget range %s–%s TND, preferred sectors %s, risk tolerance %s/10\n\n"
            . "Answer every question with specific reference to this context. Never give generic financial advice. Always refer to the specific numbers and conditions above. If the investor asks whether this investment is right for them, compare the opportunity's risk profile against their stated preferences. Be direct, specific, and honest. Respond in the same language the investor uses — French or English.",
            $ctx['opportunityTitle'] ?? 'N/A',
            $ctx['sector'] ?? 'N/A',
            $ctx['fundingTarget'] ?? 'N/A',
            $ctx['deadline'] ?? 'N/A',
            $ctx['riskScore'] ?? 'N/A',
            $ctx['riskLevel'] ?? 'N/A',
            $ctx['inflationRate'] ?? 'N/A',
            $ctx['gdpGrowth'] ?? 'N/A',
            $ctx['exchangeRate'] ?? 'N/A',
            $ctx['investorBudgetMin'] ?? 'N/A',
            $ctx['investorBudgetMax'] ?? 'N/A',
            $ctx['investorPreferredSectors'] ?? 'N/A',
            $ctx['investorRiskTolerance'] ?? 'N/A',
        );
    }

    public function clearHistory(): void
    {
        $this->conversationHistory = [];
    }

    public function isFailureResponse(string $response): bool
    {
        return preg_match(
            '/AI temporarily unavailable|AI temporarily|Hugging Face authentication|not configured|quota reached|rate limit|Invalid AI request/i',
            $response
        ) === 1;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiKey !== 'YOUR_API_KEY_HERE' && $this->apiKey !== 'your_groq_api_key_here';
    }

    private function sendOneShot(string $prompt, int $maxTokens = 512): string
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_INSTRUCTION],
            ['role' => 'user', 'content' => $prompt],
        ];
        return $this->sendRequest($messages, $maxTokens);
    }

    /** @return array<int, array{role: string, content: string}> */
    private function buildMessages(): array
    {
        $messages = [['role' => 'system', 'content' => self::SYSTEM_INSTRUCTION]];
        foreach ($this->conversationHistory as $turn) {
            $messages[] = $turn;
        }
        return $messages;
    }

    /** @param array<int, array{role: string, content: string}> $messages */
    private function sendRequest(array $messages, int $maxTokens = 512): string
    {
        if (!$this->isConfigured()) {
            return 'The AI assistant is not configured. Please set HF_TOKEN in your .env file.';
        }

        foreach ($this->getCandidateModels() as $model) {
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => 0.7,
                'stream' => false,
            ];

            for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    $response = $this->httpClient->request('POST', self::HF_URL, [
                        'timeout' => self::TIMEOUT,
                        'verify_peer' => false,
                        'verify_host' => false,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->apiKey,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => $payload,
                    ]);

                    $statusCode = $response->getStatusCode();
                    if ($statusCode !== 200) {
                        $responseBody = $response->getContent(false);
                        if ($this->isUnsupportedModelError($statusCode, $responseBody)) {
                            break;
                        }
                        if ($this->isRetryableStatus($statusCode) && $attempt < self::MAX_RETRIES) {
                            usleep(250000 * $attempt);
                            continue;
                        }

                        return $this->buildErrorMessage($statusCode, $responseBody);
                    }

                    $data = $response->toArray();
                    $this->workingModel = $model;

                    return $data['choices'][0]['message']['content'] ?? 'The AI returned an empty response.';
                } catch (\Throwable $e) {
                    if ($attempt < self::MAX_RETRIES) {
                        usleep(250000 * $attempt);
                        continue;
                    }
                }
            }
        }

        return 'AI temporarily unavailable. No compatible model is currently available for your Hugging Face configuration.';
    }

    private function buildErrorMessage(int $statusCode, string $responseBody): string
    {
        $apiMessage = null;
        $decoded = json_decode($responseBody, true);
        if (is_array($decoded)) {
            $apiMessage = $decoded['error']['message']
                ?? $decoded['error']
                ?? $decoded['message']
                ?? null;
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return 'Hugging Face authentication failed. Please check your HF_TOKEN in .env.';
        }

        if ($statusCode === 429) {
            return 'AI quota reached or rate limit exceeded. Please try again later.';
        }

        if ($this->isRetryableStatus($statusCode)) {
            return 'AI temporarily unavailable (code ' . $statusCode . '). The remote service did not respond correctly after several attempts.';
        }

        if ($statusCode === 400 && is_string($apiMessage) && $apiMessage !== '') {
            return 'Invalid AI request: ' . $apiMessage;
        }

        if (is_string($apiMessage) && $apiMessage !== '') {
            return 'AI temporarily unavailable (code ' . $statusCode . '): ' . $apiMessage;
        }

        return 'AI temporarily unavailable (code ' . $statusCode . '). Please try again in a moment.';
    }

    private function trimHistory(): void
    {
        while (count($this->conversationHistory) > self::MAX_HISTORY) {
            array_shift($this->conversationHistory);
        }
    }

    /** @return array<int, string> */
    private function getCandidateModels(): array
    {
        if ($this->workingModel !== null) {
            return array_values(array_unique([$this->workingModel, ...self::HF_MODELS]));
        }

        return self::HF_MODELS;
    }

    private function isUnsupportedModelError(int $statusCode, string $responseBody): bool
    {
        if ($statusCode !== 400) {
            return false;
        }

        $apiMessage = $this->extractApiMessage($responseBody);
        if ($apiMessage === null) {
            return false;
        }

        return str_contains(strtolower($apiMessage), 'not supported by any provider you have enabled');
    }

    private function extractApiMessage(string $responseBody): ?string
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return null;
        }

        $message = $decoded['error']['message']
            ?? $decoded['error']
            ?? $decoded['message']
            ?? null;

        return is_string($message) ? $message : null;
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        return in_array($statusCode, [502, 503, 504], true);
    }
}
