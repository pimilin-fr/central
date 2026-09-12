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
        $categories = $this->findAllOrdered();

        $tree = [];
        $children = [];

        foreach ($categories as $category) {
            $parentId = $category->getParent()?->getId();

            if ($parentId === null) {
                $tree[] = $category;
            } else {
                $children[$parentId][] = $category;
            }
        }

        return $this->attachChildren($tree, $children);
    }

    private function attachChildren(array $nodes, array $children): array {
        foreach ($nodes as $node) {
            $node->viewChildren = $this->attachChildren(
                    $children[$node->getId()] ?? [],
                    $children
            );
        }

        return $nodes;
    }

    public function findAllOrdered(): array {
        return $this->createQueryBuilder('c')
                        //->andWhere('c.deletedAt IS NULL')
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
