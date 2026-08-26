<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByQuarter implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        $date = $depense->getDate();
        $quarter = (int) ceil($date->format('n') / 3);
        return $date->format('Y') . '-Q' . $quarter;
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        $date = $depense->getDate();
        $quarter = (int) ceil($date->format('n') / 3);
        return sprintf('T%d %s', $quarter, $date->format('Y'));
    }

//    #[Override]
//    public function getDate(Depenses $depense): ?DateTime {
//        $date = clone $depense->getDate();
//        $quarter = (int) ceil($date->format('n') / 3);
//
//        $firstMonth = ($quarter - 1) * 3 + 1;
//
//        return $date
//                        ->setDate((int) $date->format('Y'), $firstMonth, 1)
//                        ->setTime(0, 0);
//    }
}
