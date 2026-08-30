<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Service\DepenseGrouper\DepenseGroup;

class SortByDateDesc implements GroupSorterInterface {

    #[\Override]
    public function sort(array $groups): array {
        usort($groups, function (DepenseGroup $a, DepenseGroup $b) {

            $dateA = $a->getDate();
            $dateB = $b->getDate();

            // Les groupes sans date sont toujours les premiers
            if ($dateA === null && $dateB !== null) {
                return -1;
            }

            if ($dateA !== null && $dateB === null) {
                return 1;
            }

            // Les deux sont sans date
            if ($dateA === null && $dateB === null) {
                return 0;
            }

            // Les deux ont une date : plus récent en premier
            return $dateB <=> $dateA;
        });

        return $groups;
    }
}
