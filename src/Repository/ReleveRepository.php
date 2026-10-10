<?php

namespace App\Repository;

use App\Entity\Portefeuille;
use App\Entity\Releve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Releve>
 */
class ReleveRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Releve::class);
    }

    public function findWithOperations(Portefeuille $portefeuille) {
        return $this->createQueryBuilder('r')
                        ->leftJoin('r.depenses', 'd')
                        ->addSelect('d')
                        ->andWhere('r.portefeuille = :p')
                        ->setParameter('p', $portefeuille)
                        ->orderBy('r.date', 'DESC')
                        ->getQuery()
                        ->getResult();
    }

    public function findLastByPortefeuille(Portefeuille $portefeuille): ?Releve {
        return $this->createQueryBuilder('r')
                        ->andWhere('r.portefeuille = :portefeuille')
                        ->setParameter('portefeuille', $portefeuille)
                        ->orderBy('r.date', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();
    }

    /**
     * Relevés non finalisés d'un portefeuille (à reprendre), du plus récent au plus ancien.
     *
     * @return list<Releve>
     */
    public function findOpen(Portefeuille $portefeuille): array {
        return $this->createQueryBuilder('r')
                        ->andWhere('r.portefeuille = :p')
                        ->andWhere('r.closedAt IS NULL')
                        ->setParameter('p', $portefeuille)
                        ->orderBy('r.date', 'DESC')
                        ->getQuery()
                        ->getResult();
    }

    /**
     * Relevés d'un portefeuille STRICTEMENT antérieurs à une date (ordre chronologique), avec opérations et catégories.
     *
     * @return list<Releve>
     */
    public function findBefore(Portefeuille $portefeuille, \DateTimeInterface $date, ?int $excludeId = null): array {
        $qb = $this->createQueryBuilder('r')
                ->leftJoin('r.depenses', 'd')
                ->leftJoin('d.categorie', 'c')
                ->addSelect('d', 'c')
                ->andWhere('r.portefeuille = :p')
                ->andWhere('r.date < :date')
                ->setParameter('p', $portefeuille)
                ->setParameter('date', $date, \Doctrine\DBAL\Types\Types::DATE_MUTABLE)
                ->orderBy('r.date', 'ASC')
                ->addOrderBy('r.id', 'ASC');
        if ($excludeId !== null) {
            $qb->andWhere('r.id != :self')->setParameter('self', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Tous les relevés d'un portefeuille, du plus ancien au plus récent, avec opérations et catégories.
     *
     * @return list<Releve>
     */
    public function findAllAsc(Portefeuille $portefeuille): array {
        return $this->createQueryBuilder('r')
                        ->leftJoin('r.depenses', 'd')
                        ->leftJoin('d.categorie', 'c')
                        ->addSelect('d', 'c')
                        ->andWhere('r.portefeuille = :p')
                        ->setParameter('p', $portefeuille)
                        ->orderBy('r.date', 'ASC')
                        ->addOrderBy('r.id', 'ASC')
                        ->getQuery()
                        ->getResult();
    }

    /**
     * Tous les relevés, tous portefeuilles confondus (ordre chronologique), avec leurs opérations.
     *
     * @return list<Releve>
     */
    public function findEveryAsc(): array {
        return $this->createQueryBuilder('r')
                        ->innerJoin('r.portefeuille', 'p')
                        ->leftJoin('r.depenses', 'd')
                        ->leftJoin('d.categorie', 'c')
                        ->addSelect('p', 'd', 'c')
                        ->orderBy('r.date', 'ASC')
                        ->addOrderBy('r.id', 'ASC')
                        ->getQuery()
                        ->getResult();
    }
}
