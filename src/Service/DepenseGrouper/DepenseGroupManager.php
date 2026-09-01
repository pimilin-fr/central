<?php

namespace App\Service\DepenseGrouper;

use App\Service\DepenseGrouper\GrouperStrategy\GroupByCategorie;
use App\Service\DepenseGrouper\GrouperStrategy\GroupByMonth;
use App\Service\DepenseGrouper\GrouperStrategy\GroupByPortefeuille;
use App\Service\DepenseGrouper\GrouperStrategy\GroupByProjet;
use App\Service\DepenseGrouper\GrouperStrategy\GroupByQuarter;
use App\Service\DepenseGrouper\GrouperStrategy\GroupByReleve;
use App\Service\DepenseGrouper\GrouperStrategy\GroupByTiers;
use App\Service\DepenseGrouper\GrouperStrategy\GroupByWeek;
use App\Service\DepenseGrouper\GrouperStrategy\GroupByYear;
use App\Service\DepenseGrouper\GrouperStrategy\GroupStrategyInterface;
use Symfony\Component\HttpFoundation\Request;

class DepenseGroupManager {

    private DepenseGrouper $grouper;
    private string $groupBy;
    public static final $REQ_PARAM_NAME = 'groupBy';

    public function __construct(Request $request, DepenseGrouper $grouper = new DepenseGrouper()) {
        $this->grouper = $grouper;
        $this->groupBy = $request->query->get('groupBy', 'releve');
    }

    public function build(array $depenses, float $initialBalance = 0): array {

        $strategy = $this->resolveStrategy();

        // 1. GROUPER
        $groups = $this->grouper->group($depenses, $strategy);

        // 2. SÉPARER LE NULL
        $nullGroups = [];
        $normalGroups = [];

        foreach ($groups as $group) {

            if ($group->isNullGroup()) {
                $nullGroups[] = $group;
                continue;
            }

            $normalGroups[] = $group;
        }

        // 3. TRI DES GROUPES NORMAUX POUR LE CALCUL
        $this->sortGroupsForCalculation($normalGroups, $strategy);

        // 4. CUMUL
        if ($strategy->isCumulative()) {

            $runningBalance = $initialBalance;

            foreach ($normalGroups as $group) {
                $group->setPreviousBalance($runningBalance);
                $runningBalance += $group->getNet();
                $group->setCurrentBalance($runningBalance);
            }

            foreach ($nullGroups as $group) {
                $group->setPreviousBalance($runningBalance);
                $runningBalance += $group->getNet();
                $group->setCurrentBalance($runningBalance);
            }
        }

        // 5. TRI POUR L'AFFICHAGE
        $this->sortGroupsForDisplay($normalGroups, $strategy);

        // 6. NULL TOUJOURS EN PREMIER À L'AFFICHAGE
        return array_merge($nullGroups, $normalGroups);
    }

    public function getGroupBy(): string {
        return $this->groupBy;
    }

    /**
     * Tri dans l'ordre nécessaire au calcul du cumul.
     *
     * Le sens est l'inverse du sens d'affichage.
     */
    private function sortGroupsForCalculation(
            array &$groups,
            GroupStrategyInterface $strategy
    ): void {

        $direction = $strategy->getSortDirection();

        /*
         * Le cumul se fait dans le sens inverse de l'affichage.
         */
        $direction = $direction === GroupStrategyInterface::SORT_ASC ? GroupStrategyInterface::SORT_DESC : GroupStrategyInterface::SORT_ASC;

        usort(
                $groups,
                function (
                        DepenseGroup $a,
                        DepenseGroup $b
                ) use ($direction) {

                    $comparison = $this->compare(
                            $a->getSortValue(),
                            $b->getSortValue()
                    );

                    return $direction === GroupStrategyInterface::SORT_ASC ? $comparison : -$comparison;
                }
        );
    }

    /**
     * Tri dans l'ordre d'affichage.
     */
    private function sortGroupsForDisplay(
            array &$groups,
            GroupStrategyInterface $strategy
    ): void {

        $direction = $strategy->getSortDirection();

        usort(
                $groups,
                function (
                        DepenseGroup $a,
                        DepenseGroup $b
                ) use ($direction) {

                    $comparison = $this->compare(
                            $a->getSortValue(),
                            $b->getSortValue()
                    );

                    return $direction === GroupStrategyInterface::SORT_ASC ? $comparison : -$comparison;
                }
        );
    }

    /**
     * Comparaison générique des valeurs de tri.
     */
    private function compare(
            mixed $a,
            mixed $b
    ): int {

        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }

        return strnatcasecmp(
                (string) $a,
                (string) $b
        );
    }

    private function resolveStrategy(): GroupStrategyInterface {
        return match ($this->groupBy) {

            'portefeuille' => new GroupByPortefeuille(),
            'categorie' => new GroupByCategorie(),
            'projet' => new GroupByProjet(),
            'tiers' => new GroupByTiers(),
            'annee' => new GroupByYear(),
            'trimestre' => new GroupByQuarter(),
            'mois' => new GroupByMonth(),
            'semaine' => new GroupByWeek(),
            default => new GroupByReleve(),
        };
    }
}
