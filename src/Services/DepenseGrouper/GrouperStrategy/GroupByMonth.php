<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByMonth implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getDate()->format('Y-m');
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getDate()->format('F Y');
    }
}
