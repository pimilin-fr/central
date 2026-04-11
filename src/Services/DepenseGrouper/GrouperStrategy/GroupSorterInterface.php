<?php

namespace App\Services\DepenseGrouper\GrouperStrategy;

interface GroupSorterInterface {

    public function sort(array $groups): array;
}
