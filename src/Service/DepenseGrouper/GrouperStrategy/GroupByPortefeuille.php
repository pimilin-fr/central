<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByPortefeuille implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return 'ptf_' . $depense->getPortefeuille()->getId();
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getPortefeuille()->getName();
    }

    #[\Override]
    public function isCumulative(): bool {
        return true;
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return $depense->getPortefeuille()->getOrdre();
    }

    #[\Override]
    public function getSortDirection(): string {
        return self::SORT_ASC;
    }
}
