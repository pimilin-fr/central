<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoFaker;
use App\Demo\TiersNamer;
use App\Demo\DemoStrategy;
use App\Entity\Tiers;
use App\Entity\TypeTiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tiers : copie, anonymisation (faux nom stable) ou exclusion selon le champ demoStrategy.
 *
 * Anonymisé : le nom ET le texte de recherche sont remplacés (le texte de recherche contient souvent
 * des alias / noms réels). Le faux nom dépend de la nature (case « personne » ou type listé dans
 * config/demo/tiers_naming.yaml) et du type de tiers : voir TiersNamer.
 */
class TiersTransformer extends AbstractDemoTransformer {

    public function __construct(
        #[Autowire(service: 'doctrine.orm.demo_entity_manager')] EntityManagerInterface $demoEm,
        DemoFaker $faker,
        private readonly TiersNamer $namer
    ) {
        parent::__construct($demoEm, $faker);
    }

    public function getOrder(): int { return 80; }

    public function getSourceClass(): string { return Tiers::class; }

    public function getLabel(): string { return 'Tiers'; }

    public function getSourceId(object $source): string|int {
        return $source->getId(); // uuid
    }

    public function configureQuery(\Doctrine\ORM\QueryBuilder $qb): void {
        $qb->addSelect('tt')->join('e.tiersType', 'tt');
    }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var Tiers $source */
        $type = $this->ref($context, TypeTiers::class, $source->getTiersType()->getId());
        if ($type === null) {
            return null;
        }

        $name = $source->getName();
        $searchText = $source->getSearchText();

        if ($strategy === DemoStrategy::ANONYMIZE) {
            $name = $this->namer->name($source->getTiersType(), $source->isPersonne(), $source->getId());
            $searchText = $name;
        }

        $target = new Tiers();
        $target->setName((string) $name)
            ->setSearchText($searchText)
            ->setTiersType($type)
            ->setPersonne($source->isPersonne())
            ->setCreatedAt($source->getCreatedAt())
            ->setDeletedAt($source->getDeletedAt())
            ->setDemoStrategy(DemoStrategy::COPY);

        $target->regenerateCode();

        return $target;
    }
}
