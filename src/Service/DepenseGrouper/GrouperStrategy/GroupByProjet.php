<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByProjet implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getProjet() ? 'prj_' . $depense->getProjet()->getId() : '0';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getProjet() ? $depense->getProjet()->getName() : 'Non affecté';
    }

    #[\Override]
    public function isCumulative(): bool {
        return false;
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return $this->getLabel($depense);
    }

    #[\Override]
    public function getSortDirection(): string {
        return self::SORT_ASC;
    }
}
