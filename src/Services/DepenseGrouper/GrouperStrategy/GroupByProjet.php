<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByProjet implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getProjet() ? 'prj_' . $depense->getProjet()->getId() : '0';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getProjet() ? $depense->getProjet()->getName() : 'Non affecté';
    }

}
