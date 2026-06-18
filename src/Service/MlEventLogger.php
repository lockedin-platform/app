<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Fire-and-forget event logger that feeds the ML auto-learning pipeline
 * (ml_data.platform_event). Every meaningful platform action — a project being
 * submitted/evaluated, an investor applying, a deal closing, a mentor session —
 * can be recorded here so models can learn from real platform behaviour.
 *
 * It NEVER throws: logging must not break the user-facing flow if the ml_data
 * schema is missing or the DB hiccups.
 */
class MlEventLogger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $payload Snapshot of the relevant fields at event time.
     */
    public function log(
        string $eventType,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $userId = null,
        array $payload = [],
    ): void {
        try {
            // platform_event lives in the `public` schema (moved there so Doctrine's deploy-time
            // schema:update doesn't crash on the view dependency). Columns: occurred_at (NOT NULL,
            // no default -> set NOW()), payload is JSON.
            $this->connection->executeStatement(
                'INSERT INTO public.platform_event (event_type, entity_type, entity_id, user_id, payload, occurred_at)
                 VALUES (:type, :entityType, :entityId, :userId, CAST(:payload AS JSON), NOW())',
                [
                    'type' => $eventType,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'userId' => $userId,
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
                ],
                [
                    'entityId' => ParameterType::INTEGER,
                    'userId' => ParameterType::INTEGER,
                ],
            );
        } catch (\Throwable $e) {
            // Swallow — analytics logging must never affect the user request.
            $this->logger->warning('MlEventLogger failed: ' . $e->getMessage());
        }
    }
}
