<?php

namespace App\Repository;

use App\Entity\Categorie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Categorie>
 */
class CategorieRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Categorie::class);
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

    public function search(string $q): array {
        return $this->createQueryBuilder('a')
                        ->where('LOWER(a.name) LIKE :q')
                        ->setParameter('q', '%' . strtolower($q) . '%')
                        ->getQuery()
                        ->getResult();
    }
}
