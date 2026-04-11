<?php

namespace App\Repository;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Depenses>
 */
class DepensesRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Depenses::class);
    }

//    public function findAllOrdered(int $page = 1, int $limit = 200): array {
//        return $this->createQueryBuilder('d')
//                        ->addOrderBy('d.date', 'DESC')
//                        ->addOrderBy('d.id', 'DESC')
//                        ->setFirstResult(($page - 1) * $limit)
//                        ->setMaxResults($limit)
//                        ->getQuery()
//                        ->getResult();
//    }
//
//    public function findNonReleveByPortefeuille(Portefeuille $portefeuille): array {
//        return $this->createQueryBuilder('o')
//                        ->where('o.portefeuille = :ptf')
//                        ->andWhere('o.releve IS NULL')
//                        ->setParameter('ptf', $portefeuille)
//                        ->orderBy('o.date', 'DESC')
//                        ->getQuery()
//                        ->getResult();
//    }
//
//    public function findTemp(Portefeuille $portefeuille) {
//        return $this->createQueryBuilder('d')
//                        ->join('d.categorie', 'c')
//                        ->join('d.tiers', "t")
//                        ->join('d.portefeuille', 'p')
//                        ->leftJoin('d.projet', 'prj')
//                        ->andWhere('d.portefeuille = :p')
//                        ->andWhere('d.releve = :r ')
//                        ->setParameter('p', $portefeuille)
//                        ->setParameter('r', 16)
//                        ->orderBy('d.date', 'ASC')
//                        ->addOrderBy('d.id', 'DESC')
//                        ->getQuery()
//                        ->getResult();
//    }
//
//    public function countAll(): int {
//        return (int) $this->createQueryBuilder('l')
//                        ->select('COUNT(l.id)')
//                        ->getQuery()
//                        ->getSingleScalarResult();
//    }
}
