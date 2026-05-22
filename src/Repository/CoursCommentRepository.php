<?php
namespace App\Repository;
use App\Entity\CoursComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class CoursCommentRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, CoursComment::class); }
    public function findByCours(mixed $cours): array { return $this->findBy(['cours' => $cours], ['createdAt' => 'DESC']); }
}
