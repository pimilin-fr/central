<?php

namespace App\Service\Geocoder\Query;

use Override;

class AdresseForceeGeoQueryBuilder extends AbstractGeoFreeQueryBuilder {

    public function getName(): string {
        return 'adresseForcee';
    }

    #[Override]
    protected function getQueryValue(): ?string {
        return $this->value($this->getAdresse()->getAdresseForcee());
    }
}
