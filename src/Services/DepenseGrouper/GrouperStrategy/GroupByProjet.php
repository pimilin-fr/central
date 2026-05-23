<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use DateTime;
use Override;

class GroupByProjet implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getProjet() ? 'prj_' . $depense->getProjet()->getId() : 'no_prj';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getProjet() ? $depense->getProjet()->getName() : 'Non affecté';
    }

    #[Override]
    public function getDate(Depenses $depense): ?DateTime {
        return $depense->getReleve() ? $depense->getReleve()->getDate() : null;
    }
}
