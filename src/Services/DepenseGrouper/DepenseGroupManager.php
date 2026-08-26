<?php

namespace App\Services\DepenseGrouper;

use App\Services\DepenseGrouper\GrouperStrategy\GroupStrategyInterface;

class DepenseGroupManager
{
    private DepenseGrouper $grouper;

    public function __construct(
        DepenseGrouper $grouper = new DepenseGrouper()
    ) {
        $this->grouper = $grouper;
    }

    public function build(
        array $depenses,
        GroupStrategyInterface $strategy,
        float $initialBalance = 0
    ): array {

        // 1️⃣ GROUP
        $groups = $this->grouper->group(
            $depenses,
            $strategy
        );

        // 2️⃣ TRI ASC
        // Nécessaire pour calculer les soldes
        $this->sortAsc($groups);

        // 3️⃣ CALCUL DES SOLDES
        $runningBalance = $initialBalance;

        foreach ($groups as $group) {

            $group->setPreviousBalance($runningBalance);

            $runningBalance += $group->getNet();

            $group->setCurrentBalance($runningBalance);
        }

        // 4️⃣ TRI DESC
        // Ordre d'affichage
        $this->sortDesc($groups);

        return $groups;
    }

    private function sortAsc(array &$groups): void
    {
        usort($groups, function (DepenseGroup $a, DepenseGroup $b) {

            $keyA = $a->getKey();
            $keyB = $b->getKey();

            // "0" = groupe sans élément
            // Toujours en premier
            if ($keyA === '0' && $keyB !== '0') {
                return -1;
            }

            if ($keyB === '0' && $keyA !== '0') {
                return 1;
            }

            return strnatcasecmp($keyA, $keyB);
        });
    }

    private function sortDesc(array &$groups): void
    {
        usort($groups, function (DepenseGroup $a, DepenseGroup $b) {

            $keyA = $a->getKey();
            $keyB = $b->getKey();

            // "0" = groupe sans élément
            // Toujours en premier,
            // même en ordre descendant
            if ($keyA === '0' && $keyB !== '0') {
                return -1;
            }

            if ($keyB === '0' && $keyA !== '0') {
                return 1;
            }

            return strnatcasecmp($keyB, $keyA);
        });
    }
}