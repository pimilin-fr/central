<?php

namespace App\Service\Geocoder\Query;

use Override;

class AdresseExactGeoQueryBuilder extends AbstractGeoFreeQueryBuilder {

    public function getName(): string {
        return 'adresseExact';
    }

    #[Override]
    protected function getQueryValue(): ?string {
        return $this->value($this->getAdresse()->getAdresseExact());
    }
}
