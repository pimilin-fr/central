<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByTiers implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getTiers() ? 'tiers_' . $depense->getTiers()->getId() : 'no_tiers';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getTiers() ? $depense->getTiers()->getName() : 'Non affecté';
    }

}

