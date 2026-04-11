<?php
namespace App\Services;


class Geocoder
{
    public function geocode(string $address): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/search?format=json&q=' . urlencode($address);

        $response = file_get_contents($url);
        $data = json_decode($response, true);

        if (!empty($data)) {
            return [
                'lat' => (float) $data[0]['lat'],
                'lng' => (float) $data[0]['lon'],
            ];
        }

        return null;
    }
}