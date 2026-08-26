<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByPortefeuille implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getPortefeuille() ? 'ptf_' . $depense->getPortefeuille()->getId() : 'no_ptf';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getPortefeuille() ? $depense->getPortefeuille()->getName() : 'Non affecté';
    }

}
