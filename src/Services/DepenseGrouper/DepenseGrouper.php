<?php

namespace App\Services\DepenseGrouper;

//use App\Entity\Depenses;
use App\Services\DepenseGrouper\GrouperStrategy\GroupStrategyInterface;

class DepenseGrouper {

    public function group(
            array $depenses,
            GroupStrategyInterface $strategy
    ): array {
        $groups = [];

        foreach ($depenses as $depense) {

            $key = $strategy->getKey($depense);

            if (!isset($groups[$key])) {
                $groups[$key] = (new DepenseGroup())
                        ->setKey($key)
                        ->setLabel($strategy->getLabel($depense))
                        ->setDate($strategy->getDate($depense));
            }

            $groups[$key]->addDepense($depense);
        }

        return array_values($groups);
    }
}
