<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\User;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class CommunityTicketService
{
    private const PREFIX = 'CommunityTicket';
    private const QR_ENDPOINT = 'https://api.qrserver.com/v1/create-qr-code/';
    private const DECODE_ENDPOINT = 'https://api.qrserver.com/v1/read-qr-code/';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /** @return array<string, string> */
    public function buildTicket(Event $event, User $user): array
    {
        $eventTitle = $this->sanitizeText((string) $event->getTitle());
        $userLabel = $this->sanitizeText(trim($user->getFullName()) !== '' ? $user->getFullName() : ('User '.$user->getId()));
        $eventDateIso = $event->getEventDate()?->format('Y-m-d\TH:i:s') ?? '';
        $signature = $this->signature((int) $event->getId(), (int) $user->getId(), $eventDateIso, $eventTitle, $userLabel);
        $payload = implode('|', [
            self::PREFIX,
            'event='.$eventTitle,
            'user='.$userLabel,
            'e='.(int) $event->getId(),
            'u='.(int) $user->getId(),
            'sig='.$signature,
        ]);

        return [
            'payload' => $payload,
            'signature' => $signature,
            'manual_code' => substr($signature, 0, 10),
            'qr_url' => self::QR_ENDPOINT.'?size=260x260&data='.rawurlencode($payload),
        ];
    }

    public function renderQrPng(string $payload): string
    {
        $payload = trim($payload);

        if ($payload === '') {
            throw new RuntimeException('Le contenu du ticket est vide.');
        }

        try {
            $response = $this->httpClient->request('GET', self::QR_ENDPOINT, [
                'query' => [
                    'size' => '600x600',
                    'data' => $payload,
                ],
                'headers' => [
                    'Accept' => 'image/png',
                ],
                'timeout' => 12,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException('Le service de generation QR est indisponible.');
            }

            $image = $response->getContent(false);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Impossible de generer le QR du ticket.', 0, $exception);
        }

        if (!is_string($image) || $image === '') {
            throw new RuntimeException('Impossible de generer le QR du ticket.');
        }

        return $image;
    }

    public function decodeUploadedImage(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new RuntimeException('Le televersement a echoue. Merci de reessayer avec une image PNG ou JPG.');
        }

        $formData = new FormDataPart([
            'file' => DataPart::fromPath(
                $file->getPathname(),
                $file->getClientOriginalName() !== '' ? $file->getClientOriginalName() : basename($file->getPathname()),
                $file->getMimeType() ?: 'image/png',
            ),
        ]);

        try {
            $response = $this->httpClient->request('POST', self::DECODE_ENDPOINT, [
                'headers' => array_merge($formData->getPreparedHeaders()->toArray(), ['Accept' => 'application/json']),
                'body' => $formData->bodyToIterable(),
                'timeout' => 12,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException('Le service de lecture QR est indisponible.');
            }

            $decoded = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
            $data = $decoded[0]['symbol'][0]['data'] ?? null;
            $error = $decoded[0]['symbol'][0]['error'] ?? null;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Impossible de lire le QR code televerse.', 0, $exception);
        }

        if (!is_string($data) || trim($data) === '') {
            throw new RuntimeException(is_string($error) && $error !== '' ? $error : 'Aucun QR code valide n\'a ete detecte sur cette image.');
        }

        return trim($data);
    }

    /** @return array<string, mixed> */
    public function validatePayloadForEvent(string $payload, Event $event): array
    {
        $parsed = $this->parsePayload($payload);

        if ((int) $parsed['e'] !== (int) $event->getId()) {
            throw new RuntimeException('Ce ticket ne correspond pas a cet evenement.');
        }

        $expected = $this->signature(
            (int) $parsed['e'],
            (int) $parsed['u'],
            $event->getEventDate()?->format('Y-m-d\TH:i:s') ?? '',
            $parsed['event'],
            $parsed['user'],
        );

        return [
            'valid' => hash_equals($expected, $parsed['sig']),
            'parsed' => $parsed,
            'payload' => $payload,
        ];
    }

    /** @return array<string, string> */
    private function parsePayload(string $payload): array
    {
        $payload = trim($payload);

        if ($payload === '') {
            throw new RuntimeException('Le contenu du ticket est vide.');
        }

        $parts = explode('|', $payload);
        if (($parts[0] ?? '') !== self::PREFIX) {
            throw new RuntimeException('Le format du ticket est invalide.');
        }

        $parsed = [];
        foreach (array_slice($parts, 1) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($key === null || $value === null || $key === '') {
                continue;
            }

            $parsed[$key] = trim($value);
        }

        foreach (['event', 'user', 'e', 'u', 'sig'] as $required) {
            if (($parsed[$required] ?? '') === '') {
                throw new RuntimeException('Le ticket est incomplet.');
            }
        }

        return $parsed;
    }

    private function signature(int $eventId, int $userId, string $eventDateIso, string $eventTitle, string $userLabel): string
    {
        $payload = implode(':', [
            $eventId,
            $userId,
            $eventDateIso,
            $eventTitle,
            $userLabel,
            $this->secret(),
        ]);

        return rtrim(strtr(base64_encode(hash('sha256', $payload, true)), '+/', '-_'), '=');
    }

    private function sanitizeText(string $value): string
    {
        return trim(str_replace(["|", "\r", "\n"], [' ', ' ', ' '], $value));
    }

    private function secret(): string
    {
        $value = $_SERVER['COMMUNITY_TICKET_SECRET'] ?? $_ENV['COMMUNITY_TICKET_SECRET'] ?? getenv('COMMUNITY_TICKET_SECRET');

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : 'community-ticket-secret-change-me';
    }
}