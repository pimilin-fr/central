<?php

namespace App\Repository;

use App\Entity\ProjetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjetType>
 */
class ProjetTypeRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, ProjetType::class);
    }

    public function findTree(): array {
        return $this->createQueryBuilder('c')
                        ->leftJoin('c.children', 'ch')
                        ->addSelect('ch')
                        ->andWhere('c.parent IS NULL')
                        ->orderBy('c.name', 'ASC')
                        ->getQuery()
                        ->getResult();
    }
}
