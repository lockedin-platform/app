<?php
namespace App\Repository;

use App\Entity\InvestmentOpportunity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class InvestmentOpportunityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentOpportunity::class);
    }

    public function findOpen(): array
    {
        return $this->findBy(['status' => 'OPEN'], ['createdAt' => 'DESC']);
    }

    public function findByProject(mixed $project): array
    {
        return $this->findBy(['project' => $project], ['id' => 'DESC']);
    }

    /**
     * DQL: Search open opportunities with full-text search and dynamic sort.
     */
    public function searchOpen(string $search = '', string $sort = 'recent'): array
    {
        return $this->searchOpenQuery($search, $sort)->getQuery()->getResult();
    }

    /**
     * Returns a QueryBuilder for open opportunities (paginatable).
     */
    public function searchOpenQuery(string $search = '', string $sort = 'recent'): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.project', 'p')
            ->where('o.status = :status')
            ->setParameter('status', 'OPEN');

        if ($search !== '') {
            $qb->andWhere('p.titre LIKE :q OR o.description LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }

        match ($sort) {
            'amount_asc' => $qb->orderBy('o.targetAmount', 'ASC'),
            'amount_desc' => $qb->orderBy('o.targetAmount', 'DESC'),
            'deadline' => $qb->orderBy('o.deadline', 'ASC'),
            default => $qb->orderBy('o.createdAt', 'DESC'),
        };

        return $qb;
    }

    /**
     * DQL: Admin search with status filter, returns QueryBuilder for pagination.
     */
    public function buildAdminQuery(array|string $filters = [], string $statusFilter = ''): QueryBuilder
    {
        if (!is_array($filters)) {
            $filters = [
                'q' => $filters,
                'status' => $statusFilter,
            ];
        }

        $search = trim((string) ($filters['q'] ?? ''));
        $statusFilter = (string) ($filters['status'] ?? '');
        $entrepreneurFilter = trim((string) ($filters['entrepreneur'] ?? ''));
        $sectorFilter = trim((string) ($filters['sector'] ?? ''));
        $deadlineState = (string) ($filters['deadline_state'] ?? '');
        $sort = (string) ($filters['order'] ?? 'recent');
        $amountMin = is_numeric($filters['amount_min'] ?? null) ? (float) $filters['amount_min'] : null;
        $amountMax = is_numeric($filters['amount_max'] ?? null) ? (float) $filters['amount_max'] : null;

        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.project', 'p')
            ->leftJoin('p.user', 'u')
            ->addSelect('p', 'u');

        if ($search !== '') {
            $qb->andWhere('LOWER(COALESCE(p.titre, \'\')) LIKE :q OR LOWER(COALESCE(o.description, \'\')) LIKE :q OR LOWER(COALESCE(p.secteur, \'\')) LIKE :q OR LOWER(CONCAT(COALESCE(u.firstname, \'\'), \' \', COALESCE(u.lastname, \'\'))) LIKE :q OR LOWER(COALESCE(u.email, \'\')) LIKE :q OR LOWER(COALESCE(u.companyName, \'\')) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($search) . '%');
        }

        if ($statusFilter !== '' && in_array($statusFilter, ['OPEN', 'CLOSED', 'FUNDED'])) {
            $qb->andWhere('o.status = :st')->setParameter('st', $statusFilter);
        }

        if ($entrepreneurFilter !== '') {
            $qb->andWhere('LOWER(CONCAT(COALESCE(u.firstname, \'\'), \' \', COALESCE(u.lastname, \'\'))) LIKE :entrepreneur OR LOWER(COALESCE(u.email, \'\')) LIKE :entrepreneur OR LOWER(COALESCE(u.companyName, \'\')) LIKE :entrepreneur')
                ->setParameter('entrepreneur', '%' . mb_strtolower($entrepreneurFilter) . '%');
        }

        if ($sectorFilter !== '') {
            $qb->andWhere('LOWER(COALESCE(p.secteur, \'\')) LIKE :sector')
                ->setParameter('sector', '%' . mb_strtolower($sectorFilter) . '%');
        }

        if ($amountMin !== null) {
            $qb->andWhere('o.targetAmount >= :amountMin')->setParameter('amountMin', $amountMin);
        }

        if ($amountMax !== null) {
            $qb->andWhere('o.targetAmount <= :amountMax')->setParameter('amountMax', $amountMax);
        }

        if ($deadlineState !== '') {
            $today = new \DateTimeImmutable('today');
            $soonLimit = $today->modify('+30 days');

            if ($deadlineState === 'expired') {
                $qb->andWhere('o.deadline IS NOT NULL')->andWhere('o.deadline < :today')->setParameter('today', $today);
            } elseif ($deadlineState === 'expiring') {
                $qb->andWhere('o.deadline BETWEEN :today AND :soonLimit')
                    ->setParameter('today', $today)
                    ->setParameter('soonLimit', $soonLimit);
            } elseif ($deadlineState === 'active') {
                $qb->andWhere('o.deadline IS NULL OR o.deadline > :today')->setParameter('today', $today);
            } elseif ($deadlineState === 'none') {
                $qb->andWhere('o.deadline IS NULL');
            }
        }

        match ($sort) {
            'oldest' => $qb->orderBy('o.createdAt', 'ASC'),
            'amount_asc' => $qb->orderBy('o.targetAmount', 'ASC'),
            'amount_desc' => $qb->orderBy('o.targetAmount', 'DESC'),
            'deadline_asc' => $qb->orderBy('o.deadline', 'ASC'),
            'deadline_desc' => $qb->orderBy('o.deadline', 'DESC'),
            'project_asc' => $qb->orderBy('p.titre', 'ASC'),
            default => $qb->orderBy('o.createdAt', 'DESC'),
        };

        return $qb;
    }

    /**
     * DQL: Count opportunities by status.
     * @return array{total: int, open: int, closed: int, funded: int}
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status, COUNT(o.id) AS cnt')
            ->groupBy('o.status')
            ->getQuery()
            ->getResult();

        $counts = ['total' => 0, 'open' => 0, 'closed' => 0, 'funded' => 0];
        foreach ($rows as $row) {
            $counts[strtolower($row['status'])] = (int) $row['cnt'];
            $counts['total'] += (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * DQL: Get opportunities expiring within N days.
     */
    public function findExpiringSoon(int $days = 7): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.deadline BETWEEN :now AND :limit')
            ->setParameter('status', 'OPEN')
            ->setParameter('now', new \DateTime('today'))
            ->setParameter('limit', new \DateTime("+{$days} days"))
            ->orderBy('o.deadline', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count open opportunities grouped by risk score bracket.
     * @return array{total: int, low: int, medium: int, high: int}
     */
    public function countOpenByRiskBracket(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.riskScore')
            ->where('o.status = :status')
            ->setParameter('status', 'OPEN')
            ->getQuery()
            ->getResult();

        $counts = ['total' => 0, 'low' => 0, 'medium' => 0, 'high' => 0];
        foreach ($rows as $row) {
            $score = (int) ($row['riskScore'] ?? 50);
            $counts['total']++;
            if ($score <= 33) {
                $counts['low']++;
            } elseif ($score <= 66) {
                $counts['medium']++;
            } else {
                $counts['high']++;
            }
        }
        return $counts;
    }

    /**
     * Count open opportunities grouped by project sector.
     * @return array<string, int>  sector => count
     */
    public function countOpenBySector(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('p.secteur AS sector, COUNT(o.id) AS cnt')
            ->leftJoin('o.project', 'p')
            ->where('o.status = :status')
            ->setParameter('status', 'OPEN')
            ->groupBy('p.secteur')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $sector = $row['sector'] ?: 'Non defini';
            $result[$sector] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * Count open opportunities with short deadlines (< 6 months), useful as "cross-border" proxy.
     */
    public function countOpenShortDeadline(int $months = 6): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.status = :status')
            ->andWhere('o.deadline IS NOT NULL')
            ->andWhere('o.deadline <= :limit')
            ->setParameter('status', 'OPEN')
            ->setParameter('limit', new \DateTime("+{$months} months"))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
