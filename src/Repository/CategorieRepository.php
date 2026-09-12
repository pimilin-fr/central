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

    public function findAllOrdered(): array {
        return $this->createQueryBuilder('c')
                        //->andWhere('c.deletedAt IS NULL')
                        ->orderBy('c.name', 'ASC')
                        ->getQuery()
                        ->getResult();
    }

    public function findHierarchy(Categorie $categorie): array {
        $categories = $this->findAllOrdered();

        $childrenByParent = [];

        foreach ($categories as $category) {
            $parentId = $category->getParent()?->getId();

            if ($parentId !== null) {
                $childrenByParent[$parentId][] = $category;
            }
        }

        $hierarchy = [];

        $collectDescendants = function (Categorie $category) use (
                &$collectDescendants,
                &$hierarchy,
                $childrenByParent
        ): void {
            $hierarchy[$category->getId()] = $category;

            foreach ($childrenByParent[$category->getId()] ?? [] as $child) {
                $collectDescendants($child);
            }
        };

        $collectDescendants($categorie);

        return array_values($hierarchy);
    }

    public function search(string $q): array {
        return $this->createQueryBuilder('a')
                        ->where('LOWER(a.name) LIKE :q')
                        ->setParameter('q', '%' . strtolower($q) . '%')
                        ->getQuery()
                        ->getResult();
    }
}
