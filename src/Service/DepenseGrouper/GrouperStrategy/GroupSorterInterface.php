<?php

namespace App\Service\DepenseGrouper\GrouperStrategy;

interface GroupSorterInterface {

    public function sort(array $groups): array;
}
