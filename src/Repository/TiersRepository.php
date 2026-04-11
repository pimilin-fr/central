<?php

namespace App\Repository;

use App\Entity\Tiers;
use App\Entity\TypeTiers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tiers>
 */
class TiersRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Tiers::class);
    }

    public function findAllWithAdresses() {
        return $this->createQueryBuilder('t')
                        // jointure vers la table pivot
                        ->leftJoin(
                                't.tiersAdresses',
                                'ta',
                                'WITH',
                                'ta.isPrincipale = true'
                        )
                        // jointure vers l'adresse réelle
                        ->leftJoin('ta.adresse', 'a')
                        ->addSelect('ta', 'a')
                        // tri par nom de tiers
                        ->addOrderBy('CASE WHEN t.deletedAt IS NULL THEN 0 ELSE 1 END', 'ASC')
                        ->addOrderBy('t.name', 'ASC')
                        ->getQuery()
                        ->getResult();
    }

    public function findByTiersType(TypeTiers $typeTiers) {
        return $this->createQueryBuilder('t')
                        ->andWhere('t.tiersType = :typeTiers')
                        ->setParameter('typeTiers', $typeTiers)
                        ->orderBy('t.name', 'ASC')
                        ->getQuery()
                        ->getResult();
    }

    public function search(string $q): array {
        return $this->createQueryBuilder('a')
                        ->where('LOWER(a.name) LIKE :q')
                        ->andWhere('a.createdAt <= :now')
                        ->andWhere('(a.deletedAt IS NULL OR a.deletedAt >= :now)')
                        ->setParameter('q', '%' . strtolower($q) . '%')
                        ->setParameter('now', new \DateTime())
                        ->getQuery()
                        ->getResult();
    }
}
