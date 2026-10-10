<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByAdresse implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return 'adresse_' . $depense->getAdresse()->getId();
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        return $depense->getTiers()->getName().' - '.$depense->getAdresse()->getVille();
    }

    #[\Override]
    public function getSortDirection(): string {
        return self::SORT_ASC;
    }

    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return $this->getLabel($depense);
    }

    #[\Override]
    public function isCumulative(): bool {
        return true;
    }

    #[\Override]
    public function isNull(Depenses $depense): bool {
        return ($depense->getAdresse() === null);
    }
}

