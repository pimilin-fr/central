<?php

namespace App\Repository;

use App\Entity\OperationView;
use App\Entity\Portefeuille;
use App\Entity\Releve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OperationView>
 */
class OperationViewRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, OperationView::class);
    }

    

    public function findByReleve(Releve $releve): array {
        return $this->createQueryBuilder('o')
                        ->where('o.releveId = :releve')
                        ->setParameter('releve', $releve->getId())
                        ->orderBy('o.date', 'ASC')
                        ->getQuery()
                        ->getResult();
    }
}
