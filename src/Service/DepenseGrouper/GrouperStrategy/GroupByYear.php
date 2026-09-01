<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
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

    #[\Override]
    public function getSortDirection(): string {
        return self::SORT_DESC;
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return $this->getKey($depense);
    }

    #[\Override]
    public function isCumulative(): bool {
        return true;
    }
}
