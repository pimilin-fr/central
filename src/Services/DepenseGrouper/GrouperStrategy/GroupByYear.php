<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

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

    #[Override]
    public function getDate(Depenses $depense): ?DateTime {
        return (clone $depense->getDate())->modify('first day of January this year')->setTime(0, 0);
    }
}
