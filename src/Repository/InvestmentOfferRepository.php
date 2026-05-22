<?php
namespace App\Repository;

use App\Entity\InvestmentOffer;
use App\Entity\InvestmentOpportunity;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class InvestmentOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentOffer::class);
    }

    public function findByInvestor(mixed $user): array
    {
        return $this->findBy(['investor' => $user], ['id' => 'DESC']);
    }

    public function findUnpaidByInvestorQuery(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->where('o.investor = :investor')
            ->andWhere('o.paid = :paid')
            ->setParameter('investor', $user)
            ->setParameter('paid', false)
            ->orderBy('o.id', 'DESC');
    }

    public function findUnpaidByInvestor(User $user): array
    {
        return $this->findUnpaidByInvestorQuery($user)
            ->getQuery()
            ->getResult();
    }

    public function findPaidByInvestor(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.investor = :investor')
            ->andWhere('o.paid = :paid')
            ->setParameter('investor', $user)
            ->setParameter('paid', true)
            ->orderBy('o.paidAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByOpportunity(mixed $opp): array
    {
        return $this->findBy(['opportunity' => $opp], ['id' => 'DESC']);
    }

    public function findPendingForEntrepreneur(User $entrepreneur): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.opportunity', 'opp')
            ->innerJoin('opp.project', 'project')
            ->addSelect('opp', 'project')
            ->innerJoin('o.investor', 'investor')
            ->addSelect('investor')
            ->where('project.user = :entrepreneur')
            ->andWhere('o.status = :status')
            ->setParameter('entrepreneur', $entrepreneur)
            ->setParameter('status', InvestmentOffer::STATUS_PENDING)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPendingForEntrepreneur(User $entrepreneur): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->innerJoin('o.opportunity', 'opp')
            ->innerJoin('opp.project', 'project')
            ->where('project.user = :entrepreneur')
            ->andWhere('o.status = :status')
            ->setParameter('entrepreneur', $entrepreneur)
            ->setParameter('status', InvestmentOffer::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * DQL: Check if an investor already has an offer for a given opportunity.
     */
    public function findExistingOffer(User $investor, InvestmentOpportunity $opportunity): ?InvestmentOffer
    {
        return $this->createQueryBuilder('o')
            ->where('o.investor = :investor')
            ->andWhere('o.opportunity = :opp')
            ->setParameter('investor', $investor)
            ->setParameter('opp', $opportunity)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function sumAcceptedAmountsForOpportunity(InvestmentOpportunity $opportunity): float
    {
        $result = $this->createQueryBuilder('o')
            ->select('COALESCE(SUM(o.proposedAmount), 0)')
            ->where('o.opportunity = :opportunity')
            ->andWhere('o.status = :status')
            ->setParameter('opportunity', $opportunity)
            ->setParameter('status', InvestmentOffer::STATUS_ACCEPTED)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $result;
    }

    /**
     * DQL: Sum of all paid offers (total raised).
     */
    public function sumAcceptedAmounts(): float
    {
        $result = $this->createQueryBuilder('o')
            ->select('COALESCE(SUM(o.proposedAmount), 0)')
            ->where('o.paid = :paid')
            ->setParameter('paid', true)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $result;
    }

    /**
     * DQL: Build a filterable query for admin offers list.
     */
    public function buildFilteredQuery(array|string $filters = ''): QueryBuilder
    {
        if (!is_array($filters)) {
            $filters = ['status' => $filters];
        }

        $statusFilter = (string) ($filters['status'] ?? '');
        $search = trim((string) ($filters['q'] ?? ''));
        $investorFilter = trim((string) ($filters['investor'] ?? ''));
        $entrepreneurFilter = trim((string) ($filters['entrepreneur'] ?? ''));
        $paidFilter = (string) ($filters['paid'] ?? '');
        $sort = (string) ($filters['order'] ?? 'recent');
        $amountMin = is_numeric($filters['amount_min'] ?? null) ? (float) $filters['amount_min'] : null;
        $amountMax = is_numeric($filters['amount_max'] ?? null) ? (float) $filters['amount_max'] : null;
        $createdFrom = $filters['created_from'] ?? null;
        $createdTo = $filters['created_to'] ?? null;

        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.opportunity', 'opp')
            ->leftJoin('opp.project', 'p')
            ->leftJoin('p.user', 'ent')
            ->leftJoin('o.investor', 'inv')
            ->addSelect('opp', 'p', 'ent', 'inv');

        if ($statusFilter !== '' && in_array($statusFilter, ['PENDING', 'ACCEPTED', 'REJECTED'])) {
            $qb->andWhere('o.status = :st')->setParameter('st', $statusFilter);
        }

        if ($search !== '') {
            $needle = '%' . mb_strtolower($search) . '%';
            $qb->andWhere('LOWER(COALESCE(p.titre, \'\')) LIKE :q OR LOWER(CONCAT(COALESCE(inv.firstname, \'\'), \' \', COALESCE(inv.lastname, \'\'))) LIKE :q OR LOWER(COALESCE(inv.email, \'\')) LIKE :q OR LOWER(CONCAT(COALESCE(ent.firstname, \'\'), \' \', COALESCE(ent.lastname, \'\'))) LIKE :q OR LOWER(COALESCE(ent.email, \'\')) LIKE :q OR LOWER(COALESCE(ent.companyName, \'\')) LIKE :q')
                ->setParameter('q', $needle);
        }

        if ($investorFilter !== '') {
            $qb->andWhere('LOWER(CONCAT(COALESCE(inv.firstname, \'\'), \' \', COALESCE(inv.lastname, \'\'))) LIKE :investor OR LOWER(COALESCE(inv.email, \'\')) LIKE :investor OR LOWER(COALESCE(inv.companyName, \'\')) LIKE :investor')
                ->setParameter('investor', '%' . mb_strtolower($investorFilter) . '%');
        }

        if ($entrepreneurFilter !== '') {
            $qb->andWhere('LOWER(CONCAT(COALESCE(ent.firstname, \'\'), \' \', COALESCE(ent.lastname, \'\'))) LIKE :entrepreneur OR LOWER(COALESCE(ent.email, \'\')) LIKE :entrepreneur OR LOWER(COALESCE(ent.companyName, \'\')) LIKE :entrepreneur')
                ->setParameter('entrepreneur', '%' . mb_strtolower($entrepreneurFilter) . '%');
        }

        if ($paidFilter === 'paid') {
            $qb->andWhere('o.paid = :paid')->setParameter('paid', true);
        } elseif ($paidFilter === 'unpaid') {
            $qb->andWhere('o.paid = :paid')->setParameter('paid', false);
        }

        if ($amountMin !== null) {
            $qb->andWhere('o.proposedAmount >= :amountMin')->setParameter('amountMin', $amountMin);
        }

        if ($amountMax !== null) {
            $qb->andWhere('o.proposedAmount <= :amountMax')->setParameter('amountMax', $amountMax);
        }

        if (is_string($createdFrom) && $createdFrom !== '') {
            $qb->andWhere('o.createdAt >= :createdFrom')
                ->setParameter('createdFrom', new \DateTimeImmutable($createdFrom . ' 00:00:00'));
        }

        if (is_string($createdTo) && $createdTo !== '') {
            $qb->andWhere('o.createdAt <= :createdTo')
                ->setParameter('createdTo', new \DateTimeImmutable($createdTo . ' 23:59:59'));
        }

        match ($sort) {
            'oldest' => $qb->orderBy('o.createdAt', 'ASC'),
            'amount_asc' => $qb->orderBy('o.proposedAmount', 'ASC'),
            'amount_desc' => $qb->orderBy('o.proposedAmount', 'DESC'),
            'investor_asc' => $qb->orderBy('inv.firstname', 'ASC')->addOrderBy('inv.lastname', 'ASC'),
            default => $qb->orderBy('o.createdAt', 'DESC'),
        };

        return $qb;
    }

    /**
     * DQL: Count offers grouped by status.
     * @return array{total: int, pending: int, accepted: int, rejected: int}
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status, COUNT(o.id) AS cnt')
            ->groupBy('o.status')
            ->getQuery()
            ->getResult();

        $counts = ['total' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $counts[strtolower($row['status'])] = (int) $row['cnt'];
            $counts['total'] += (int) $row['cnt'];
        }
        return $counts;
    }
}
