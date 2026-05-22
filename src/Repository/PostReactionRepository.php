<?php
namespace App\Repository;
use App\Entity\PostReaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class PostReactionRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, PostReaction::class); }
    public function findByPostAndUser(mixed $post, mixed $user): ?PostReaction {
        return $this->findOneBy(['post' => $post, 'user' => $user]);
    }
}
