<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByTiers implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return 'tiers_' . $depense->getTiers()->getId();
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getTiers()->getName();
    }

    #[\Override]
    public function getSortDirection(): string {
        return self::SORT_ASC;
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return $this->getLabel($depense);
    }

    #[\Override]
    public function isCumulative(): bool {
        
    }
}

