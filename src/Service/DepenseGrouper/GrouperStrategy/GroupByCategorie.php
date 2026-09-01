<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByCategorie implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return 'categorie_' . $depense->getCategorie()->getId();
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getCategorie()->__toString();
    }

    #[\Override]
    public function isCumulative(): bool {
        return false;
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return 'categorie_' . urlencode($depense->getCategorie()->fullName("-"));
    }

    #[\Override]
    public function getSortDirection(): string {
        return self::SORT_ASC;
    }
}
