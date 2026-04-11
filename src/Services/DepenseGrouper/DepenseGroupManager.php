<?php

namespace App\Services\DepenseGrouper;

use App\Services\DepenseGrouper\GrouperStrategy\GroupByReleve;
use App\Services\DepenseGrouper\GrouperStrategy\GroupStrategyInterface;

class DepenseGroupManager
{
    private DepenseGrouper $grouper;
    
    public function __construct(DepenseGrouper $grouper = new DepenseGrouper()) {
        $this->grouper = $grouper;
    }

    public function build(
        array $depenses,
        GroupStrategyInterface $strategy,
        float $initialBalance = 0
    ): array {

        // 1️⃣ GROUP
        $groups = $this->grouper->group($depenses, $strategy);

        // 2️⃣ TRI ASC (avec gestion null)
        $this->sortAsc($groups);

        // 3️⃣ CALCUL
        $runningBalance = $initialBalance;

        foreach ($groups as $group) {

            $group->setPreviousBalance($runningBalance);

            $runningBalance += $group->getNet();

            $group->setCurrentBalance($runningBalance);
        }

        // 4️⃣ TRI DESC (affichage)
        $this->sortDesc($groups);

        return $groups;
    }

    private function sortAsc(array &$groups): void
    {
        usort($groups, function (DepenseGroup $a, DepenseGroup $b) {

            $dateA = $a->getDate();
            $dateB = $b->getDate();

            if ($dateA === null) return 1;
            if ($dateB === null) return -1;

            return $dateA <=> $dateB;
        });
    }

    private function sortDesc(array &$groups): void
    {
        usort($groups, function (DepenseGroup $a, DepenseGroup $b) {

            $dateA = $a->getDate();
            $dateB = $b->getDate();

            if ($dateA === null) return -1;
            if ($dateB === null) return 1;

            return $dateB <=> $dateA;
        });
    }
}