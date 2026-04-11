<?php

namespace App\Repository;

use App\Entity\PortefeuilleView;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/**
 * @extends ServiceEntityRepository<PortefeuilleView>
 */
class PortefeuilleViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortefeuilleView::class);
    }

    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->addOrderBy('p.is_default')    
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
