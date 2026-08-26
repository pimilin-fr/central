<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use DateTime;
use Override;

class GroupByWeek implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getDate()->format('o-\WW'); // année ISO + semaine
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return 'Semaine ' . $depense->getDate()->format('W Y');
    }
}
