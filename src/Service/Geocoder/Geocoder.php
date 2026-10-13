<?php

namespace App\Service\Geocoder;

use App\Entity\Adresse;
use App\Service\Geocoder\Query\AdresseExactGeoQueryBuilder;
use App\Service\Geocoder\Query\AdresseForceeGeoQueryBuilder;
use App\Service\Geocoder\Query\AdresseGeoQueryBuilder;
use App\Service\Geocoder\Query\StructuredGeoQueryBuilder;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class Geocoder {

    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private HttpClientInterface $httpClient;

    public function __construct(HttpClientInterface $httpClient) {
        $this->httpClient = $httpClient;
    }

    private function generateBuilders(Adresse $adresse): array {
        return [
            new AdresseForceeGeoQueryBuilder($adresse),
            new StructuredGeoQueryBuilder($adresse),
            new AdresseGeoQueryBuilder($adresse),
        ];
    }

    public function geocode(Adresse $adresse): array {
        $builders = $this->generateBuilders($adresse);

        $errors = [];

        try {

            foreach ($builders as $builder) {

                $request = $builder->build();

                /*
                 * Le builder peut décider qu'il n'est
                 * pas applicable à cette adresse.
                 */
                if ($request === null) {
                    $errors[$builder->getName()] = 'Not applicable';
                    continue;
                }

                $response = $this->httpClient->request(
                        'GET',
                        self::NOMINATIM_URL,
                        $request
                );

                $data = $this->retrieveData($response);

                /*
                 * Aucun résultat :
                 * on essaye la stratégie suivante.
                 */
                if (empty($data)) {
                    $errors[$builder->getName()] = [
                        'message' => 'No result',
                        'request' => $request,
                        'response' => $data,
                    ];

                    continue;
                }

                /*
                 * Premier résultat valide :
                 * terminé.
                 */
                return $this->extractCoordinates($data);
            }

            throw new GeocoderException(
                            $this->buildNoResultMessage($errors),
                            2
                    );
        } catch (GeocoderException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {

            throw new GeocoderException(
                            'Network error: ' . $e->getMessage(),
                            10,
                            $e
                    );
        } catch (Throwable $e) {

            throw new GeocoderException(
                            'Geocoder error: ' . $e->getMessage(),
                            99,
                            $e
                    );
        }
    }

    /**
     * Recherche libre détaillée (addressdetails) : liste de résultats Nominatim bruts.
     * Appelée sur action explicite de l'utilisateur (la politique de Nominatim interdit l'autocomplétion à la frappe).
     *
     * @return list<array<string,mixed>>
     */
    public function searchDetailed(string $q, int $limit = 5): array {
        return $this->call('search', ['q' => $q, 'limit' => $limit]);
    }

    /** Géocodage inverse (coordonnées → adresse détaillée), ou null. */
    public function reverseDetailed(float $lat, float $lng): ?array {
        $data = $this->call('reverse', ['lat' => $lat, 'lon' => $lng, 'zoom' => 18]);

        return $data[0] ?? null;
    }

    /** Position approximative d'un lieu (quartier, ville…) ; null si introuvable ou en cas d'erreur réseau. */
    public function lookupPoint(string $q): ?array {
        try {
            $data = $this->call('search', ['q' => $q, 'limit' => 1]);
        } catch (GeocoderException) {
            return null;
        }

        return isset($data[0]['lat'], $data[0]['lon']) ? ['lat' => (float) $data[0]['lat'], 'lng' => (float) $data[0]['lon']] : null;
    }

    private function call(string $endpoint, array $query): array {
        try {
            $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/' . $endpoint, [
                'query' => $query + ['format' => 'jsonv2', 'addressdetails' => 1],
                'headers' => ['User-Agent' => 'localapp-central/1.0', 'Accept-Language' => 'fr'],
                'timeout' => 15,
            ]);
            $data = $this->retrieveData($response);
        } catch (TransportExceptionInterface $e) {
            throw new GeocoderException('Réseau indisponible : ' . $e->getMessage(), 10, $e);
        }

        // « reverse » renvoie un objet unique (ou {error}) ; « search » une liste
        if (isset($data['error'])) {
            return [];
        }

        return array_is_list($data) ? $data : [$data];
    }

    private function retrieveData($response): array {
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new GeocoderException(
                            sprintf(
                                    'HTTP error %d: %s',
                                    $statusCode,
                                    $response->getContent(false)
                            ),
                            $statusCode
                    );
        }

        return $response->toArray(false);
    }

    private function extractCoordinates(array $data): array {
        if (!isset($data[0]['lat'], $data[0]['lon'])) {
            throw new GeocoderException(
                            'No Lat Lon property received in response',
                            3
                    );
        }

        return [
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
        ];
    }

    private function buildNoResultMessage(array $errors): string {
        $message = "No geocoding result found.";

        foreach ($errors as $name => $error) {

            $message .= sprintf(
                    "\n\n[%s]\n%s",
                    $name,
                    is_array($error) ? ($error['message'] ?? 'Unknown error') : $error
            );
        }

        return $message;
    }
}
