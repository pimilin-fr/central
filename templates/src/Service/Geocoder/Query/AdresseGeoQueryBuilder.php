<?php

namespace App\Service\Geocoder\Query;

use Override;

class AdresseGeoQueryBuilder extends AbstractGeoFreeQueryBuilder {

    public function getName(): string {
        return 'adresse';
    }

    #[Override]
    protected function getQueryValue(): ?string {
        return $this->value($this->getAdresse()->getAdresse());
    }
}
