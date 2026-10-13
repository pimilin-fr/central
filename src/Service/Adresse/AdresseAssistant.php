<?php

namespace App\Service\Adresse;

use App\Entity\Adresse;
use App\Entity\AdresseType;
use App\Repository\AdresseRepository;
use App\Service\Geocoder\Geocoder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saisie assistée d'une adresse, par étapes (« tiroirs ») :
 *  - locate()  : adresse complète (ou coordonnées) → candidats décomposés. Niveau « rue » : champs de l'adresse (AdresseParser::candidate) ;
 *                niveaux supérieurs (quartier, ville…) : le lieu saisi « comme une adresse » (AdresseParser::place) ;
 *  - nearby()  : les N adresses existantes d'un niveau les plus proches d'une position (pour la carte et la liste) ;
 *  - applyChain() : à l'enregistrement, crée les niveaux « nouveaux » (du plus haut au plus bas) et rattache la rue.
 *
 * Rien n'est modifié en base de données hors création d'Adresse / d'AdresseType manquants (ajout seulement).
 */
final class AdresseAssistant {

    public function __construct(
            private readonly Geocoder $geocoder,
            private readonly AdresseParser $parser,
            private readonly AdresseRepository $adresses,
            private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function locate(string $q, ?array $coords, AdresseLevel $level = AdresseLevel::Rue): array {
        $build = fn(array $raw, ?float $lat = null, ?float $lng = null) => $level === AdresseLevel::Rue
                ? $this->parser->candidate($raw, $lat, $lng)
                : $this->parser->place($raw) + ['lat' => $lat, 'lng' => $lng];

        if ($coords !== null) {
            $raw = $this->geocoder->reverseDetailed($coords['lat'], $coords['lng']);
            if ($raw === null) {
                return [];
            }
            $c = $build($raw, $coords['lat'], $coords['lng']);
            $c['lat'] = $coords['lat'];
            $c['lng'] = $coords['lng'];

            return [$c];
        }
        if (trim($q) === '') {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($this->geocoder->searchDetailed($q, 5) as $raw) {
            $c = $level === AdresseLevel::Rue ? $this->parser->candidate($raw) : $this->parser->place($raw);
            if (isset($seen[$c['label']])) {
                continue;
            }
            $seen[$c['label']] = true;
            $out[] = $c;
        }

        return $out;
    }

    /**
     * Adresses existantes d'un niveau, les plus proches d'abord (celles sans position à la fin, seulement si leur nom correspond).
     *
     * @return array<string,mixed>
     */
    public function nearby(AdresseLevel $level, ?float $lat, ?float $lng, string $name = '', int $limit = 6): array {
        $type = $this->findType($level);
        $norm = AdresseLevel::normalize($name);
        $rows = [];
        if ($type !== null) {
            foreach ($this->adresses->findBy(['adresseType' => $type, 'deletedAt' => null]) as $a) {
                $located = $a->isGeolocalisee();
                $km = ($lat !== null && $lng !== null && $located) ? $this->km($lat, $lng, $a->getLatitude(), $a->getLongitude()) : null;
                $exact = $norm !== '' && AdresseLevel::normalize($a->getName()) === $norm;
                if ($km === null && !$exact && ($lat !== null && $lng !== null)) {
                    continue; // position inconnue et nom différent : inutile à proposer
                }
                $parent = $a->getAdresseParent();
                $rows[] = [
                    'id' => $a->getId(),
                    'label' => $a->getName(),
                    'parent' => $parent?->getName(),
                    'lat' => $located ? $a->getLatitude() : null,
                    'lng' => $located ? $a->getLongitude() : null,
                    'km' => $km !== null ? round($km, 1) : null,
                    'exact' => $exact,
                ];
            }
        }
        usort($rows, static fn($x, $y) => [$x['km'] ?? 1e9, !$x['exact'], $x['label']] <=> [$y['km'] ?? 1e9, !$y['exact'], $y['label']]);

        return ['level' => $level->value, 'label' => $level->label(), 'items' => array_slice($rows, 0, max(1, $limit))];
    }

    /**
     * Applique la chaîne choisie : liste ordonnée du plus bas au plus haut niveau,
     *   {level, mode:'existing', id}  ou  {level, mode:'new', name, adresse, lat, lng, ville, pays}.
     * Un niveau « existant » termine la chaîne (ses propres parents sont déjà en base).
     * Retourne le nombre d'adresses créées.
     */
    public function applyChain(Adresse $street, array $steps, ?float $lat, ?float $lng): int {
        $valid = array_map(static fn(AdresseLevel $l) => $l->value, AdresseLevel::chain());
        $picked = [];
        $seen = [];
        foreach ($steps as $step) {
            if (!is_array($step) || !in_array($step['level'] ?? null, $valid, true) || isset($seen[$step['level']])) {
                continue;
            }
            $seen[$step['level']] = true;
            $level = AdresseLevel::from($step['level']);
            if (($step['mode'] ?? '') === 'existing') {
                $existing = $this->adresses->find((int) ($step['id'] ?? 0));
                if ($existing instanceof Adresse && !$existing->isDeleted()) {
                    $picked[] = ['level' => $level, 'entity' => $existing];
                    break;
                }
            } elseif (($step['mode'] ?? '') === 'new' && trim((string) ($step['name'] ?? '')) !== '') {
                $picked[] = ['level' => $level, 'step' => $step];
            }
        }
        if ($picked === []) {
            return 0;
        }

        $parent = null;
        $created = 0;
        foreach (array_reverse($picked) as $p) {
            if (isset($p['entity'])) {
                $parent = $p['entity'];
                continue;
            }
            /** @var AdresseLevel $level */
            $level = $p['level'];
            $s = $p['step'];
            $name = mb_substr(trim((string) $s['name']), 0, 255);
            $ville = trim((string) ($s['ville'] ?? ''));
            $pays = trim((string) ($s['pays'] ?? '')) ?: (string) $street->getPays();

            $a = new Adresse();
            $a->setName($name)
                    ->setAdresseType($this->typeFor($level))
                    ->setAdresseParent($parent)
                    ->setNomVoie('')
                    ->setVille($level === AdresseLevel::Ville ? $name : ($level === AdresseLevel::Quartier ? ($ville ?: (string) $street->getVille()) : $ville))
                    ->setPays($level === AdresseLevel::Pays ? $name : $pays)
                    ->setAdresse(trim((string) ($s['adresse'] ?? '')) ?: $name)
                    ->setLatitude(is_numeric($s['lat'] ?? null) ? (float) $s['lat'] : $lat)
                    ->setLongitude(is_numeric($s['lng'] ?? null) ? (float) $s['lng'] : $lng);
            $this->em->persist($a);
            $parent = $a;
            $created++;
        }
        $street->setAdresseParent($parent);

        return $created;
    }

    /* ------------------------------------------------------------------ */

    private function findType(AdresseLevel $level): ?AdresseType {
        foreach ($this->em->getRepository(AdresseType::class)->findAll() as $t) {
            if (AdresseLevel::fromTypeName($t->getName()) === $level) {
                return $t;
            }
        }

        return null;
    }

    private function typeFor(AdresseLevel $level): AdresseType {
        $type = $this->findType($level);
        if ($type === null) {
            $type = (new AdresseType())->setName($level->label())->setColor('');
            $this->em->persist($type);
        }

        return $type;
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($h)));
    }
}
