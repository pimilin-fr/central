<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use Override;

class GroupByWeek implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getDate()->format('o\WW'); // année ISO + semaine
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        $date = $depense->getDate();

        $debut = (clone $date)->modify('monday this week');
        $fin = (clone $debut)->modify('+6 days');

        return 'Du ' . $debut->format('d/m') . ' au ' . $fin->format('d/m/Y');
//        return 'Semaine ' . $depense->getDate()->format('W Y');
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
