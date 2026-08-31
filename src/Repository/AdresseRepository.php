<?php

namespace App\Repository;

use App\Entity\Adresse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Adresse>
 */
class AdresseRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Adresse::class);
    }

    public function searchAdresse(string $q, bool $forcedRue = true): array {
        $qb = $this->createQueryBuilder('a')
                ->where('( LOWER(a.name) LIKE :q')
                ->orWhere("LOWER (a.adresse) LIKE :q )")
                ->setParameter('q', '%' . strtolower($q) . '%');
        if ($forcedRue) {
            $qb->leftJoin('a.adresseType', 'c')
                    ->andWhere('c.name = :name')
                    ->setParameter("name", "Rue");
        }
        return $qb
                        ->setMaxResults(20)
                        ->getQuery()
                        ->getResult();
    }

    public function findAllOrdreredQueryBuilder($inclureRue = false): QueryBuilder {
        $qb = $this->createQueryBuilder('a')
                ->addOrderBy(
                        'CASE WHEN a.deletedAt IS NULL THEN 0 ELSE 1 END',
                        'ASC'
                )
                ->addOrderBy('a.adresseType', 'ASC')
                ->addOrderBy('a.name', 'ASC');

        if (!$inclureRue) {
            $qb
                    ->join('a.adresseType', 't')
                    ->andWhere('t.name != :name')
                    ->setParameter('name', 'Rue');
        }

        return $qb;
    }

    public function findAllOrdered(): array {
        return $this->findAllOrdreredQueryBuilder(true)
                        ->getQuery()
                        ->getResult();
    }
}
