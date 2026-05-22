<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\Thread;
use App\Entity\User;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class CommunityAiService
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    private const DEFAULT_MODEL = 'llama-3.3-70b-versatile';

    private const STOP_WORDS = [
        'a', 'about', 'after', 'again', 'all', 'also', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been',
        'before', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'for', 'from', 'had', 'has', 'have',
        'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'me', 'more', 'my', 'no', 'not', 'of',
        'on', 'or', 'our', 'out', 'so', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these',
        'they', 'this', 'to', 'too', 'up', 'us', 'very', 'was', 'we', 'were', 'what', 'when', 'where',
        'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your', 'cest', 'ca', 'ce', 'ces', 'chez',
        'dans', 'de', 'des', 'du', 'elle', 'elles', 'en', 'est', 'et', 'eux', 'il', 'ils', 'je', 'la',
        'le', 'les', 'leur', 'mais', 'mes', 'moi', 'nous', 'ou', 'par', 'pas', 'plus', 'pour', 'que',
        'qui', 'quoi', 'sans', 'ses', 'sur', 'tes', 'toi', 'ton', 'tu', 'une', 'vos', 'vous', 'w', 'fi',
        'thread', 'discussion', 'comment', 'comments', 'reply', 'replies', 'message', 'messages', 'latest',
        'recent', 'summary', 'resume', 'suggest', 'suggestion', 'suggestions', 'point', 'points', 'need',
        'needs', 'simple', 'thing', 'things', 'still', 'really', 'right', 'away', 'hello', 'bonjour',
        'ala', 'eli', 'ella', 'enti', 'enta', 'mtaa', 'mta', 'tawa', 'brabi', 'ya', 'من', 'الى', 'إلى', 'على',
        'عن', 'في', 'و', 'يا', 'هذا', 'هذه', 'ذلك', 'تلك', 'كان', 'كانت', 'يكون', 'تكون', 'مع', 'ما', 'ماذا',
        'شنو', 'شنية', 'كيف', 'علاش', 'هل', 'لو', 'اللي', 'بش', 'باش', 'موش', 'مش', 'كانش', 'يعني', 'جدا',
    ];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * @param Comment[] $comments
     * @return array<string, mixed>
     */
    public function summarizeThread(Thread $thread, array $comments): array
    {
        $allCommentsCount = count($comments);
        $comments = $this->recentChronologicalComments($comments, 60);
        $configured = $this->isConfigured();
        $language = $this->dominantDiscussionLanguage($thread, $comments);

        if ($comments === []) {
            return $this->textResult($this->noDiscussionMessage($language), false, $configured);
        }

        $user = $this->buildThreadSummaryPrompt($thread, $comments, $allCommentsCount);
        $content = $this->chat(
            'You are an expert community discussion analyst. You receive a structured dossier containing the group, its description, the opening message, every visible comment in chronological order, the participants, the latest messages, the discussion mode, and the likely topic. Use that full conversation context, not isolated keywords. Return only valid JSON with this exact shape: {"summary":"..."}. The summary must be in the dominant language of the thread, two or three natural sentences, and must explain the opening need, the most useful ideas raised, and the latest direction, decision, or unresolved question. Never include headings, usernames, timestamps, markdown, or bullet points. If the discussion is too vague, say that clearly instead of inventing detail.',
            $user,
            0.15,
        );
        $summary = $content !== null ? $this->extractStructuredSummary($content) : null;

        if (($summary === null || $this->summaryLooksWeak($summary, $thread, $comments)) && $configured) {
            $retry = $this->chat(
                'Return only valid JSON matching {"summary":"..."}. Summarize the actual conversation flow. Keep the opening need, the concrete ideas from the replies, and the most recent direction tightly connected. Use the same language as the discussion. Output only two or three plain sentences and do not copy the thread title or any comment verbatim.',
                $user,
                0.1,
            );
            $summary = $retry !== null ? $this->extractStructuredSummary($retry) : $summary;
        }

        if ($summary !== null && !$this->summaryLooksWeak($summary, $thread, $comments)) {
            return $this->textResult($summary, true, $configured);
        }

        if ($configured) {
            return $this->providerErrorTextResult('Groq n a pas renvoye un resume exploitable pour cette discussion.');
        }

        return $this->textResult($this->fallbackThreadSummary($thread, $comments), false, false);
    }

    /**
     * @param Comment[] $comments
     * @return array<string, mixed>
     */
    public function suggestThreadReplies(Thread $thread, array $comments): array
    {
        $allCommentsCount = count($comments);
        $comments = $this->recentChronologicalComments($comments, 60);

        $user = $this->buildReplyPrompt($thread, $comments, $allCommentsCount);
        $configured = $this->isConfigured();
        $content = $this->chat(
            'You write exactly 3 ready-to-post replies for the next message in a community thread. You receive the full discussion dossier: opening message, full chronological transcript, participant names, latest replies, discussion mode, and the likely topic. Base each reply on the actual conversation and the latest messages, not on generic advice. Use the dominant language of the discussion. Make the 3 replies clearly different: one practical next step, one clarifying or alignment reply, and one constructive refinement or alternative. Write in first person, keep them natural, and never narrate the thread. Return only valid JSON with this exact shape: {"replies":["...","...","..."]}.',
            $user,
            0.45,
        );
        error_log('[CommunityAI] suggestThreadReplies chat returned: '.($content !== null ? mb_substr($content, 0, 300) : 'NULL'));
        $suggestions = $content !== null ? $this->extractStructuredSuggestions($content) : [];
        error_log('[CommunityAI] extracted suggestions count: '.count($suggestions).' weak: '.($this->suggestionsLookWeak($suggestions, $thread, $comments) ? 'YES' : 'NO'));

        if ($this->suggestionsLookWeak($suggestions, $thread, $comments) && $configured) {
            $retry = $this->chat(
                'Return only valid JSON matching {"replies":["...","...","..."]}. Write the next three replies using the whole conversation. Every reply must connect the opening request to the latest comments. Avoid vague filler. Make the replies usable as real follow-ups that move the discussion forward.',
                $user,
                0.25,
            );
            $suggestions = $retry !== null ? $this->extractStructuredSuggestions($retry) : $suggestions;
        }

        if ($this->suggestionsLookWeak($suggestions, $thread, $comments)) {
            if ($configured) {
                return $this->providerErrorSuggestionsResult('Groq n a pas renvoye trois reponses assez solides pour ce fil.');
            }

            return $this->suggestionsResult(array_slice($this->fallbackReplySuggestions($thread, $comments), 0, 3), false, false);
        }

        return $this->suggestionsResult(array_slice($suggestions, 0, 3), true, $configured);
    }

    /** @return array<string, mixed> */
    public function generateEventText(Event $event, string $mode): array
    {
        $mode = in_array($mode, ['summary', 'promo', 'checklist'], true) ? $mode : 'summary';
        $configured = $this->isConfigured();
        $user = match ($mode) {
            'promo' => $this->buildEventPrompt($event, 'Write one short promotional line for this event. Maximum 18 words. One sentence only. Mention the clearest reason to join. Do not repeat the title unless it adds useful information.'),
            'checklist' => $this->buildEventPrompt($event, 'Write exactly 3 preparation bullets for this event. Each bullet must be 2 to 6 words. No intro sentence. No title repetition. Use short dash bullets only.'),
            default => $this->buildEventPrompt($event, 'Write one precise factual summary sentence for this event. Maximum 18 words. Keep only the essential facts and no filler.'),
        };

        $system = match ($mode) {
            'promo' => 'You are a concise event-writing assistant. Use only the provided facts. Return only valid JSON with this exact shape: {"text":"..."}. The text value must be a single short sentence, precise, useful, and free of labels like text:, promo:, summary:, or checklist:.',
            'checklist' => 'You are a concise event-writing assistant. Use only the provided facts. Return only valid JSON with this exact shape: {"text":"- first item\n- second item\n- third item"}. The text value must contain exactly 3 very short dash bullets and nothing else. Do not include labels like text:, promo:, summary:, checklist:, or preparation checklist.',
            default => 'You are a concise event-writing assistant. Use only the provided facts. Return only valid JSON with this exact shape: {"text":"..."}. The text value must be one short factual sentence and must not include labels like text:, promo:, summary:, or checklist:.',
        };

        $temperature = match ($mode) {
            'promo' => 0.2,
            'checklist' => 0.1,
            default => 0.1,
        };

        $content = $this->chat(
            $system,
            $user,
            $temperature,
        );

        $text = $content !== null ? $this->extractStructuredEventText($content) : null;

        if ($text !== null && $text !== '') {
            return $this->textResult($text, true, $configured);
        }

        if ($configured) {
            return $this->providerErrorTextResult('Groq n a pas renvoye un texte exploitable pour cet evenement.');
        }

        return $this->textResult($this->fallbackEventText($event, $mode), false, false);
    }

    /** @param Comment[] $comments */
    private function buildThreadSummaryPrompt(Thread $thread, array $comments, int $allCommentsCount): string
    {
        $lines = [
            'TASK: Write a strong summary of this discussion.',
            'OUTPUT RULES:',
            '- Output only valid JSON: {"summary":"..."}.',
            '- Keep the opening request, the concrete ideas from the replies, and the latest direction tied together.',
            '- Never copy one message word for word unless a short phrase is necessary.',
            '- Never include usernames, dates, timestamps, or labels in the final answer.',
            '- If the discussion is unclear, say that clearly instead of faking precision.',
            $this->buildThreadConversationContext($thread, $comments, $allCommentsCount),
        ];

        return implode("\n", $lines);
    }

    /** @param Comment[] $comments */
    private function buildReplyPrompt(Thread $thread, array $comments, int $allCommentsCount): string
    {
        $lines = [
            'TASK: Generate 3 ready-to-post comment replies.',
            'RULES:',
            '- Output only valid JSON: {"replies":["...","...","..."]}.',
            '- Each reply must sound ready to post as-is.',
            '- React to the latest messages while staying aligned with the opening request.',
            '- Reply 1 must be practical, reply 2 must clarify or align, reply 3 must refine or propose an alternative.',
            '- Use first person and natural wording.',
            '- Do not narrate the thread or talk about the conversation from outside.',
            '- If the discussion is unclear, ask for the missing detail instead of inventing facts.',
            $this->buildThreadConversationContext($thread, $comments, $allCommentsCount),
        ];

        return implode("\n", $lines);
    }

    private function buildEventPrompt(Event $event, string $task): string
    {
        return implode("\n", [
            'TASK: '.$task,
            'OUTPUT RULES:',
            '- Return only valid JSON: {"text":"..."}.',
            '- Keep the wording grounded in the provided details only.',
            'EVENT CONTEXT:',
            'Title: '.$this->safe($event->getTitle()),
            'Description: '.$this->safe($event->getDescription()),
            'Organizer: '.$this->safe($event->getCreatedBy()?->getFullName()),
            'Capacity: '.(int) $event->getCapacity(),
            'Date: '.$this->eventDateLabel($event),
        ]);
    }

    /** @param Comment[] $comments */
    private function fallbackThreadSummary(Thread $thread, array $comments): string
    {
        $language = $this->dominantDiscussionLanguage($thread, $comments);

        if ($comments === []) {
            return $this->noDiscussionMessage($language);
        }

        if ($this->discussionLooksUnclear($thread, $comments)) {
            return $language === 'en'
                ? 'The discussion is still too unclear or off-topic to produce a useful summary right now.'
                : 'La discussion reste encore trop floue ou hors sujet pour produire un resume utile pour le moment.';
        }

        $openingFocus = $this->extractOpeningFocus($thread);
        $recentIdeas = $this->extractRecentIdeas($comments, 2);

        $parts = [];
        if ($openingFocus !== '') {
            $parts[] = $this->openingSummarySentence($openingFocus, $language);
        }

        if ($recentIdeas !== []) {
            $parts[] = $this->recentIdeasSummarySentence($recentIdeas, $language);
        } else {
            $mode = $this->dominantDiscussionMode($comments);
            $parts[] = match ($mode) {
                'question' => $language === 'en'
                    ? 'The latest replies are mainly trying to clarify the key question before giving a final answer.'
                    : 'Les derniers messages cherchent surtout a clarifier la question centrale avant de conclure.',
                'problem' => $language === 'en'
                    ? 'The latest replies focus mostly on what is blocking progress and how to fix it.'
                    : 'Les derniers messages se concentrent surtout sur ce qui bloque et sur la facon de le corriger.',
                default => $language === 'en'
                    ? 'The latest replies move the discussion forward but without a final conclusion yet.'
                    : 'Les derniers messages font avancer la discussion sans encore trancher clairement.',
            };
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /** @param Comment[] $comments */
    private function fallbackReplySuggestions(Thread $thread, array $comments): array
    {
        $language = $this->dominantDiscussionLanguage($thread, $comments);
        $openingFocus = $this->extractOpeningFocus($thread);
        $recentIdeas = $this->extractRecentIdeas($comments, 3);
        $primaryIdea = $recentIdeas[0] ?? $openingFocus;
        $secondaryIdea = $recentIdeas[1] ?? $primaryIdea;
        $focus = $recentIdeas[2] ?? $secondaryIdea ?? $openingFocus;

        if ($comments === []) {
            return $language === 'en'
                ? [
                    'Thanks for starting this thread. Could you add a bit more context so I can reply usefully?',
                    'I am interested in this too. What is the main point you want feedback on first?',
                    'If you share one concrete example, I can suggest a more precise next reply.',
                ]
                : [
                    'Merci pour ce sujet. Tu peux ajouter un peu plus de contexte pour qu on te reponde utilement ?',
                    'Je suis interesse aussi. Quel est exactement le point principal sur lequel tu veux un avis ?',
                    'Si tu donnes un exemple concret, je pourrai te proposer une reponse plus precise.',
                ];
        }

        if ($this->discussionLooksUnclear($thread, $comments)) {
            return $language === 'en'
                ? [
                    sprintf('I want to reply usefully, but I still need one more detail about %s. Could you clarify that point?', $this->humanizeIdea($focus)),
                    sprintf('I think we need to narrow down %s before going further. What exactly are you trying to decide?', $this->humanizeIdea($focus)),
                    sprintf('I can help, but it would be easier if we tighten the discussion around %s first.', $this->humanizeIdea($focus)),
                ]
                : [
                    sprintf('Je veux repondre utilement, mais il me manque encore un detail sur %s. Tu peux clarifier ce point ?', $this->humanizeIdea($focus)),
                    sprintf('Je pense qu il faut d abord resserrer la discussion autour de %s. Qu est ce que tu cherches exactement a decider ?', $this->humanizeIdea($focus)),
                    sprintf('Je peux aider, mais il faudrait preciser %s avant d aller plus loin.', $this->humanizeIdea($focus)),
                ];
        }

        $mode = $this->dominantDiscussionMode($comments);

        if ($language === 'en') {
            $third = match ($mode) {
                'question' => sprintf('If you want, we can test one real case around %s and see which answer holds up best.', $this->humanizeIdea($focus)),
                'problem' => sprintf('If you want, we can try this on one real case around %s and keep only what actually removes the blocker.', $this->humanizeIdea($focus)),
                default => 'If you want, we can test this format on the next real topic in the group and adjust it after the first round.',
            };

            return $this->uniqueSuggestions([
                sprintf('I think we should start with %s, because that would give the thread a clearer structure right away.', $this->humanizeIdea($primaryIdea !== '' ? $primaryIdea : $openingFocus)),
                sprintf('I would also add %s, so each discussion ends with something concrete and easy to reuse.', $this->humanizeIdea($secondaryIdea !== '' ? $secondaryIdea : $openingFocus)),
                $third,
            ]);
        }

        $third = match ($mode) {
            'question' => sprintf('Si tu veux, on peut prendre un cas concret autour de %s et voir quelle reponse tient le mieux.', $this->humanizeIdea($focus)),
            'problem' => sprintf('Si tu veux, on peut tester ca sur un vrai cas autour de %s et garder seulement ce qui debloque vraiment la situation.', $this->humanizeIdea($focus)),
            default => 'Si ca te va, on peut appliquer ce format sur le prochain vrai sujet du groupe et l ajuster apres le premier essai.',
        };

        return $this->uniqueSuggestions([
            sprintf('Je pense qu on peut commencer par %s, parce que ca donnerait tout de suite une structure plus claire a la discussion.', $this->humanizeIdea($primaryIdea !== '' ? $primaryIdea : $openingFocus)),
            sprintf('J ajouterais aussi %s, pour que chaque echange se termine avec quelque chose de concret et reutilisable.', $this->humanizeIdea($secondaryIdea !== '' ? $secondaryIdea : $openingFocus)),
            $third,
        ]);
    }

    /** @param Comment[] $comments */
    private function buildThreadConversationContext(Thread $thread, array $comments, int $allCommentsCount): string
    {
        $participants = $this->participantNames($thread, $comments);
        $latestIdeas = $this->extractRecentIdeas($comments, 3);
        $latestComments = array_slice($comments, -6);
        $lines = [
            'THREAD DOSSIER:',
            'group_name: '.$this->safe($thread->getGroup()?->getName()),
            'group_description: '.$this->safe($thread->getGroup()?->getDescription()),
            'thread_author: '.$this->participantName($thread->getUser()),
            'thread_created_at: '.$this->dateLabel($thread->getCreatedAt()),
            'thread_title: '.$this->safe($thread->getTitle()),
            'thread_opening_message: '.$this->safe($thread->getContent()),
            'comments_total: '.$allCommentsCount,
            'comments_included: '.count($comments),
            'participants: '.($participants !== [] ? implode(', ', $participants) : 'unknown'),
            'dominant_language: '.$this->dominantDiscussionLanguage($thread, $comments),
            'discussion_mode: '.$this->dominantDiscussionMode($comments),
            'discussion_topic: '.$this->threadTopic($thread, $comments),
        ];

        if ($allCommentsCount > count($comments)) {
            $lines[] = 'note: only the newest visible comments are included in this dossier because the thread is long.';
        }

        $openingFocus = $this->extractOpeningFocus($thread);
        if ($openingFocus !== '') {
            $lines[] = 'opening_focus: '.$openingFocus;
        }

        if ($latestIdeas !== []) {
            $lines[] = 'latest_key_points:';
            foreach ($latestIdeas as $index => $idea) {
                $lines[] = '- idea '.($index + 1).': '.$idea;
            }
        }

        $lines[] = 'FULL TRANSCRIPT (oldest to newest):';
        $lines[] = '0. '.$this->participantName($thread->getUser()).' at '.$this->dateLabel($thread->getCreatedAt()).': '.$this->safe($this->normalizeSpaces((string) $thread->getContent()));

        foreach ($comments as $index => $comment) {
            $lines[] = ($index + 1).'. '.$this->participantName($comment->getUser()).' at '.$this->dateLabel($comment->getCreatedAt()).': '.$this->safe($this->normalizeSpaces((string) $comment->getContent()));
        }

        if ($latestComments !== []) {
            $lines[] = 'LATEST MESSAGES TO PRIORITIZE:';

            foreach ($latestComments as $index => $comment) {
                $lines[] = 'latest '.($index + 1).': '.$this->safe($this->normalizeSpaces((string) $comment->getContent()));
            }
        }

        return implode("\n", $lines);
    }

    private function fallbackEventText(Event $event, string $mode): string
    {
        $title = $this->safe($event->getTitle()) !== '' ? $this->safe($event->getTitle()) : 'Cet evenement';
        $description = $this->excerpt((string) $event->getDescription(), 22);
        $date = $this->eventDateLabel($event);
        $capacity = (int) $event->getCapacity();

        return match ($mode) {
            'promo' => trim(sprintf('%s le %s. %s', $title, $date, $description !== '' ? $description : 'Consultez les details et preparez votre venue.')),
            'checklist' => implode("\n", [
                '- Verifier date et heure.',
                '- Confirmer le materiel utile.',
                '- Suivre les '.max($capacity, 1).' places disponibles.',
            ]),
            default => trim(sprintf('%s est prevu le %s avec %d places.', $title, $date, max($capacity, 1))),
        };
    }

    private function chat(string $system, string $user, float $temperature): ?string
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return null;
        }

        try {
            $body = json_encode([
                'model' => $this->model(),
                'temperature' => $temperature,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ], JSON_THROW_ON_ERROR);

            $ch = curl_init($this->endpoint());
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => (int) $this->timeout(),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $curlError !== '') {
                error_log('[CommunityAI] cURL error: '.$curlError);
                return null;
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                error_log('[CommunityAI] HTTP '.$httpCode.': '.mb_substr((string) $response, 0, 300));
                return null;
            }

            $payload = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
            $content = $payload['choices'][0]['message']['content'] ?? null;

            return is_string($content) && trim($content) !== '' ? trim($content) : null;
        } catch (Throwable $e) {
            error_log('[CommunityAI] chat() error: '.$e->getMessage());
            return null;
        }
    }

    private function cleanSummary(string $content): string
    {
        $content = trim($content);

        if (preg_match('/^(summary|resume)\s*[:\-]/i', $content) === 1) {
            $content = trim((string) preg_replace('/^(summary|resume)\s*[:\-]/i', '', $content));
        }

        return $this->normalizeSpaces($content);
    }

    private function extractStructuredSummary(string $content): ?string
    {
        $payload = $this->extractJsonObject($content);
        $summary = $payload['summary'] ?? null;

        if (is_string($summary) && trim($summary) !== '') {
            return $this->cleanSummary($summary);
        }

        $clean = $this->cleanSummary($content);

        return $clean !== '' ? $clean : null;
    }

    /** @return string[] */
    private function extractStructuredSuggestions(string $content): array
    {
        $payload = $this->extractJsonObject($content);
        $items = $payload['replies'] ?? $payload['suggestions'] ?? null;

        if (is_array($items)) {
            return $this->uniqueSuggestions(array_map(
                fn (mixed $item): string => $this->normalizeSpaces((string) $item),
                $items,
            ));
        }

        $parsed = $this->parseNumberedList($content);
        if ($parsed !== []) {
            return $this->uniqueSuggestions($parsed);
        }

        $lines = preg_split('/\R+/u', $content) ?: [];

        return $this->uniqueSuggestions(array_map(
            fn (string $line): string => (string) preg_replace('/^[-*•\d\)\.\s]+/u', '', trim($line)),
            $lines,
        ));
    }

    private function extractStructuredEventText(string $content): ?string
    {
        $payload = $this->extractJsonObject($content);
        $text = $payload['text'] ?? null;

        if (is_string($text) && trim($text) !== '') {
            return $this->cleanEventText($text);
        }

        $looseText = $this->extractLooseJsonTextField($content);
        if ($looseText !== null) {
            return $this->cleanEventText($looseText);
        }

        $clean = $this->cleanEventText($content);

        return $clean !== '' ? $clean : null;
    }

    private function extractLooseJsonTextField(string $content): ?string
    {
        if (preg_match('/"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $content, $match) !== 1) {
            return null;
        }

        try {
            $decoded = json_decode('"'.$match[1].'"', true, 512, JSON_THROW_ON_ERROR);

            return is_string($decoded) && trim($decoded) !== '' ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanEventText(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content));
        $content = trim($content, " \t\n\r\0\x0B{}");

        if (preg_match('/^"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/s', $content, $match) === 1) {
            try {
                $decoded = json_decode('"'.$match[1].'"', true, 512, JSON_THROW_ON_ERROR);
                if (is_string($decoded)) {
                    $content = $decoded;
                }
            } catch (Throwable) {
            }
        }

        if (str_starts_with($content, '"') && str_ends_with($content, '"')) {
            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (is_string($decoded)) {
                    $content = $decoded;
                }
            } catch (Throwable) {
            }
        }

        $content = str_replace(["\r\n", "\r", '\\n', '\\r'], ["\n", "\n", "\n", ''], $content);
        $content = (string) preg_replace('/^\s*(summary|resume|promo|promotion|checklist|preparation checklist(?:\s+for[^:\n]+)?)\s*[:\-]\s*/iu', '', $content);

        if (preg_match('/(?:^|\s)\*\s+/u', $content) === 1) {
            $content = ltrim((string) preg_replace('/\s*\*\s+/u', "\n- ", $content), "\n");
        }

        $lines = preg_split('/\R+/u', $content) ?: [];
        $normalizedLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^[-*•]\s+/u', $line) === 1) {
                $line = '- '.trim((string) preg_replace('/^[-*•]\s+/u', '', $line));
            } else {
                $line = $this->normalizeSpaces($line);
            }

            $normalizedLines[] = $line;
        }

        if ($normalizedLines === []) {
            return '';
        }

        return count($normalizedLines) > 1
            ? implode("\n", $normalizedLines)
            : $this->normalizeSpaces($normalizedLines[0]);
    }

    /** @return array<string, mixed> */
    private function extractJsonObject(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $candidates = [$content];
        $withoutFence = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);
        if (is_string($withoutFence) && trim($withoutFence) !== '' && trim($withoutFence) !== $content) {
            $candidates[] = trim($withoutFence);
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $match) === 1) {
            $candidates[] = trim($match[0]);
        }

        foreach ($candidates as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);

                return is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function textResult(string $content, bool $usedProvider, bool $configured): array
    {
        return [
            'content' => trim($content),
            'used_provider' => $usedProvider,
            'configured' => $configured,
            'provider' => $usedProvider ? 'Groq' : 'Local',
            'model' => $usedProvider ? $this->model() : null,
            'source_label' => $usedProvider ? 'Groq' : ($configured ? 'Repli local' : 'Mode local'),
            'source_hint' => $usedProvider
                ? 'Generation reelle via Groq.'
                : ($configured ? 'Groq n a pas renvoye un resultat exploitable, le repli local a pris le relais.' : 'Ajoutez une cle Groq pour activer la generation distante.'),
            'error' => false,
            'error_message' => null,
        ];
    }

    /** @param string[] $items
     *  @return array<string, mixed>
     */
    private function suggestionsResult(array $items, bool $usedProvider, bool $configured): array
    {
        return [
            'items' => $items,
            'used_provider' => $usedProvider,
            'configured' => $configured,
            'provider' => $usedProvider ? 'Groq' : 'Local',
            'model' => $usedProvider ? $this->model() : null,
            'source_label' => $usedProvider ? 'Groq' : ($configured ? 'Repli local' : 'Mode local'),
            'source_hint' => $usedProvider
                ? 'Suggestions generees depuis le contexte complet du fil.'
                : ($configured ? 'Groq n a pas renvoye des suggestions assez solides, le repli local a ete utilise.' : 'Ajoutez une cle Groq pour activer des suggestions distantes.'),
            'error' => false,
            'error_message' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function providerErrorTextResult(string $message): array
    {
        return [
            'content' => '',
            'used_provider' => false,
            'configured' => true,
            'provider' => 'Groq',
            'model' => $this->model(),
            'source_label' => 'Groq indisponible',
            'source_hint' => 'Aucun repli local n a ete utilise. Verifiez la cle, la connexion reseau ou le TLS local.',
            'error' => true,
            'error_message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function providerErrorSuggestionsResult(string $message): array
    {
        return [
            'items' => [],
            'used_provider' => false,
            'configured' => true,
            'provider' => 'Groq',
            'model' => $this->model(),
            'source_label' => 'Groq indisponible',
            'source_hint' => 'Aucun repli local n a ete utilise. Verifiez la cle, la connexion reseau ou le TLS local.',
            'error' => true,
            'error_message' => $message,
        ];
    }

    private function noDiscussionMessage(string $language): string
    {
        return $language === 'en' ? 'No discussion yet.' : 'Aucune discussion pour le moment.';
    }

    /** @param Comment[] $comments */
    private function recentChronologicalComments(array $comments, int $limit): array
    {
        $comments = array_values($comments);

        usort($comments, static function (Comment $left, Comment $right): int {
            $leftCreatedAt = $left->getCreatedAt()?->format('Y-m-d H:i:s.u') ?? '';
            $rightCreatedAt = $right->getCreatedAt()?->format('Y-m-d H:i:s.u') ?? '';
            $dateCompare = strcmp($leftCreatedAt, $rightCreatedAt);

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ($left->getId() ?? 0) <=> ($right->getId() ?? 0);
        });

        if (count($comments) > $limit) {
            $comments = array_slice($comments, -$limit);
        }

        return $comments;
    }

    /** @param Comment[] $comments */
    private function summaryLooksWeak(string $summary, Thread $thread, array $comments): bool
    {
        if ($summary === '' || mb_strlen($summary) < 20) {
            return true;
        }

        if ($comments !== [] && preg_match('/^(aucune discussion|no discussion yet)\.?$/i', $summary) === 1) {
            return true;
        }

        $title = $this->normalizeSpaces((string) $thread->getTitle());
        if ($title !== '') {
            similar_text(mb_strtolower($summary), mb_strtolower($title), $similarity);

            if ($similarity >= 96.0) {
                return true;
            }
        }

        $opening = $this->normalizeSpaces((string) $thread->getContent());
        if ($opening !== '' && mb_strtolower($summary) === mb_strtolower($opening)) {
            return true;
        }

        return false;
    }

    /** @param string[] $suggestions
     *  @param Comment[] $comments
     */
    private function suggestionsLookWeak(array $suggestions, Thread $thread, array $comments): bool
    {
        if (count($suggestions) < 3) {
            return true;
        }

        $normalized = array_map(static fn (string $suggestion): string => mb_strtolower(trim($suggestion)), $suggestions);
        if (count(array_unique($normalized)) < 3) {
            return true;
        }

        foreach ($suggestions as $suggestion) {
            if (mb_strlen(trim($suggestion)) < 18) {
                return true;
            }

            if (preg_match('/\b(someone|the person|they seem|this thread)\b/i', $suggestion) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param Comment[] $comments */
    private function discussionLooksUnclear(Thread $thread, array $comments): bool
    {
        if ($comments === []) {
            return false;
        }

        $keywordFrequencies = $this->keywordFrequencies(array_map(
            static fn (Comment $comment): string => (string) $comment->getContent(),
            $comments,
        ));
        $topFrequency = $keywordFrequencies === [] ? 0 : max($keywordFrequencies);
        $meaningfulKeywords = array_keys($keywordFrequencies);
        $longComments = count(array_filter($comments, fn (Comment $comment): bool => count($this->extractKeywords((string) $comment->getContent())) >= 4));

        if ($longComments === 0) {
            return true;
        }

        if (count($meaningfulKeywords) < 2) {
            return true;
        }

        if (count($comments) >= 4 && $topFrequency < 2 && $this->threadTopic($thread, $comments) === '') {
            return true;
        }

        return false;
    }

    /** @param Comment[] $comments */
    private function dominantDiscussionMode(array $comments): string
    {
        $counts = [
            'question' => 0,
            'problem' => 0,
            'suggestion' => 0,
            'agreement' => 0,
        ];

        foreach ($comments as $comment) {
            $text = mb_strtolower($this->normalizeSpaces((string) $comment->getContent()));

            if ($text === '') {
                continue;
            }

            if (str_contains($text, '?') || $this->containsAny($text, ['how', 'what', 'why', 'when', 'which', 'who', 'help', 'comment', 'كيف', 'شنو', 'شنية', 'علاش', 'pourquoi', 'comment', 'quoi'])) {
                ++$counts['question'];
            }

            if ($this->containsAny($text, ['problem', 'issue', 'error', 'bug', 'broken', 'fail', 'failing', 'wrong', 'مشكل', 'مشكلة', 'probl', 'souci'])) {
                ++$counts['problem'];
            }

            if ($this->containsAny($text, ['should', 'could', 'try', 'maybe', 'recommend', 'suggest', 'propose', 'better', 'لازم', 'يلزم', 'يمكن', 'peut', 'devrait', 'essaye'])) {
                ++$counts['suggestion'];
            }

            if ($this->containsAny($text, ['agree', 'exactly', 'true', 'same', 'yes', 'totally', 'صح', 'بالضبط', 'oui', 'exactement'])) {
                ++$counts['agreement'];
            }
        }

        arsort($counts);
        $mode = (string) array_key_first($counts);

        return ($counts[$mode] ?? 0) > 0 ? $mode : 'mixed';
    }

    /** @param Comment[] $comments */
    private function threadTopic(Thread $thread, array $comments): string
    {
        $threadKeywords = $this->extractKeywordsFromText((string) ($thread->getTitle().' '.$thread->getContent()), 3);
        if ($threadKeywords !== []) {
            return $this->formatKeywordList($threadKeywords);
        }

        $commentKeywords = $this->extractKeywordsFromComments($comments, 3);
        if ($commentKeywords !== []) {
            return $this->formatKeywordList($commentKeywords);
        }

        return $this->excerpt((string) ($thread->getTitle() ?: $thread->getContent()), 10);
    }

    /** @param Comment[] $comments */
    private function extractKeywordsFromComments(array $comments, int $limit): array
    {
        $frequencies = $this->keywordFrequencies(array_map(
            static fn (Comment $comment): string => (string) $comment->getContent(),
            $comments,
        ));

        return array_slice(array_keys($frequencies), 0, $limit);
    }

    private function extractKeywordsFromText(string $text, int $limit): array
    {
        $frequencies = $this->keywordFrequencies([$text]);

        return array_slice(array_keys($frequencies), 0, $limit);
    }

    /** @param string[] $texts */
    private function keywordFrequencies(array $texts): array
    {
        $frequencies = [];

        foreach ($texts as $text) {
            foreach ($this->extractKeywords($text) as $keyword) {
                $frequencies[$keyword] = ($frequencies[$keyword] ?? 0) + 1;
            }
        }

        arsort($frequencies);

        return $frequencies;
    }

    /** @return string[] */
    private function extractKeywords(string $text): array
    {
        $text = $this->normalizeSpaces(mb_strtolower($text));
        if ($text === '') {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $matches);
        $tokens = $matches[0] ?? [];

        return array_values(array_filter($tokens, static function (string $token): bool {
            if (in_array($token, self::STOP_WORDS, true)) {
                return false;
            }

            if (preg_match('/^\d+$/', $token) === 1) {
                return false;
            }

            return mb_strlen($token) >= 3 || preg_match('/\p{Arabic}/u', $token) === 1;
        }));
    }

    /** @param string[] $keywords */
    private function formatKeywordList(array $keywords): string
    {
        $keywords = array_values(array_unique(array_filter(array_map([$this, 'normalizeSpaces'], $keywords))));

        if ($keywords === []) {
            return '';
        }

        if (count($keywords) === 1) {
            return $keywords[0];
        }

        if (count($keywords) === 2) {
            return $keywords[0].' et '.$keywords[1];
        }

        return $keywords[0].', '.$keywords[1].' et '.$keywords[2];
    }

    /** @param string[] $suggestions */
    private function uniqueSuggestions(array $suggestions): array
    {
        $result = [];
        $seen = [];

        foreach ($suggestions as $suggestion) {
            $clean = $this->normalizeSpaces((string) preg_replace('/^\s*[123]\)\s*/', '', trim($suggestion)));

            if ($clean === '') {
                continue;
            }

            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $clean;
        }

        return $result;
    }

    /** @param string[] $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSpaces(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function excerpt(string $text, int $words): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $text) ?: [];
        if (count($parts) <= $words) {
            return $text;
        }

        return implode(' ', array_slice($parts, 0, $words)).'...';
    }

    private function openingSummarySentence(string $openingFocus, string $language): string
    {
        $openingFocus = $this->humanizeIdea($openingFocus);

        if ($openingFocus === '') {
            return $language === 'en'
                ? 'The thread has a clear opening message.'
                : 'Le fil part d un message initial clair.';
        }

        $lower = mb_strtolower($openingFocus);
        $asks = str_starts_with($lower, 'how ')
            || str_starts_with($lower, 'comment ')
            || str_starts_with($lower, 'why ')
            || str_starts_with($lower, 'pourquoi ');

        if ($language === 'en') {
            return $asks
                ? 'The opening message asks '.$this->ensureTrailingPeriod($openingFocus)
                : 'The opening message asks for '.$this->ensureTrailingPeriod($openingFocus);
        }

        return 'Le message de depart demande '.$this->ensureTrailingPeriod($openingFocus);
    }

    /** @param string[] $recentIdeas */
    private function recentIdeasSummarySentence(array $recentIdeas, string $language): string
    {
        $recentIdeas = array_values(array_filter(array_map([$this, 'humanizeIdea'], $recentIdeas)));
        if ($recentIdeas === []) {
            return '';
        }

        if ($language === 'en') {
            if (count($recentIdeas) === 1) {
                return 'The latest replies mainly suggest '.$this->ensureTrailingPeriod($recentIdeas[0]);
            }

            return 'The latest replies mainly suggest '.$this->trimTrailingPunctuation($recentIdeas[0]).', then '.$this->ensureTrailingPeriod($recentIdeas[1]);
        }

        if (count($recentIdeas) === 1) {
            return 'Les derniers messages proposent surtout '.$this->ensureTrailingPeriod($recentIdeas[0]);
        }

        return 'Les derniers messages proposent surtout '.$this->trimTrailingPunctuation($recentIdeas[0]).', puis '.$this->ensureTrailingPeriod($recentIdeas[1]);
    }

    private function extractOpeningFocus(Thread $thread): string
    {
        $opening = $this->bestDiscussionSentence((string) $thread->getContent());

        if ($opening !== '') {
            return $opening;
        }

        return $this->bestDiscussionSentence((string) $thread->getTitle());
    }

    /** @param Comment[] $comments
     *  @return string[]
     */
    private function extractRecentIdeas(array $comments, int $limit): array
    {
        $ideas = [];

        foreach (array_reverse($comments) as $comment) {
            $idea = $this->bestDiscussionSentence((string) $comment->getContent());

            if ($idea === '') {
                continue;
            }

            $ideas[] = $idea;

            if (count($ideas) >= $limit * 2) {
                break;
            }
        }

        $ideas = array_reverse($ideas);

        return array_slice($this->deduplicateTextList($ideas), -$limit);
    }

    private function bestDiscussionSentence(string $text): string
    {
        $bestCandidate = '';
        $bestScore = -1;

        foreach ($this->splitDiscussionSentences($text) as $sentence) {
            foreach ($this->expandIdeaCandidates($sentence) as $candidate) {
                $candidate = $this->cleanIdeaFragment($candidate);
                if ($candidate === '') {
                    continue;
                }

                $score = $this->scoreIdeaCandidate($candidate);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestCandidate = $candidate;
                }
            }
        }

        return $bestCandidate;
    }

    /** @return string[] */
    private function splitDiscussionSentences(string $text): array
    {
        $text = trim((string) str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[\.\!\?])\s+|\n+/u', $text) ?: [];

        return array_values(array_filter(array_map([$this, 'normalizeSpaces'], $sentences)));
    }

    /** @return string[] */
    private function expandIdeaCandidates(string $sentence): array
    {
        $candidates = [$sentence];

        foreach ([':', ' - ', ' — '] as $separator) {
            if (str_contains($sentence, $separator)) {
                $parts = explode($separator, $sentence, 2);
                $tail = $this->normalizeSpaces((string) ($parts[1] ?? ''));
                if ($tail !== '') {
                    $candidates[] = $tail;
                }
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function cleanIdeaFragment(string $text): string
    {
        $text = $this->normalizeSpaces($text);
        $text = (string) preg_replace('/^[\-\*\d\)\.\s:]+/u', '', $text);
        $text = (string) preg_replace('/^(good idea|bonne idee|i agree|je suis d accord|that makes sense|ca a du sens|je pense que|i think|je propose que|je propose|i suggest that|i suggest|we can also|on peut aussi|nous pouvons aussi|we should also|on devrait aussi|i would also add|j ajouterais aussi|we can|on peut|nous pouvons|we could|on pourrait|j ajouterais|i would add|i need|i want|i am looking for|je cherche|je veux|je voudrais|also|aussi|then|ensuite|just|simplement)\s+/iu', '', $text);
        $text = (string) preg_replace('/^(also|aussi)\s+/iu', '', $text);
        $text = $this->normalizeSpaces($text);

        return $this->trimTrailingPunctuation($text);
    }

    private function scoreIdeaCandidate(string $candidate): int
    {
        $length = mb_strlen($candidate);
        $keywords = count($this->extractKeywords($candidate));

        if ($length < 12) {
            return 0;
        }

        if ($this->looksGenericIdea($candidate)) {
            return max(1, $keywords * 2);
        }

        $score = ($keywords * 12) + min($length, 120);

        if (str_contains($candidate, ':')) {
            $score -= 18;
        }

        return $score;
    }

    private function looksGenericIdea(string $candidate): bool
    {
        $lower = mb_strtolower($candidate);

        if (preg_match('/^(ok|oui|yes|thanks|merci|bonne idee|good idea|exactly|exactement)$/u', $lower) === 1) {
            return true;
        }

        return count($this->extractKeywords($candidate)) < 2;
    }

    /** @param string[] $items
     *  @return string[]
     */
    private function deduplicateTextList(array $items): array
    {
        $result = [];
        $seen = [];

        foreach ($items as $item) {
            $clean = $this->normalizeSpaces($item);
            if ($clean === '') {
                continue;
            }

            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $clean;
        }

        return $result;
    }

    private function humanizeIdea(string $idea): string
    {
        $idea = $this->trimTrailingPunctuation($this->normalizeSpaces($idea));

        return $idea !== '' ? $idea : 'ce point';
    }

    private function ensureTrailingPeriod(string $text): string
    {
        $text = $this->trimTrailingPunctuation($text);

        return $text === '' ? '' : $text.'.';
    }

    private function trimTrailingPunctuation(string $text): string
    {
        return rtrim($this->normalizeSpaces($text), " \t\n\r\0\x0B.;:!?，،");
    }

    /** @param Comment[] $comments */
    private function participantNames(Thread $thread, array $comments): array
    {
        $participants = [$this->participantName($thread->getUser())];

        foreach ($comments as $comment) {
            $participants[] = $this->participantName($comment->getUser());
        }

        return $this->deduplicateTextList($participants);
    }

    private function participantName(?User $user): string
    {
        if (!$user instanceof User) {
            return 'Participant';
        }

        $name = $this->normalizeSpaces((string) $user->getFullName());

        return $name !== '' ? $name : 'Participant';
    }

    private function dateLabel(?\DateTimeInterface $dateTime): string
    {
        return $dateTime?->format('d/m/Y H:i') ?? 'unknown time';
    }

    /** @param Comment[] $comments */
    private function dominantDiscussionLanguage(Thread $thread, array $comments): string
    {
        $texts = [(string) $thread->getTitle(), (string) $thread->getContent()];
        foreach (array_slice($comments, -8) as $comment) {
            $texts[] = (string) $comment->getContent();
        }

        $joined = mb_strtolower($this->normalizeSpaces(implode(' ', $texts)));
        if ($joined === '') {
            return 'fr';
        }

        if (preg_match('/\p{Arabic}/u', $joined) === 1) {
            return 'ar';
        }

        $frenchScore = $this->countLanguageHits($joined, [
            'bonjour', 'salut', 'merci', 'projet', 'discussion', 'groupe', 'reponse', 'resume', 'question',
            'pour', 'avec', 'sans', 'nous', 'vous', 'dans', 'comment', 'pourquoi', 'peut', 'devrait',
            'utile', 'structurer', 'suivre', 'points', 'accord', 'synthese', 'actions', 'ajouter',
            'clarifier', 'prochain', 'essai', 'format', 'retours',
        ]);

        if (preg_match('/[àâäçéèêëîïôöùûüÿœæ]/u', $joined) === 1) {
            $frenchScore += 2;
        }

        $englishScore = $this->countLanguageHits($joined, [
            'hello', 'thanks', 'project', 'discussion', 'reply', 'summary', 'question', 'with', 'without',
            'about', 'how', 'why', 'should', 'could', 'would', 'think', 'useful', 'structured', 'follow',
            'group', 'agreements', 'next', 'steps', 'clear', 'argued', 'pace', 'cadence', 'format',
            'practical', 'test', 'real', 'topic', 'message', 'messages',
        ]);

        return $englishScore > $frenchScore ? 'en' : 'fr';
    }

    /** @param string[] $words */
    private function countLanguageHits(string $text, array $words): int
    {
        $score = 0;

        foreach ($words as $word) {
            if (preg_match('/(?<!\p{L})'.preg_quote($word, '/').'(?!\p{L})/u', $text) === 1) {
                ++$score;
            }
        }

        return $score;
    }

    private function eventDateLabel(Event $event): string
    {
        try {
            return $event->getEventDate()?->format('d/m/Y H:i') ?? 'la date prevue';
        } catch (Throwable) {
            return 'la date prevue';
        }
    }

    private function parseNumberedList(string $content): array
    {
        preg_match_all('/^\s*(?:[123][\)\.]|[-*•])\s*(.+)$/mi', $content, $matches);

        return array_values(array_filter(array_map(
            fn (string $line): string => $this->normalizeSpaces(trim($line)),
            $matches[1] ?? [],
        )));
    }

    private function endpoint(): string
    {
        foreach (['COMMUNITY_GROQ_ENDPOINT', 'GROQ_API_ENDPOINT'] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return self::ENDPOINT;
    }

    private function apiKey(): ?string
    {
        foreach (['COMMUNITY_GROQ_API_KEY', 'GROQ_SUMMARY_API', 'GROQ_API_KEY'] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function model(): string
    {
        foreach (['COMMUNITY_GROQ_MODEL', 'COMMUNITY_AI_MODEL'] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return self::DEFAULT_MODEL;
    }

    private function timeout(): float
    {
        $value = $_SERVER['COMMUNITY_GROQ_TIMEOUT'] ?? $_ENV['COMMUNITY_GROQ_TIMEOUT'] ?? getenv('COMMUNITY_GROQ_TIMEOUT');

        if (is_string($value) && is_numeric($value) && (float) $value > 0) {
            return (float) $value;
        }

        return 18.0;
    }

    private function allowInsecureTls(): bool
    {
        $value = $_SERVER['COMMUNITY_GROQ_INSECURE'] ?? $_ENV['COMMUNITY_GROQ_INSECURE'] ?? getenv('COMMUNITY_GROQ_INSECURE');

        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function safe(?string $value): string
    {
        return trim((string) $value);
    }
}