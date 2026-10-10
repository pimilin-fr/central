<?php

namespace App\Service\Geocoder\Query;

class StructuredGeoQueryBuilder extends AbstractGeoQueryBuilder {

    #[\Override]
    public function getName(): string {
        return 'structured';
    }

    #[\Override]
    public function build(): ?array {
        $query = [
            'format' => self::FORMAT,
            'limit' => self::LIMIT,
        ];

        $street = trim(implode(' ', array_filter([
            $this->getAdresse()->getNum(),
            $this->getAdresse()->getBisTer(),
            $this->getAdresse()->getTypeVoie(),
            $this->getAdresse()->getNomVoie(),
        ])));

        if ($street !== '') {
            $query['street'] = $street;
        }

        if ($postalCode = $this->value($this->getAdresse()->getCodePostal())) {
            $query['postalcode'] = $postalCode;
        }

        if ($city = $this->value($this->getAdresse()->getVille())) {
            $query['city'] = $city;
        }

        if ($country = $this->value($this->getAdresse()->getPays())) {
            $query['country'] = $country;
        }

        /*
         * Aucun critère permettant une recherche.
         */
        if (count($query) === 2) {
            return null;
        }

        return $this->buildRequest($query);
    }
}
