<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByReleve implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getReleve() ? 'releve_' . $depense->getReleve()->getDate()->format('Ymd'): '0';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getReleve() ? $depense->getReleve()->getDate()->format('d/m/Y') : 'Non affecté';
    }
}
