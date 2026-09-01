<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;

interface GroupStrategyInterface {

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function getKey(Depenses $depense): string;

    public function getLabel(Depenses $depense): string;

    public function isCumulative(): bool;

    public function getSortValue(Depenses $depense): mixed;

    public function getSortDirection(): string;
    
    public function isNull(Depenses $depense): bool;
}
