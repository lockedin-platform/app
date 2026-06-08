<?php
namespace App\Repository;

use App\Entity\InvestorApplication;
use App\Entity\InvestorPosting;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestorApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestorApplication::class);
    }

    public function findByPosting(InvestorPosting $posting): array
    {
        return $this->findBy(['posting' => $posting], ['createdAt' => 'DESC']);
    }

    public function findByEntrepreneur(User $entrepreneur): array
    {
        return $this->findBy(['entrepreneur' => $entrepreneur], ['createdAt' => 'DESC']);
    }

    public function findExisting(InvestorPosting $posting, User $entrepreneur): ?InvestorApplication
    {
        return $this->findOneBy(['posting' => $posting, 'entrepreneur' => $entrepreneur]);
    }

    public function countPendingForInvestor(User $investor): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.posting', 'p')
            ->where('p.postedBy = :investor')
            ->andWhere('a.status = :status')
            ->setParameter('investor', $investor)
            ->setParameter('status', InvestorApplication::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
