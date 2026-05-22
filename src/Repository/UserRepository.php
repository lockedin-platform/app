<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function countByRole(string $role): int
    {
        return $this->count(['role' => $role]);
    }

    public function countBanned(): int
    {
        return $this->count(['isBanned' => true]);
    }

    public function countVerified(): int
    {
        return $this->count(['verified' => true]);
    }

    public function findBySearch(string $query): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('u')
            ->where('u.firstname LIKE :q OR u.lastname LIKE :q OR u.email LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('u.createdAt', 'DESC');
    }

    /**
     * @return User[]
     */
    public function findFaceRegisteredUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.faceRegistered = true')
            ->andWhere('u.faceEncoding IS NOT NULL')
            ->andWhere('u.isActive = true')
            ->andWhere('u.isBanned = false')
            ->getQuery()
            ->getResult();
    }
}
