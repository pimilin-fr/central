<?php

namespace App\Service\DepenseGrouper;

use App\Entity\Depenses;
use App\Service\DepenseGrouper\GrouperStrategy\GroupExtraInterface;
use App\Service\DepenseGrouper\GrouperStrategy\GroupStrategyInterface;

class DepenseGrouper {

    public function group(array $depenses, GroupStrategyInterface $strategy): array {
        $groups = [];

        foreach ($depenses as $depense) {

            $key = $strategy->getKey($depense);

            if (!isset($groups[$key])) {

                $groups[$key] = (new DepenseGroup())
                        ->setKey($key)
                        ->setLabel($strategy->getLabel($depense))
                        ->setSortValue($strategy->getSortValue($depense))
                        ->setCumulative($strategy->isCumulative())
                        ->setNullGroup($strategy->isNull($depense));

                if ($strategy instanceof GroupExtraInterface) {
                    $groups[$key]->setExtra($strategy->getExtra($depense));
                }
            }

            $groups[$key]->addDepense($depense);
        }

        return array_values($groups);
    }
}
