<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\Tiers;
use App\Entity\TypeTiers;

/**
 * Tiers : copie, anonymisation (faux nom stable) ou exclusion selon le champ demoStrategy.
 *
 * Anonymisé : le nom ET le texte de recherche sont remplacés (le texte de recherche contient souvent
 * des alias / noms réels). Particulier ou société : déduit du nom du type de tiers (PERSON_HINTS).
 */
class TiersTransformer extends AbstractDemoTransformer {

    /** Mots du libellé de type (N1/N2/N3) indiquant une personne physique => « Prénom Nom ». */
    private const PERSON_HINTS = '/particulier|personne|physique|famille|proche|ami|salari|privé|prive|individu/iu';

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
            $isPerson = (bool) preg_match(self::PERSON_HINTS, $source->getTiersType()->getName());
            $name = $isPerson
                ? $this->faker->personName($source->getId())
                : $this->faker->companyName($source->getId());
            $searchText = $name;
        }

        $target = new Tiers();
        $target->setName((string) $name)
            ->setSearchText($searchText)
            ->setTiersType($type)
            ->setCreatedAt($source->getCreatedAt())
            ->setDeletedAt($source->getDeletedAt())
            ->setDemoStrategy(DemoStrategy::COPY);

        $target->regenerateCode();

        return $target;
    }
}
