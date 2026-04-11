<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Services\DepenseGrouper\DepenseGroup;

class SortByDateDesc implements GroupSorterInterface {

    #[\Override]
    public function sort(array $groups): array {
        usort($groups, function (DepenseGroup $a, DepenseGroup $b) {
            return ($b->getDate()?->getTimestamp() ?? 0) <=> ($a->getDate()?->getTimestamp() ?? 0);
        });

        return $groups;
    }
}
