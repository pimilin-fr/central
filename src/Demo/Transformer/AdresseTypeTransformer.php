<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\AdresseType;

/** Types d'adresse : référentiel, toujours copié. */
class AdresseTypeTransformer extends AbstractDemoTransformer {

    public function getOrder(): int { return 20; }

    public function getSourceClass(): string { return AdresseType::class; }

    public function getLabel(): string { return 'Types d’adresse'; }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var AdresseType $source */
        return (new AdresseType())
            ->setName($source->getName())
            ->setColor($source->getColor());
    }
}
