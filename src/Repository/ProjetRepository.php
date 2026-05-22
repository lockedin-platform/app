<?php
namespace App\Repository;

use App\Entity\Projet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Projet::class); }

    public function findBySearch(string $search): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.titre LIKE :q OR p.description LIKE :q OR p.secteur LIKE :q')
            ->setParameter('q', '%' . $search . '%');
    }

    /**
     * Retourne un QueryBuilder pour tous les projets dont l'utilisateur existe encore (user IS NOT NULL).
     */
    public function createQueryBuilderWithUser(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->where('p.user IS NOT NULL');
    }

    public function findByUserWithFilters(mixed $user, ?string $search = null, ?string $secteur = null, string $sort = 'dateCreation', string $direction = 'DESC'): array
    {
        $allowedSorts = ['dateCreation', 'titre', 'secteur', 'statutProjet', 'scoreGlobal'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'dateCreation';
        }
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $qb = $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user);

        if ($search) {
            $qb->andWhere('p.titre LIKE :q OR p.description LIKE :q OR p.secteur LIKE :q')
               ->setParameter('q', '%'.$search.'%');
        }

        if ($secteur) {
            $qb->andWhere('p.secteur = :secteur')
               ->setParameter('secteur', $secteur);
        }

        return $qb->orderBy('p.'.$sort, $direction)
                   ->getQuery()
                   ->getResult();
    }

    /** @return Projet[] */
    public function findByUser(mixed $user): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
