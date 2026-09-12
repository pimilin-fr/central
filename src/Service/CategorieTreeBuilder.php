<?php
namespace App\Service;

use App\Entity\Model\CategorieNode;

class CategorieTreeBuilder{    
    public function build(array $categories): array{
        $childrenByParent = [];
        $roots = [];

        foreach ($categories as $category) {
            $parentId = $category->getParent()?->getId();

            if ($parentId === null) {
                $roots[] = $category;
            } else {
                $childrenByParent[$parentId][] = $category;
            }
        }

        return $this->buildNodes($roots, $childrenByParent);
    }

    private function buildNodes( array $categories,array $childrenByParent, int $level = 0): array {
        $nodes = [];

        foreach ($categories as $category) {
            $nodes[] = new CategorieNode(
                categorie: $category,
                children: $this->buildNodes($childrenByParent[$category->getId()] ?? [],$childrenByParent,$level + 1),
                level: $level,
            );
        }

        return $nodes;
    }
}

