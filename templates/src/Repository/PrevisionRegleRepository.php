<?php

namespace App\Repository;

use App\Entity\PrevisionRegle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PrevisionRegle> */
class PrevisionRegleRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, PrevisionRegle::class);
    }

    /** @return list<PrevisionRegle> */
    public function findAllWithTranches(): array {
        return $this->createQueryBuilder('r')
                        ->leftJoin('r.tranches', 't')
                        ->addSelect('t')
                        ->orderBy('r.actif', 'DESC')
                        ->addOrderBy('r.libelle', 'ASC')
                        ->getQuery()
                        ->getResult();
    }
}
