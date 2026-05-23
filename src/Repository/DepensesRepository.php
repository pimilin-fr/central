<?php

namespace App\Repository;

use App\Entity\Depenses;
use App\Entity\Projet;
use App\Entity\Tiers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Depenses>
 */
class DepensesRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Depenses::class);
    }
    
    public function findByTiers(Tiers $tiers){
//        $depRepo->findBy(['tiers'=>$tiers],["date"=>"DESC"])
        return $this->createQueryBuilder('d')
                //->innerJoin('d.Tiers', 't')
                ->innerJoin('d.portefeuille', 'p')
                ->andWhere('p.isReal = :reel')
                ->andWhere('d.tiers = :tiers')
                ->addOrderBy('d.date',"DESC")
                ->setParameter('reel', true)
                ->setParameter('tiers', $tiers)
                ->getQuery()
                ->getResult();
    }
    
    public function findByProjet(Projet $projet){
        return $this->createQueryBuilder('d')
                //->innerJoin('d.Tiers', 't')
                ->innerJoin('d.portefeuille', 'p')
                ->andWhere('p.isReal = :reel')
                ->andWhere('d.projet = :projet')
                ->addOrderBy('d.date',"DESC")
                ->setParameter('reel', true)
                ->setParameter('projet', $projet)
                ->getQuery()
                ->getResult();
    }
}
