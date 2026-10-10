<?php
namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

/**
 * Un groupe = un relevé (clé : son identifiant ; tri : sa date). Indique si le relevé est
 * finalisé (ordre figé) ou en cours (group.extra.closed / group.extra.ordered).
 */
class GroupByReleve implements GroupStrategyInterface, GroupExtraInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getReleve() ? 'releve_' . $depense->getReleve()->getId() : '0';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getReleve() ? 'Relevé du ' . $depense->getReleve()->getDate()->format('d/m/Y') : 'Non affecté';
    }

    #[\Override]
    public function isCumulative(): bool {
        return true;
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        $releve = $depense->getReleve();

        // date puis identifiant : l'ordre chronologique des relevés (comparaison texte)
        return $releve ? $releve->getDate()->format('Ymd') . sprintf('%09d', $releve->getId()) : '0';
    }

    #[\Override]
    public function getSortDirection(): string {
        return self::SORT_DESC;
    }

    #[\Override]
    public function isNull(Depenses $depense): bool {
        return ($depense->getReleve() === null);
    }

    #[\Override]
    public function getExtra(Depenses $depense): array {
        $releve = $depense->getReleve();
        if ($releve === null) {
            return [];
        }

        return [
            'releveId' => $releve->getId(),
            'portefeuilleId' => $releve->getPortefeuille()->getId(),
            'closed' => $releve->isClosed(),
            'ordered' => $releve->hasOrderedOperations(),
        ];
    }
}
