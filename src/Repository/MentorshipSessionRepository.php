<?php
namespace App\Repository;
use App\Entity\MentorshipSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class MentorshipSessionRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, MentorshipSession::class); }

    public function findByUser(mixed $user): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.mentorshipRequest', 'r')
            ->where('r.mentor = :user OR r.entrepreneur = :user')
            ->setParameter('user', $user)
            ->orderBy('s.scheduledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
