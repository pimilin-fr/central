<?php

namespace App\Repository;

use App\Entity\Depenses;
use App\Entity\Projet;
use App\Entity\Tiers;
use App\Entity\TypeTiers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Depenses>
 */
class DepensesRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Depenses::class);
    }
    
    private function findRealQueryBuilder($alias = "d") : QueryBuilder {
        return $this->createQueryBuilder($alias)
                        ->join($alias . ".portefeuille", "p")
                        ->andWhere('p.isReal = :isReal')
                        ->setParameter('isReal', true)
                        ->orderBy($alias . '.date', 'DESC')
                        ->addOrderBy($alias . '.id', 'DESC');
    }


    public function findByTiers(Tiers $tiers){
        return $this->findRealQueryBuilder()
                ->andWhere('d.tiers = :tiers')
                ->setParameter('tiers', $tiers)
                ->getQuery()
                ->getResult();
    }
    
    public function findByTypeTiers(TypeTiers $typeTiers) {
        return $this->findRealQueryBuilder()   
                ->join('d.tiers','t')
                ->andWhere('t.tiersType = :typetiers')
                ->setParameter('typetiers', $typeTiers)
                ->getQuery()
                ->getResult();
    }


    public function findByProjet(Projet $projet){
        return $this->findRealQueryBuilder()
                ->andWhere('d.projet = :prj') 
                ->setParameter('prj', $projet)
                ->getQuery()
                ->getResult();
    }
}
