<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Model\CategorieNode;

class CategorieTreeBuilder {

    public function build(array $categories,?Categorie $root = null): array {
        $childrenByParent = [];

        foreach ($categories as $category) {
            $parentId = $category->getParent()?->getId();

            if ($parentId !== null) {
                $childrenByParent[$parentId][] = $category;
            }
        }

        if ($root !== null) {
            return $this->buildNodes(
                            [$root],
                            $childrenByParent
                    );
        }

        $roots = [];

        foreach ($categories as $category) {
            if ($category->getParent() === null) {
                $roots[] = $category;
            }
        }

        return $this->buildNodes($roots, $childrenByParent);
    }

    private function buildNodes(
            array $categories,
            array $childrenByParent,
            int $level = 0
    ): array {
        $nodes = [];

        foreach ($categories as $category) {
            $nodes[] = new CategorieNode(
                    categorie: $category,
                    children: $this->buildNodes(
                            $childrenByParent[$category->getId()] ?? [],
                            $childrenByParent,
                            $level + 1
                    ),
                    level: $level,
            );
        }

        return $nodes;
    }
}
