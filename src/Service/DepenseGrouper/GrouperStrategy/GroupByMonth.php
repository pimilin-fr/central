<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use IntlDateFormatter;
use Override;

class GroupByMonth implements GroupStrategyInterface {

    #[Override]
    public function getKey(Depenses $depense): string {
        return $depense->getDate()->format('Ym');
    }

    #[Override]
    public function getLabel(Depenses $depense): string {
        $formatter = new IntlDateFormatter(
                'fr_FR',
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                null,
                null,
                'MMMM yyyy'
        );

        return $formatter->format($depense->getDate());
    }
}
