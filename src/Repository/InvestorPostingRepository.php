<?php
namespace App\Repository;

use App\Entity\InvestorPosting;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class InvestorPostingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestorPosting::class);
    }

    public function findOpen(): array
    {
        return $this->findBy(['status' => InvestorPosting::STATUS_OPEN], ['createdAt' => 'DESC']);
    }

    public function searchOpenQuery(string $search = '', string $sort = 'recent'): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.postedBy', 'u')
            ->where('p.status = :status')
            ->setParameter('status', InvestorPosting::STATUS_OPEN);

        if ($search !== '') {
            $qb->andWhere('p.sector LIKE :q OR p.description LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }

        match ($sort) {
            'budget_asc' => $qb->orderBy('p.budgetMax', 'ASC'),
            'budget_desc' => $qb->orderBy('p.budgetMax', 'DESC'),
            'deadline' => $qb->orderBy('p.deadline', 'ASC'),
            default => $qb->orderBy('p.createdAt', 'DESC'),
        };

        return $qb;
    }

    public function findByInvestor(User $investor): array
    {
        return $this->findBy(['postedBy' => $investor], ['createdAt' => 'DESC']);
    }
}
