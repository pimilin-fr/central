<?php

namespace App\Service\DepenseGrouper;

use App\Entity\Depenses;

/**
 * Ordre des opérations DANS chaque groupe.
 *
 *  - Relevé finalisé : TOUJOURS l'ordre du relevé de compte (aucune option) ; les lignes sont numérotées.
 *  - Sinon, selon l'option « Tri » de la barre de regroupement :
 *      'date'   : celui de la requête (date décroissante) — comportement habituel ;
 *      'releve' : par relevé (récent d'abord) puis par rang dans le relevé ; hors relevé en tête, par date.
 */
final class DepenseOrderer {

    public const TRI_DATE = 'date';
    public const TRI_RELEVE = 'releve';

    public static function normalize(?string $tri): string {
        return $tri === self::TRI_RELEVE ? self::TRI_RELEVE : self::TRI_DATE;
    }

    /** @param list<DepenseGroup> $groups */
    public static function apply(array $groups, string $tri): void {
        foreach ($groups as $group) {
            $depenses = $group->getDepenses();
            if (count($depenses) < 2 && !self::isSingleReleve($depenses)) {
                continue;
            }

            if (self::isSingleReleve($depenses) && $depenses[0]->getReleve()->isClosed()) {
                $group->setDepenses(self::byRang($depenses))->setStatementOrder(true);
            } elseif ($tri === self::TRI_RELEVE) {
                $group->setDepenses(self::byReleve($depenses));
                $group->setStatementOrder(self::isSingleReleve($depenses) && self::allRanked($depenses));
            }
        }
    }

    /** Toutes les opérations appartiennent au même relevé. */
    private static function isSingleReleve(array $depenses): bool {
        if ($depenses === [] || $depenses[0]->getReleve() === null) {
            return false;
        }
        $id = $depenses[0]->getReleve()->getId();
        foreach ($depenses as $depense) {
            if ($depense->getReleve()?->getId() !== $id) {
                return false;
            }
        }

        return true;
    }

    private static function allRanked(array $depenses): bool {
        foreach ($depenses as $depense) {
            if ($depense->getReleveOrdre() === null) {
                return false;
            }
        }

        return true;
    }

    /** Rang croissant ; sans rang (relevé ancien) à la suite, par date puis identifiant. */
    private static function byRang(array $depenses): array {
        usort($depenses, static fn (Depenses $a, Depenses $b): int => [$a->getReleveOrdre() ?? PHP_INT_MAX, $a->getDate(), $a->getId()]
                        <=> [$b->getReleveOrdre() ?? PHP_INT_MAX, $b->getDate(), $b->getId()]);

        return $depenses;
    }

    private static function byReleve(array $depenses): array {
        usort($depenses, static function (Depenses $a, Depenses $b): int {
            $ra = $a->getReleve();
            $rb = $b->getReleve();
            // hors relevé d'abord (par date décroissante), puis relevés récents d'abord, puis rang
            return [$ra === null ? 0 : 1, $ra === null ? 0 : -(int) $ra->getDate()->format('Ymd'), $ra?->getId() ?? 0, $a->getReleveOrdre() ?? PHP_INT_MAX, -(int) $a->getDate()->format('Ymd'), -($a->getId() ?? 0)]
                    <=> [$rb === null ? 0 : 1, $rb === null ? 0 : -(int) $rb->getDate()->format('Ymd'), $rb?->getId() ?? 0, $b->getReleveOrdre() ?? PHP_INT_MAX, -(int) $b->getDate()->format('Ymd'), -($b->getId() ?? 0)];
        });

        return $depenses;
    }
}
