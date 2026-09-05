<?php

namespace App\Service\Adresse;

use App\Entity\Adresse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class AdresseMapBuilder {

    private UrlGeneratorInterface $urlGenerator;

    public function __construct(UrlGeneratorInterface $urlGenerator) {
        $this->urlGenerator = $urlGenerator;
    }

    public function build(Adresse $adresse): array {
        $map = ['current' => $this->buildPoint($adresse), 'zones' => [],];
        if ($adresse->getChildren()->isEmpty()) {
            return $map;
        }

        $children = $adresse->getChildren()->toArray();
        if ($this->childrenAreLeaves($children)) {
            $points = [];
            foreach ($children as $child) {
                $point = $this->buildPoint($child);
                if ($point['latitude'] === null || $point['longitude'] === null) {
                    continue;
                } $points[] = $point;
            }
            $map['zones'][] = [
                'id' => $adresse->getId(),
                'name' => $adresse->getName(),
                'points' => $points
            ];
            return $map;
        } foreach ($children as $zone) {
            $points = $this->collectRuePoints($zone);
            $map['zones'][] = [
                'id' => $zone->getId(),
                'name' => $zone->getName(),
                'points' => $points,
            ];
        } return $map;
    }

    private function buildPoint(Adresse $adresse): array {
        return [
            'id' => $adresse->getId(),
            'name' => $adresse->getName(),
            'latitude' => $adresse->getLatitude(),
            'longitude' => $adresse->getLongitude(),
            'url' => $adresse->getId() !== null ? $this->urlGenerator->generate('app_adresse_show', ['id' => $adresse->getId()]) : null,
        ];
    }

    private function childrenAreLeaves(array $children): bool {
        foreach ($children as $child) {
            if (!$child->getChildren()->isEmpty()) {
                return false;
            }
        } 
        return true;
    }

    private function collectRuePoints(Adresse $adresse): array {
        if ($adresse->getChildren()->isEmpty()) {
            if (!$adresse->isGeolocalisee()) {
                return [];
            } 
            return [$this->buildPoint($adresse),];
        } 
        $points = [];
        foreach ($adresse->getChildren() as $child) {
            foreach ($this->collectRuePoints($child) as $point) {
                $points[] = $point;
            }
        } 
        return $points;
    }
}
