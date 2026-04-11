<?php

namespace App\Repository;

use App\Entity\Tiers;
use App\Entity\TiersAdresse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tiers>
 */
class TiersAdresseRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, TiersAdresse::class);
    }

    public function findByTiersOrdered(Tiers $tiers): array {
        return $this->createQueryBuilder('ta')
                        ->leftJoin('ta.adresse', 'a')
                        ->addSelect('a')
                        ->where('ta.tiers = :tiers')
                        ->setParameter('tiers', $tiers)
                        ->orderBy('ta.isPrincipale', 'DESC')
                        ->addOrderBy('a.name', 'ASC')
//                        ->addOrderBy('a.id', 'ASC')
                        ->getQuery()
                        ->getResult();
    }

    //    /**
    //     * @return Tiers[] Returns an array of Tiers objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('t.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }
    //    public function findOneBySomeField($value): ?Tiers
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
