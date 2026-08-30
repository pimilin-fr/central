<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use DateTime;
use Override;

class GroupByYear implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getDate()->format('Y');
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getDate()->format('Y');
    }
}
