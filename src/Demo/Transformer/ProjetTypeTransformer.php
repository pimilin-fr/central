<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\ProjetType;

/** Types de projet (arbre) : référentiel, toujours copié. */
class ProjetTypeTransformer extends AbstractDemoTransformer {

    public function getOrder(): int { return 30; }

    public function getSourceClass(): string { return ProjetType::class; }

    public function getLabel(): string { return 'Types de projet'; }

    public function isHierarchical(): bool { return true; }

    public function getParentId(object $source): string|int|null {
        return $source->getParent()?->getId();
    }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var ProjetType $source */
        $target = new ProjetType();
        $target->setName($source->getName())->setLibelle($source->getLibelle());

        if ($source->getParent() !== null) {
            $target->setParent($this->ref($context, ProjetType::class, $source->getParent()->getId()));
        }

        return $target;
    }
}
