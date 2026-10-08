<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\Adresse;
use App\Entity\Tiers;
use App\Entity\TiersAdresse;
use Doctrine\ORM\QueryBuilder;

/** Liens tiers ↔ adresse : conservés si le tiers ET l'adresse le sont. */
class TiersAdresseTransformer extends AbstractDemoTransformer {

    public function getOrder(): int { return 90; }

    public function getSourceClass(): string { return TiersAdresse::class; }

    public function getLabel(): string { return 'Liens tiers ↔ adresse'; }

    public function configureQuery(QueryBuilder $qb): void {
        $qb->addSelect('t', 'a')->join('e.tiers', 't')->join('e.adresse', 'a');
    }

    public function strategyOf(object $source, DemoContext $context): DemoStrategy {
        /** @var TiersAdresse $source */
        $tiers = $context->strategyOf(Tiers::class, $source->getTiers()->getId());
        $adresse = $context->strategyOf(Adresse::class, $source->getAdresse()->getId());

        return ($tiers === DemoStrategy::EXCLUDE || $adresse === DemoStrategy::EXCLUDE)
            ? DemoStrategy::EXCLUDE
            : DemoStrategy::COPY;
    }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var TiersAdresse $source */
        $tiers = $this->ref($context, Tiers::class, $source->getTiers()->getId());
        $adresse = $this->ref($context, Adresse::class, $source->getAdresse()->getId());
        if ($tiers === null || $adresse === null) {
            return null;
        }

        return (new TiersAdresse())
            ->setTiers($tiers)
            ->setAdresse($adresse)
            ->setIsPrincipale($source->getIsPrincipale());
    }
}
