<?php

namespace App\Repository;

use App\Entity\Projet;
use App\Entity\ProjetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Projet>
 */
class ProjetRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Projet::class);
    }

    public function findByProjetType(ProjetType $type): array {
//        $typeRepo = $this->getEntityManager()
//                ->getRepository(ProjetType::class);

        $allTypes = $type->getAllDescendants($type);

        return $this->createQueryBuilder('p')
                        ->leftJoin('p.type', 't')
                        ->addSelect('t')
                        ->where('p.type IN (:types)')
                        ->setParameter('types', $allTypes)
                        ->getQuery()
                        ->getResult();
    }

    public function search(string $q): array {
        return $this->createQueryBuilder('a')
                        ->where('LOWER(a.name) LIKE :q')
                        ->andWhere('a.beginAt <= :now')
                        ->andWhere('(a.endAt IS NULL OR a.endAt >= :now)')
                        ->setParameter('q', '%' . strtolower($q) . '%')
                        ->setParameter('now', new \DateTime())
                        ->getQuery()
                        ->getResult();
    }
}
