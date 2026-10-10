<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\Projet;
use App\Entity\ProjetType;

/** Projets : copie, anonymisation (« Travaux 1 »…) ou exclusion selon le champ demoStrategy. */
class ProjetTransformer extends AbstractDemoTransformer {

    public function getOrder(): int { return 60; }

    public function getSourceClass(): string { return Projet::class; }

    public function getLabel(): string { return 'Projets'; }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var Projet $source */
        $type = $this->ref($context, ProjetType::class, $source->getType()->getId());
        if ($type === null) {
            return null;
        }

        $name = $source->getName();
        if ($strategy === DemoStrategy::ANONYMIZE) {
            $typeName = $source->getType()->getName();
            $name = sprintf('%s %d', $typeName, $context->next('projet.' . $typeName));
        }

        return (new Projet())
            ->setName($name)
            ->setType($type)
            ->setCouleur($source->getCouleur())
            ->setBeginAt($context->asImmutable($source->getBeginAt()))
            ->setEndAt($context->asImmutable($source->getEndAt()))
            ->setDemoStrategy(DemoStrategy::COPY);
    }
}
