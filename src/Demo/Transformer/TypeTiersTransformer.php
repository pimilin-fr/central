<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\TypeTiers;
use Throwable;

/** Types de tiers : référentiel, toujours copié. */
class TypeTiersTransformer extends AbstractDemoTransformer {

    public function getOrder(): int { return 10; }

    public function getSourceClass(): string { return TypeTiers::class; }

    public function getLabel(): string { return 'Types de tiers'; }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var TypeTiers $source */
        $target = new TypeTiers();
        $target->setName($source->getName())
            ->setTypeN1($source->getTypeN1())->setCodeN1($source->getCodeN1())
            ->setTypeN2($source->getTypeN2())->setCodeN2($source->getCodeN2())
            ->setTypeN3($source->getTypeN3())->setCodeN3($source->getCodeN3())
            ->setCouleur($source->getCouleur())
            ->setLibelleLiserai($source->getLibelleLiserai());

        try {
            $target->computeFields(); // recalcule le code « TT-xxx-xxx-xxx »
        } catch (Throwable) {
            // nom non conforme (moins de 3 niveaux) : le code retombe sur l'id
        }

        return $target;
    }
}
