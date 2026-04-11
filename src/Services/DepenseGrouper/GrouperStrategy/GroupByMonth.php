<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use DateTime;
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

    #[Override]
    public function getDate(Depenses $depense): ?DateTime {
        return (clone $depense->getDate())->modify('first day of this month');
    }
}
