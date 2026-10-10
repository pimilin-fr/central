<?php

namespace App\Repository;

use App\Entity\TypeTiers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TypeTiers>
 */
class TypeTiersRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, TypeTiers::class);
    }

    public function findAllOrdered() {
        return $this->createQueryBuilder('tt')
                        ->orderBy('tt.typeN1', "ASC")
                        ->addOrderBy('tt.typeN2', "ASC")
                        ->addOrderBy('tt.typeN3', "ASC")
                        ->getQuery()
                        ->getResult();
    }
}
