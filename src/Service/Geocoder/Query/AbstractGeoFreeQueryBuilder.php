<?php

namespace App\Service\Geocoder\Query;

use Override;

abstract class AbstractGeoFreeQueryBuilder extends AbstractGeoQueryBuilder {

    abstract protected function getQueryValue(): ?string;

    #[Override]
    public function build(): ?array {
        $value = $this->getQueryValue();

        if ($value === null || trim($value) === "") {
            return null;
        }

        return $this->buildRequest([
                    'format' => self::FORMAT,
                    'limit' => self::LIMIT,
                    'q' => $value,
        ]);
    }
}
