<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\Portefeuille;

/** Portefeuilles : copie, anonymisation (noms génériques) ou exclusion selon le champ demoStrategy. */
class PortefeuilleTransformer extends AbstractDemoTransformer {

    /** Libellé générique par type de portefeuille. */
    private const GENERIC_NAMES = [
        'Banque' => 'Compte courant',
        'Espèce' => 'Espèces',
        'Epargne' => 'Livret d’épargne',
        'Prêt' => 'Prêt',
        'Justice' => 'Dossier spécial',
        'Spécifique' => 'Compte spécial',
    ];

    public function getOrder(): int { return 50; }

    public function getSourceClass(): string { return Portefeuille::class; }

    public function getLabel(): string { return 'Portefeuilles'; }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var Portefeuille $source */
        $target = new Portefeuille();

        if ($strategy === DemoStrategy::ANONYMIZE) {
            $base = self::GENERIC_NAMES[$source->getType() ?? ''] ?? 'Portefeuille';
            $name = sprintf('%s %d', $base, $context->next('portefeuille.' . $base));
            $target->setName($name)->setLibelle($name)->setOrigine(null);
        } else {
            $target->setName($source->getName())
                ->setLibelle($source->getLibelle())
                ->setOrigine($source->getOrigine());
        }

        $target->setType($source->getType())
            ->setOrdre($source->getOrdre())
            ->setIsDefault($source->getIsDefault())
            ->setIsReal($source->getIsReal())
            ->setCouleur($source->getCouleur())
            ->setDeleted($source->getDeleted())
            ->setDemoStrategy(DemoStrategy::COPY); // la règle ne sert que pour la construction

        // le code dérive du type : on ne le recalcule que si ce type est connu (sinon on garde l'ancien)
        if (in_array($source->getType(), Portefeuille::TYPE_PTF, true)) {
            $target->regenerateCode();
        } else {
            $target->setCode($source->getCode());
        }

        return $target;
    }
}
