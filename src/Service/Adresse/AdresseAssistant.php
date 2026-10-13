<?php

namespace App\Service\Adresse;

use App\Entity\Adresse;
use App\Entity\AdresseType;
use App\Repository\AdresseRepository;
use App\Service\Geocoder\Geocoder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saisie assistée d'une adresse :
 *  1. locate()  : adresse complète (ou coordonnées) → candidats avec champs décomposés (voir AdresseParser) ;
 *  2. levels()  : pour chaque niveau parent (quartier, ville, département, région, pays) → adresses existantes proches
 *                 (même nom et/ou à proximité) et proposition de création ;
 *  3. applyChain() : à l'enregistrement, crée les niveaux manquants (du plus haut au plus bas) et rattache la rue.
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
    public function locate(string $q, ?array $coords): array {
        if ($coords !== null) {
            $raw = $this->geocoder->reverseDetailed($coords['lat'], $coords['lng']);

            return $raw === null ? [] : [$this->parser->candidate($raw, $coords['lat'], $coords['lng'])];
        }
        if (trim($q) === '') {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($this->geocoder->searchDetailed($q, 5) as $raw) {
            $c = $this->parser->candidate($raw);
            if (isset($seen[$c['adresse']])) {
                continue;
            }
            $seen[$c['adresse']] = true;
            $out[] = $c;
        }

        return $out;
    }

    /**
     * @param array<string,?string> $anc noms des niveaux (clés : quartier, ville, departement, region, pays)
     * @return list<array<string,mixed>>
     */
    public function levels(array $anc, ?float $lat, ?float $lng): array {
        $out = [];
        foreach (AdresseLevel::chain() as $level) {
            $name = trim((string) ($anc[$level->value] ?? ''));
            if ($name === '' && $level !== AdresseLevel::Quartier) {
                continue; // seul le quartier est proposé sans nom (saisie libre possible)
            }
            $options = $this->options($level, $name, $lat, $lng);
            $out[] = [
                'key' => $level->value,
                'label' => $level->label(),
                'name' => $name,
                'options' => $options,
                'choice' => $this->preselect($level, $name, $options),
            ];
        }

        return $out;
    }

    /**
     * Applique le choix de l'utilisateur : chain[niveau][choice] = id:12 | new | none, chain[niveau][name].
     * Le plus bas niveau « existant » arrête la chaîne (son propre parent est déjà en base).
     * Retourne le nombre d'adresses créées.
     */
    public function applyChain(Adresse $street, array $chain, ?float $lat, ?float $lng): int {
        $picked = [];
        foreach (AdresseLevel::chain() as $level) {
            $c = $chain[$level->value] ?? null;
            if (!is_array($c)) {
                continue;
            }
            $choice = (string) ($c['choice'] ?? 'none');
            $name = trim((string) ($c['name'] ?? ''));
            if (str_starts_with($choice, 'id:')) {
                $existing = $this->adresses->find((int) substr($choice, 3));
                if ($existing instanceof Adresse && !$existing->isDeleted()) {
                    $picked[] = ['level' => $level, 'entity' => $existing];
                    break;
                }
            } elseif ($choice === 'new' && $name !== '') {
                $picked[] = ['level' => $level, 'name' => $name];
            }
        }
        if ($picked === []) {
            return 0;
        }

        $cityName = trim((string) ($chain['ville']['name'] ?? '')) ?: (string) $street->getVille();
        $parent = null;
        $created = 0;
        $lookups = 0;
        foreach (array_reverse($picked) as $p) {
            if (isset($p['entity'])) {
                $parent = $p['entity'];
                continue;
            }
            /** @var AdresseLevel $level */
            $level = $p['level'];
            $a = new Adresse();
            $a->setName($p['name'])
                    ->setAdresseType($this->typeFor($level))
                    ->setAdresseParent($parent)
                    ->setNomVoie('')
                    ->setVille($level === AdresseLevel::Ville ? $p['name'] : $cityName)
                    ->setPays((string) $street->getPays())
                    ->setAdresse(implode(', ', array_filter([$p['name'], $level === AdresseLevel::Quartier ? $cityName : null, $street->getPays()])));

            // position : recherche du lieu lui-même (1 requête/s max), sinon position de la rue
            $query = $this->placeQuery($level, $p['name'], $chain, (string) $street->getPays());
            if ($lookups++ > 0) {
                sleep(1);
            }
            $pt = $this->geocoder->lookupPoint($query);
            $a->setLatitude($pt['lat'] ?? $lat)->setLongitude($pt['lng'] ?? $lng);

            $this->em->persist($a);
            $parent = $a;
            $created++;
        }
        $street->setAdresseParent($parent);

        return $created;
    }

    /* ------------------------------------------------------------------ */

    private function placeQuery(AdresseLevel $level, string $name, array $chain, string $pays): string {
        $parts = [$name];
        $above = false;
        foreach (AdresseLevel::chain() as $l) {
            if ($l === $level) {
                $above = true;
                continue;
            }
            $n = trim((string) ($chain[$l->value]['name'] ?? ''));
            if ($above && $n !== '' && in_array($l, [AdresseLevel::Ville, AdresseLevel::Departement], true)) {
                $parts[] = $n;
            }
        }
        $parts[] = $pays;

        return implode(', ', array_filter($parts));
    }

    /** @return list<array<string,mixed>> */
    private function options(AdresseLevel $level, string $name, ?float $lat, ?float $lng): array {
        $type = $this->findType($level);
        if ($type === null) {
            return [];
        }
        $norm = AdresseLevel::normalize($name);
        $radius = $level->radiusKm();
        $rows = [];
        foreach ($this->adresses->findBy(['adresseType' => $type, 'deletedAt' => null]) as $a) {
            $n = AdresseLevel::normalize($a->getName());
            $dist = ($lat !== null && $lng !== null && $a->isGeolocalisee()) ? $this->km($lat, $lng, $a->getLatitude(), $a->getLongitude()) : null;
            $exact = $norm !== '' && $n === $norm && ($dist === null || $radius === 0.0 || $dist <= $radius * 2);
            $like = !$exact && $norm !== '' && mb_strlen($norm) >= 4 && (str_contains($n, $norm) || str_contains($norm, $n)) && mb_strlen($n) >= 4;
            $near = $dist !== null && $radius > 0 && $dist <= $radius;
            if (!$exact && !$like && !$near) {
                continue;
            }
            $parent = $a->getAdresseParent();
            $rows[] = [
                'id' => $a->getId(),
                'label' => $a->getName() . ($parent ? ' · ' . $parent->getName() : ''),
                'km' => $dist !== null ? round($dist, 1) : null,
                'exact' => $exact,
            ];
        }
        usort($rows, static fn($x, $y) => [!$x['exact'], $x['km'] ?? 9999] <=> [!$y['exact'], $y['km'] ?? 9999]);

        return array_slice($rows, 0, 8);
    }

    private function preselect(AdresseLevel $level, string $name, array $options): string {
        foreach ($options as $o) {
            if ($o['exact']) {
                return 'id:' . $o['id'];
            }
        }
        if ($level === AdresseLevel::Quartier && $options !== [] && ($options[0]['km'] ?? 99) <= 1.5) {
            return 'id:' . $options[0]['id'];
        }

        return $name !== '' ? 'new' : 'none';
    }

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
