<?php
namespace App\Repository;
use App\Entity\Cours;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class CoursRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Cours::class); }
    public function findBySearch(string $q): \Doctrine\ORM\QueryBuilder {
        return $this->createQueryBuilder('c')->where('c.titre LIKE :q OR c.categorie LIKE :q')->setParameter('q', '%'.$q.'%');
    }
    public function countCertifiants(): int { return $this->count(['certification' => true]); }
    public function sumXpPoints(): int {
        return (int) $this->createQueryBuilder('c')->select('SUM(c.pointsXp)')->getQuery()->getSingleScalarResult();
    }
}
