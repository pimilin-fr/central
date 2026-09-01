<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByReleve implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getReleve() ? 'releve_' . $depense->getReleve()->getDate()->format('Ymd') : '0';
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getReleve() ? $depense->getReleve()->getDate()->format('d/m/Y') : 'Non affecté';
    }

    #[\Override]
    public function isCumulative(): bool {
        return true;
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return $this->getKey($depense);
    }

    #[\Override]
    public function getSortDirection(): string {
        return self::SORT_DESC;
    }
}
