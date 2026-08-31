<?php

namespace App\Service\Geocoder\Query;

use App\Entity\Adresse;

abstract class AbstractGeoQueryBuilder {

    protected const FORMAT = 'jsonv2';
    protected const LIMIT = 1;
    protected const APP_NAME = "localapp-central/1.0";

    private Adresse $adresse;

    public function __construct(Adresse $adresse) {
        $this->adresse = $adresse;
    }

    final public function getAdresse():Adresse {
        return $this->adresse;
    }


    /**
     * Construit la requête Nominatim.
     */
    abstract public function build(): ?array;

    /**
     * Nom de la stratégie.
     * Sert notamment au debug.
     */
    abstract public function getName(): string;

    /**
     * Construction commune des options HTTP.
     */
    protected function buildRequest(array $query): array {
        return [
            'query' => $query,
            'headers' => [
                'User-Agent' => self::APP_NAME,
                'Accept-Language' => 'fr',
            ],
            'timeout' => 20,
        ];
    }

    /**
     * Vérifie qu'une valeur est exploitable.
     */
    protected function value(?string $value): ?string {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
