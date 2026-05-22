<?php
namespace App\Repository;
use App\Entity\MentorAvailability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class MentorAvailabilityRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, MentorAvailability::class); }
    public function findByMentor(mixed $mentor): array { return $this->findBy(['mentor' => $mentor], ['date' => 'ASC']); }
}
