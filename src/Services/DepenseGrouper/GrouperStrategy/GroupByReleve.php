<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use DateTime;
use Override;

class GroupByReleve implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getReleve() ? 'releve_' . $depense->getReleve()->getId() : 'no_releve';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getReleve() ? $depense->getReleve()->getDate()->format('d/m/Y') : 'Non affecté';
    }

    #[Override]
    public function getDate(Depenses $depense): ?DateTime {
        return $depense->getReleve() ? $depense->getReleve()->getDate() : null;
    }
}
