<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use IntlDateFormatter;
use Override;

abstract class GroupByDateStrategyAbstractClass implements GroupStrategyInterface {

    #[\Override]
    public final function isCumulative(): bool {
        return true;
    }
    
     #[\Override]
    public final function getSortDirection(): string {
        return self::SORT_DESC;
    }
    
    #[\Override]
    public final function isNull(Depenses $depense): bool {
        return ($depense->getDate() === null); /// toujours faux
    }
    
    #[\Override]
    public function getSortValue(Depenses $depense): mixed {
        return $this->getKey($depense);
    }
}
