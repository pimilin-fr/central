<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\Portefeuille;
use App\Entity\Releve;
use Doctrine\ORM\QueryBuilder;

/** Relevés : suivent leur portefeuille (exclu => exclu, anonymisé => libellé neutre). */
class ReleveTransformer extends AbstractDemoTransformer {

    public function getOrder(): int { return 100; }

    public function getSourceClass(): string { return Releve::class; }

    public function getLabel(): string { return 'Relevés'; }

    public function configureQuery(QueryBuilder $qb): void {
        $qb->addSelect('p')->join('e.portefeuille', 'p');
    }

    public function strategyOf(object $source, DemoContext $context): DemoStrategy {
        /** @var Releve $source */
        return $context->strategyOf(Portefeuille::class, $source->getPortefeuille()->getId()) ?? DemoStrategy::COPY;
    }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var Releve $source */
        $portefeuille = $this->ref($context, Portefeuille::class, $source->getPortefeuille()->getId());
        if ($portefeuille === null) {
            return null;
        }

        $date = $context->asDateTime($source->getDate());
        $label = $source->getLabel();
        if ($strategy === DemoStrategy::ANONYMIZE && $label !== null) {
            $label = 'Relevé' . ($date ? ' du ' . $date->format('d/m/Y') : '');
        }

        return (new Releve())
            ->setDate($date)
            ->setLabel($label)
            ->setClosedAt($context->asImmutable($source->getClosedAt()))
            ->setPortefeuille($portefeuille);
    }
}
