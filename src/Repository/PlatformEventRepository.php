<?php

namespace App\Repository;

use App\Entity\PlatformEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PlatformEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformEvent::class);
    }

    /** @return array<array<string, mixed>> */
    public function exportForTraining(string $eventType, int $limit = 10000): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.userId, e.userRole, e.entityId, e.entityType, e.payload, e.occurredAt')
            ->where('e.eventType = :type')
            ->setParameter('type', $eventType)
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /** @return array<array<string, mixed>> — export investor views paired with project scores (for matching model) */
    public function exportMatchingData(int $limit = 50000): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.userId, e.entityId, e.payload, e.occurredAt')
            ->where('e.eventType = :view')
            ->setParameter('view', PlatformEvent::INVESTOR_VIEWED_PROJECT)
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    public function countByType(string $eventType): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.eventType = :type')
            ->setParameter('type', $eventType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<string, int> */
    public function countAll(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.eventType, COUNT(e.id) as cnt')
            ->groupBy('e.eventType')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['eventType']] = (int) $row['cnt'];
        }
        return $result;
    }

    /** @return array<array<string, mixed>> — scoring events with outcomes for training */
    public function exportScoringDataset(int $limit = 5000): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.payload, e.occurredAt')
            ->where('e.eventType = :type')
            ->setParameter('type', PlatformEvent::PROJECT_SCORED)
            ->andWhere('e.payload IS NOT NULL')
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /** @return array<array<string, mixed>> — deal events for outcome labeling */
    public function exportDealOutcomes(int $limit = 5000): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.userId, e.entityId, e.payload, e.occurredAt, e.eventType')
            ->where('e.eventType IN (:types)')
            ->setParameter('types', [PlatformEvent::DEAL_INITIATED, PlatformEvent::DEAL_COMPLETED, PlatformEvent::DEAL_REJECTED])
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }
}
