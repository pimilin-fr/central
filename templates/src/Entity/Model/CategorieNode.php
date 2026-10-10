<?php

namespace App\Entity\Model;

use App\Entity\Categorie;

class CategorieNode {

    private Categorie $categorie;
    private array $children = [];
    private int $level;

    public function __construct(Categorie $categorie, array $children = [], int $level = 0) {
        $this->categorie = $categorie;
        $this->children = $children;
        $this->level = $level;
    }

    public function getCategorie(): Categorie {
        return $this->categorie;
    }

    public function getChildren(): array {
        return $this->children;
    }

    public function getLevel(): int {
        return $this->level;
    }
}
