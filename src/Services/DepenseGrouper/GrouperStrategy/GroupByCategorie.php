<?php
namespace App\Services\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
use DateTime;
use Override;

class GroupByCategorie implements GroupStrategyInterface
{
    #[Override]
    public function getKey(Depenses $depense): string
    {
        return 'categorie_' . $depense->getCategorie()->getId();
    }

    #[Override]
    public function getLabel(Depenses $depense): string
    {
        return $depense->getCategorie()->getName();
    }

    #[Override]
    public function getDate(Depenses $depense): ?DateTime
    {
        return $depense->getDate();
    }
}