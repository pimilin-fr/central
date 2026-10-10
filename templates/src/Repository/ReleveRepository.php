<?php

namespace App\Repository;

use App\Entity\Portefeuille;
use App\Entity\Releve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Releve>
 */
class ReleveRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Releve::class);
    }

    public function findWithOperations(Portefeuille $portefeuille) {
        return $this->createQueryBuilder('r')
                        ->leftJoin('r.depenses', 'd')
                        ->addSelect('d')
                        ->andWhere('r.portefeuille = :p')
                        ->setParameter('p', $portefeuille)
                        ->orderBy('r.date', 'DESC')
                        ->getQuery()
                        ->getResult();
    }

    public function findLastByPortefeuille(Portefeuille $portefeuille): ?Releve {
        return $this->createQueryBuilder('r')
                        ->andWhere('r.portefeuille = :portefeuille')
                        ->setParameter('portefeuille', $portefeuille)
                        ->orderBy('r.date', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();
    }
}
