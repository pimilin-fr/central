<?php

namespace App\Repository;

use App\Entity\Portefeuille;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Portefeuille>
 */
class PortefeuilleRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Portefeuille::class); 
    }

    public function findAllOrdered($all = false) {
        $qb = $this->createQueryBuilder('p')
            ->addOrderBy('p.isDefault',"DESC")    
            ->addOrderBy('p.name', 'ASC');
        if(!$all){
            $qb->andWhere("p.deleted is NULL");
        }
            
        return $qb;
        
    }
}
