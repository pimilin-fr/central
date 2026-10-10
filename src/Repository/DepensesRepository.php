<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\Projet;
use App\Entity\Tiers;
use App\Entity\TypeTiers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Depenses>
 */
class DepensesRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Depenses::class);
    }

    private function findRealQueryBuilder($alias = "d"): QueryBuilder {
        return $this->createQueryBuilder($alias)
                        ->join($alias . ".portefeuille", "p")
                        ->andWhere('p.isReal = :isReal')
                        ->setParameter('isReal', true)
                        ->orderBy($alias . '.date', 'DESC')
                        ->addOrderBy($alias . '.id', 'DESC');
    }

    public function findByCategories(array $categories): array {
        $categoryIds = array_map(
                fn(Categorie $categorie) => $categorie->getId(),
                $categories
        );

        return $this->findRealQueryBuilder()
                        ->andWhere('d.categorie IN (:categories)')
                        ->setParameter('categories', $categoryIds)
                        ->getQuery()
                        ->getResult();
    }

    public function findByTiers(Tiers $tiers) {
        return $this->findRealQueryBuilder()
                        ->andWhere('d.tiers = :tiers')
                        ->setParameter('tiers', $tiers)
                        ->getQuery()
                        ->getResult();
    }

    public function findByTypeTiers(TypeTiers $typeTiers) {
        return $this->findRealQueryBuilder()
                        ->join('d.tiers', 't')
                        ->andWhere('t.tiersType = :typetiers')
                        ->setParameter('typetiers', $typeTiers)
                        ->getQuery()
                        ->getResult();
    }

    public function findByProjet(Projet $projet) {
        return $this->findRealQueryBuilder()
                        ->andWhere('d.projet = :prj')
                        ->setParameter('prj', $projet)
                        ->getQuery()
                        ->getResult();
    }

    /**
     * Opérations d'un portefeuille qui ne sont dans aucun relevé : le « stock » à pointer,
     * dans l'ordre chronologique (celui dans lequel on les retrouve en général sur un relevé).
     *
     * @return list<Depenses>
     */
    public function findUnreleved(Portefeuille $portefeuille): array {
        return $this->createQueryBuilder('d')
                        ->addSelect('t', 'c')
                        ->join('d.tiers', 't')
                        ->join('d.categorie', 'c')
                        ->andWhere('d.portefeuille = :p')
                        ->andWhere('d.releve IS NULL')
                        ->setParameter('p', $portefeuille)
                        ->orderBy('d.date', 'ASC')
                        ->addOrderBy('d.id', 'ASC')
                        ->getQuery()
                        ->getResult();
    }
}
