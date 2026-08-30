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

        // 1️⃣ GROUP
        $groups = $this->grouper->group(
                $depenses,
                $this->resolveStrategy()
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

    private function sortAsc(array &$groups): void {
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

    private function sortDesc(array &$groups): void {
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

    public function getGroupBy(): string {
        return $this->groupBy;
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
