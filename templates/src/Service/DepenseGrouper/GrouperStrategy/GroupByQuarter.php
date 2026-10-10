<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByQuarter extends GroupByDateStrategyAbstractClass  {

    #[Override]
    public function getKey(Depenses $depense): string {
        $date = $depense->getDate();
        $quarter = (int) ceil($date->format('n') / 3);
        return $date->format('Y') . 'Q' . $quarter;
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        $date = $depense->getDate();
        $quarter = (int) ceil($date->format('n') / 3);
        return sprintf('T%d %s', $quarter, $date->format('Y'));
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return $this->getKey($depense);
    }
}
