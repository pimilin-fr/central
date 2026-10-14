<?php

namespace App\Service\Adresse;

use App\Entity\Adresse;
use App\Entity\AdresseType;
use App\Repository\AdresseRepository;
use App\Service\Geocoder\Geocoder;
use App\Service\Settings\AppSettings;
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
            private readonly AppSettings $settings,
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

    /** Marge ajoutée autour des points d'une zone (m) : même règle que la carte des adresses (maps.js). */

    /**
     * Adresses existantes d'un niveau proches d'une position, vues comme des ZONES.
     *
     * Une adresse de niveau supérieur (quartier, ville…) est la zone formée par toutes les adresses situées en dessous
     * d'elle (enveloppe convexe de leurs points + marge, comme sur la carte de visualisation) :
     *  - la distance est mesurée jusqu'au point le plus proche de la zone, pas jusqu'au « centre » de l'adresse ;
     *  - dans la zone → distance 0, « inside », et c'est la zone présélectionnée (la plus petite si plusieurs) ;
     *  - « trop éloigné » est relatif : la tolérance dépend du niveau et de la taille de la zone (une grande ville
     *    tolère plus qu'un petit quartier). `$all` lève ce filtre (bouton « voir plus éloignés »).
     *
     * @return array<string,mixed>
     */
    public function nearby(AdresseLevel $level, ?float $lat, ?float $lng, string $name = '', ?int $limit = null, bool $all = false, ?int $excludeId = null): array {
        $limit ??= $this->settings->get($all ? 'adresse.limit_all' : 'adresse.limit');
        $type = $this->findType($level);
        $norm = AdresseLevel::normalize($name);
        $hasPos = $lat !== null && $lng !== null;
        $rows = [];

        if ($type !== null) {
            $children = [];
            $everything = $this->adresses->findBy(['deletedAt' => null]);
            foreach ($everything as $a) {
                $children[$a->getAdresseParent()?->getId() ?? 0][] = $a;
            }
            // adresse en cours de modification : elle et tout ce qui est en dessous ne peuvent ni être son parent
            // (cycle) ni servir à dessiner la zone d'un candidat (sa position est peut-être justement à corriger)
            $excluded = [];
            if ($excludeId !== null) {
                $stack = [$excludeId];
                while ($stack !== []) {
                    $id = array_pop($stack);
                    if (isset($excluded[$id])) {
                        continue;
                    }
                    $excluded[$id] = true;
                    foreach ($children[$id] ?? [] as $c) {
                        $stack[] = $c->getId();
                    }
                }
                foreach ($children as $pid => $list) {
                    $children[$pid] = array_values(array_filter($list, static fn($c) => !isset($excluded[$c->getId()])));
                }
            }

            foreach ($everything as $a) {
                if ($a->getAdresseType()->getId() !== $type->getId() || isset($excluded[$a->getId()])) {
                    continue;
                }
                $points = $this->zonePoints($a, $children);
                $exact = $norm !== '' && AdresseLevel::normalize($a->getName()) === $norm;
                $parent = $a->getAdresseParent();
                $row = [
                    'id' => $a->getId(),
                    'label' => $a->getName(),
                    'parent' => $parent?->getName(),
                    'lat' => $a->isGeolocalisee() ? $a->getLatitude() : null,
                    'lng' => $a->isGeolocalisee() ? $a->getLongitude() : null,
                    'dist' => null,
                    'inside' => false,
                    'diameter' => 0,
                    'exact' => $exact,
                    'shape' => null,
                    'nearest' => null,
                    'points' => count($points),
                ];
                if ($hasPos && $points !== []) {
                    $row = $this->measure($row, $points, $lat, $lng);
                } elseif ($hasPos && !$exact && !in_array($level, [AdresseLevel::Zone, AdresseLevel::Planete], true)) {
                    continue; // aucune position connue et nom différent : rien à comparer (zone/planète : toujours proposées, pas de géométrie)
                }
                $rows[] = $row;
            }
        }

        usort($rows, static fn($x, $y) => [!$x['inside'], $x['inside'] ? $x['diameter'] : 0, $x['dist'] ?? 1e12, !$x['exact'], $x['label']]
                <=> [!$y['inside'], $y['inside'] ? $y['diameter'] : 0, $y['dist'] ?? 1e12, !$y['exact'], $y['label']]);

        $tolBase = in_array($level->value, ['quartier', 'ville', 'agglomeration', 'departement', 'region', 'pays'], true) ? 1000.0 * $this->settings->get('adresse.tolerance.' . $level->value) : 1e12; // réglé en km
        $ratio = $this->settings->get('adresse.tolerance.ratio') / 100;
        // « near » : dans la zone, ou assez proche pour être considérée comme le même endroit (même règle que la pertinence)
        foreach ($rows as $i => $r) {
            $rows[$i]['near'] = $r['inside'] || $r['dist'] === null || $r['dist'] <= max($tolBase, $ratio * $r['diameter']);
        }
        $relevant = array_values(array_filter($rows, static fn($r) => $r['near'] || $r['exact']));
        $pool = $all ? $rows : $relevant;
        $items = array_slice($pool, 0, max(1, $limit));
        $preselect = ($items !== [] && $items[0]['inside']) ? $items[0]['id'] : null;

        return [
            'level' => $level->value,
            'label' => $level->label(),
            'items' => $items,
            'preselect' => $preselect,
            'hasMore' => count($rows) > count($items),
        ];
    }

    /**
     * Points qui forment la zone d'une adresse : ceux des adresses situées en dessous d'elle (et le sien, sauf pour les
     * niveaux grossiers : le « centre » d'un département, d'une région ou d'un pays fourni par OpenStreetMap n'est pas
     * une adresse réelle et étirerait la zone). Sans aucun point en dessous, on retombe sur son propre point.
     *
     * @return list<array{0:float,1:float}>
     */
    private function zonePoints(Adresse $root, array $children): array {
        $points = [];
        $stack = [$root];
        $seen = [];
        while ($stack !== []) {
            $a = array_pop($stack);
            if (isset($seen[$a->getId()])) {
                continue;
            }
            $seen[$a->getId()] = true;
            $level = AdresseLevel::fromTypeName($a->getAdresseType()->getName());
            if ($a->isGeolocalisee() && !($level?->isCoarse() ?? false)) {
                $points[] = [(float) $a->getLatitude(), (float) $a->getLongitude()];
            }
            foreach ($children[$a->getId()] ?? [] as $c) {
                $stack[] = $c;
            }
        }
        if ($points === [] && $root->isGeolocalisee()) {
            $points[] = [(float) $root->getLatitude(), (float) $root->getLongitude()];
        }

        return $points;
    }

    /**
     * Distance de la position à la zone (0 dedans), forme de la zone et point le plus proche.
     * Géométrie en mètres sur une projection plane locale (suffisant à l'échelle d'une région).
     */
    private function measure(array $row, array $points, float $lat, float $lng): array {
        $k = cos(deg2rad($lat)) * 111320.0;
        $proj = static fn(array $p): array => [($p[1] - $lng) * $k, ($p[0] - $lat) * 110574.0];
        $unproj = static fn(array $xy): array => [$lat + $xy[1] / 110574.0, $lng + $xy[0] / $k];

        $hull = self::hull(array_map($proj, $points));
        $n = count($hull);
        $margin = (float) $this->settings->get($n >= 3 ? 'adresse.margin.many' : 'adresse.margin.few');
        $me = [0.0, 0.0];

        if ($n >= 3 && self::inPolygon($me, $hull)) {
            $d = 0.0;
            $near = $me;
        } else {
            [$d, $near] = self::closest($me, $hull);
            $d = max(0.0, $d - $margin);
        }

        $diameter = 0.0;
        foreach ($hull as $a) {
            foreach ($hull as $b) {
                $diameter = max($diameter, hypot($a[0] - $b[0], $a[1] - $b[1]));
            }
        }
        $diameter += 2 * $margin;

        if ($n >= 3) { // enveloppe élargie de la marge, pour le dessin
            $cx = array_sum(array_column($hull, 0)) / $n;
            $cy = array_sum(array_column($hull, 1)) / $n;
            $shape = ['type' => 'polygon', 'pts' => array_map(static function (array $v) use ($cx, $cy, $margin, $unproj): array {
                $len = max(1e-6, hypot($v[0] - $cx, $v[1] - $cy));

                return $unproj([$v[0] + ($v[0] - $cx) / $len * $margin, $v[1] + ($v[1] - $cy) / $len * $margin]);
            }, $hull)];
        } else {
            $c = $n === 2 ? [($hull[0][0] + $hull[1][0]) / 2, ($hull[0][1] + $hull[1][1]) / 2] : $hull[0];
            $shape = ['type' => 'circle', 'center' => $unproj($c), 'r' => (int) round($margin + ($n === 2 ? hypot($hull[0][0] - $hull[1][0], $hull[0][1] - $hull[1][1]) / 2 : 0))];
        }

        $row['inside'] = $d <= 0.0;
        $row['dist'] = (int) round($d);
        $row['diameter'] = (int) round($diameter);
        $row['shape'] = $shape;
        $row['nearest'] = $unproj($near);
        if ($row['lat'] === null) { // pas de point propre : repère au centre de la zone
            $center = $unproj([array_sum(array_column($hull, 0)) / $n, array_sum(array_column($hull, 1)) / $n]);
            $row['lat'] = $center[0];
            $row['lng'] = $center[1];
        }

        return $row;
    }

    /** Enveloppe convexe (monotone chain) de points [x, y]. */
    private static function hull(array $pts): array {
        $pts = array_values(array_unique(array_map(static fn($p) => round($p[0], 2) . ',' . round($p[1], 2), $pts)));
        $pts = array_map(static fn($s) => array_map('floatval', explode(',', $s)), $pts);
        sort($pts);
        if (count($pts) <= 2) {
            return $pts;
        }
        $cross = static fn($o, $a, $b) => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
        $lower = [];
        foreach ($pts as $p) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) {
                array_pop($lower);
            }
            $lower[] = $p;
        }
        $upper = [];
        foreach (array_reverse($pts) as $p) {
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) {
                array_pop($upper);
            }
            $upper[] = $p;
        }
        array_pop($lower);
        array_pop($upper);

        return array_merge($lower, $upper);
    }

    private static function inPolygon(array $p, array $poly): bool {
        $in = false;
        for ($i = 0, $j = count($poly) - 1; $i < count($poly); $j = $i++) {
            if ((($poly[$i][1] > $p[1]) !== ($poly[$j][1] > $p[1]))
                    && ($p[0] < ($poly[$j][0] - $poly[$i][0]) * ($p[1] - $poly[$i][1]) / ($poly[$j][1] - $poly[$i][1]) + $poly[$i][0])) {
                $in = !$in;
            }
        }

        return $in;
    }

    /** Point le plus proche de p sur une forme (1 point, 1 segment ou le contour d'un polygone). @return array{0:float,1:array} */
    private static function closest(array $p, array $shape): array {
        $n = count($shape);
        if ($n === 1) {
            return [hypot($p[0] - $shape[0][0], $p[1] - $shape[0][1]), $shape[0]];
        }
        $best = [INF, $shape[0]];
        $edges = $n === 2 ? 1 : $n;
        for ($i = 0; $i < $edges; $i++) {
            $a = $shape[$i];
            $b = $shape[($i + 1) % $n];
            $dx = $b[0] - $a[0];
            $dy = $b[1] - $a[1];
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len2)) : 0.0;
            $q = [$a[0] + $t * $dx, $a[1] + $t * $dy];
            $d = hypot($p[0] - $q[0], $p[1] - $q[1]);
            if ($d < $best[0]) {
                $best = [$d, $q];
            }
        }

        return $best;
    }

    /**
     * Applique la chaîne choisie : liste ordonnée du plus bas au plus haut niveau,
     *   {level, mode:'existing', id}  ou  {level, mode:'new', name, adresse, lat, lng, ville, pays}.
     * Un niveau « existant » termine la chaîne (ses propres parents sont déjà en base).
     * Retourne le nombre d'adresses créées.
     */
    /** Noms de niveaux déjà existants réutilisés au lieu d'être recréés (doublon évité) lors du dernier applyChain. @var list<string> */
    public array $reused = [];

    public function applyChain(Adresse $street, array $steps, ?float $lat, ?float $lng): int {
        $this->reused = [];
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
                if ($existing instanceof Adresse && !$existing->isDeleted() && !$this->isSelfOrDescendant($existing, $street)) {
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

            // même nom, même niveau, même parent : on réutilise l'existant (deux villes différentes peuvent, elles, avoir un quartier de même nom)
            $twin = $this->findTwin($level, $name, $parent);
            if ($twin !== null) {
                $parent = $twin;
                $this->reused[] = $name;
                continue;
            }

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

    /** Adresse active du même niveau, de même nom (insensible casse/accents) et de même parent, s'il y en a une. */
    private function findTwin(AdresseLevel $level, string $name, ?Adresse $parent): ?Adresse {
        $type = $this->findType($level);
        if ($type === null || ($parent !== null && $parent->getId() === null)) {
            return null; // type encore inexistant, ou parent tout juste créé (pas encore en base) : aucun jumeau possible
        }
        $norm = AdresseLevel::normalize($name);
        foreach ($this->adresses->findBy(['adresseType' => $type, 'adresseParent' => $parent, 'deletedAt' => null]) as $a) {
            if (AdresseLevel::normalize($a->getName()) === $norm) {
                return $a;
            }
        }

        return null;
    }

    /** Vrai si $candidate est $street ou l'un de ses descendants (un parent ne peut pas être sous l'adresse elle-même). */
    private function isSelfOrDescendant(Adresse $candidate, Adresse $street): bool {
        if ($street->getId() === null) {
            return false;
        }
        for ($n = 0, $cur = $candidate; $cur !== null && $n < 50; $n++, $cur = $cur->getAdresseParent()) {
            if ($cur->getId() === $street->getId()) {
                return true;
            }
        }

        return false;
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
}
