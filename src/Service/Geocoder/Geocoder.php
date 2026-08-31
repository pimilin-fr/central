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
            new StructuredGeoQueryBuilder($adresse),
            new AdresseForceeGeoQueryBuilder($adresse),
            new AdresseGeoQueryBuilder($adresse),
            new AdresseGeoQueryBuilder($adresse),
            new AdresseExactGeoQueryBuilder($adresse),
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
            echo '<pre>';
            var_dump($errors);
            die;
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
