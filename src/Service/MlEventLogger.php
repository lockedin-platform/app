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
            $this->connection->executeStatement(
                'INSERT INTO ml_data.platform_event (event_type, entity_type, entity_id, user_id, payload)
                 VALUES (:type, :entityType, :entityId, :userId, CAST(:payload AS JSONB))',
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
