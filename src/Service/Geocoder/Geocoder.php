<?php

namespace App\Service\Geocoder;

use App\Entity\Adresse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Response\CurlResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Geocoder {

    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private HttpClientInterface $httpClient;

    public function __construct(HttpClientInterface $httpClient) {
        $this->httpClient = $httpClient;
    }

    public function geocode(Adresse $adresse): ?array {
        $request = $this->buidRequest($adresse);

        try {
            $response = $this->httpClient->request(
                    'GET',
                    self::NOMINATIM_URL,
                    $request
            );

            $data = $this->reteriveData($response);

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
        } catch (GeocoderException $e) {

            // Nos propres erreurs : on les laisse remonter telles quelles.
            throw $e;
        } catch (TransportExceptionInterface $e) {

            throw new GeocoderException(
                            'Network error: ' . $e->getMessage(),
                            10,
                            $e
                    );
        } catch (HttpExceptionInterface $e) {

            $statusCode = $e->getResponse()->getStatusCode();

            throw new GeocoderException(
                            sprintf(
                                    'HTTP error %d: %s',
                                    $statusCode,
                                    $e->getMessage()
                            ),
                            $statusCode,
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

    private function buidRequest(Adresse $adresse): array {
        return [
            "query" => [
                "format" => "json",
                "limit" => 1,
                "q" => $adresse->__toString()
            ],
            "headers" => [
                "User-Agent" => 'localapp-central/1.0',
            ],
            "timeout" => 20
        ];
    }

    private function reteriveData(CurlResponse $response): array {
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

        $data = $response->toArray(false);

        if (empty($data)) {
            throw new GeocoderException(
                            'Empty response',
                            2
                    );
        }

        return $data;
    }
}
