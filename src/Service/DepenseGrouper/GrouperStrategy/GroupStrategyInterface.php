<?php
namespace App\Service\DepenseGrouper\GrouperStrategy;

use App\Entity\Depenses;
//use DateTime;


interface GroupStrategyInterface {

    public function getKey(Depenses $depense): string;

    public function getLabel(Depenses $depense): string;

//    public function getDate(Depenses $depense): ?DateTime;
}
