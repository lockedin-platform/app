<?php
namespace App\Repository;
use App\Entity\Progression;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class ProgressionRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Progression::class); }
    public function findByUser(mixed $user): array { return $this->findBy(['user' => $user], ['dateDebut' => 'DESC']); }
}
