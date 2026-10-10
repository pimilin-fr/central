<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;

/**
 * Une stratégie qui renseigne des informations complémentaires sur son groupe
 * (ex. relevé : identifiant, finalisé ou non) — lues dans les templates via group.extra.
 */
interface GroupExtraInterface {

    /** @return array<string, mixed> */
    public function getExtra(Depenses $depense): array;
}
