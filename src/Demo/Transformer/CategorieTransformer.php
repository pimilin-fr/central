<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\Categorie;

/** Catégories (arbre) : référentiel, toujours copié. */
class CategorieTransformer extends AbstractDemoTransformer {

    public function getOrder(): int { return 40; }

    public function getSourceClass(): string { return Categorie::class; }

    public function getLabel(): string { return 'Catégories'; }

    public function isHierarchical(): bool { return true; }

    public function getParentId(object $source): string|int|null {
        return $source->getParent()?->getId();
    }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var Categorie $source */
        $target = new Categorie();
        $target->setName($source->getName());

        if ($source->getLibelle() !== null) {
            $target->setLibelle($source->getLibelle());
        }
        if ($source->getNatureCode() !== null) {
            in_array($source->getNatureCode(), Categorie::NATURE_MAP, true)
                ? $target->setNature($source->getNatureCode()) // pose aussi natureLibelle
                : $target->setNatureCode($source->getNatureCode());
        }
        if ($source->getParent() !== null) {
            $target->setParent($this->ref($context, Categorie::class, $source->getParent()->getId()));
        }

        // pas de setters pour ces deux champs
        $this->setProp($target, 'createdAt', $source->getCreatedAt());
        $this->setProp($target, 'deletedAt', $source->getDeletedAt());

        return $target;
    }
}
