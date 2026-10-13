<?php

namespace App\Service\Adresse;

use App\Form\AdresseFormType;

/**
 * Transforme un résultat Nominatim (addressdetails=1) en champs du formulaire Adresse
 * + noms des niveaux parents (quartier, ville, département, région, pays).
 *
 * La logique dépend du pays :
 *  - FR_LIKE (France, Belgique, Luxembourg, Suisse, Monaco) : « 12 bis rue de la Paix » → n°, bis, type de voie (liste du formulaire), nom de voie ;
 *  - autres pays : la voie complète est mise dans « Voie », sans type (le type de voie du formulaire est une liste française).
 * Pour ajouter un pays : ajouter son code ISO dans FR_LIKE s'il suit la même logique, ou écrire une méthode dédiée dans streetFor().
 */
final class AdresseParser {

    private const FR_LIKE = ['fr', 'be', 'lu', 'ch', 'mc'];

    /** @return array<string,mixed> candidat prêt pour le JS */
    public function candidate(array $r, ?float $lat = null, ?float $lng = null): array {
        $a = is_array($r['address'] ?? null) ? $r['address'] : [];
        $cc = strtolower((string) ($a['country_code'] ?? ''));

        $road = (string) ($a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? $a['path'] ?? $a['cycleway'] ?? $a['square'] ?? $r['name'] ?? '');
        [$typeVoie, $nomVoie] = $this->streetFor($cc, $road);
        [$num, $bis] = $this->houseNumber((string) ($a['house_number'] ?? ''));

        $ville = $this->first($a, ['city', 'town', 'village', 'municipality', 'hamlet']) ?? '';
        $quartier = $this->first($a, ['suburb', 'neighbourhood', 'quarter', 'city_district', 'borough']);
        if ($quartier !== null && AdresseLevel::normalize($quartier) === AdresseLevel::normalize($ville)) {
            $quartier = null;
        }
        $cp = trim(explode(';', (string) ($a['postcode'] ?? ''))[0]);
        $pays = (string) ($a['country'] ?? '');

        $line1 = trim(implode(' ', array_filter([$num, $bis, $typeVoie, $nomVoie], static fn($v) => $v !== null && $v !== '')));
        $full = implode(', ', array_filter([$line1, trim($cp . ' ' . $ville), $pays]));
        $voie = trim(($typeVoie ?? '') . ' ' . $nomVoie);

        return [
            'label' => (string) ($r['display_name'] ?? $full),
            'lat' => $lat ?? (isset($r['lat']) ? (float) $r['lat'] : null),
            'lng' => $lng ?? (isset($r['lon']) ? (float) $r['lon'] : null),
            'cc' => $cc,
            'adresse' => $full,
            'name' => trim(implode(', ', array_filter([trim(($num ?? '') . ' ' . $voie), $ville]))),
            'fields' => [
                'prefix' => '',
                'num' => $num ?? '',
                'bisTer' => $bis ?? '',
                'typeVoie' => $typeVoie ?? '',
                'nomVoie' => $nomVoie,
                'codePostal' => $cp,
                'ville' => $ville,
                'cedex' => '',
                'pays' => $pays,
            ],
            'ancestors' => $this->ancestors($a, $ville, $quartier),
        ];
    }

    /**
     * Résultat Nominatim pour un lieu de niveau supérieur (quartier, ville, département…) saisi « comme une adresse ».
     *
     * @return array<string,mixed>
     */
    public function place(array $r): array {
        $a = is_array($r['address'] ?? null) ? $r['address'] : [];
        $display = (string) ($r['display_name'] ?? '');
        $name = trim((string) ($r['name'] ?? '')) ?: trim(explode(',', $display)[0]);
        $ville = $this->first($a, ['city', 'town', 'village', 'municipality', 'hamlet']) ?? '';
        $pays = (string) ($a['country'] ?? '');

        return [
            'label' => $display !== '' ? $display : $name,
            'name' => $name,
            'adresse' => $display !== '' ? $display : $name,
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lon']) ? (float) $r['lon'] : null,
            'cc' => strtolower((string) ($a['country_code'] ?? '')),
            'fields' => ['ville' => $ville, 'pays' => $pays],
            'ancestors' => $this->ancestors($a, $ville, $this->first($a, ['suburb', 'neighbourhood', 'quarter', 'city_district', 'borough'])),
        ];
    }

    /** @return array<string,?string> */
    private function ancestors(array $a, string $ville, ?string $quartier): array {
        $pays = (string) ($a['country'] ?? '');

        return [
            'quartier' => $quartier,
            'ville' => $ville !== '' ? $ville : null,
            'departement' => $this->first($a, ['county', 'state_district']),
            'region' => $this->first($a, ['state', 'region']),
            'pays' => $pays !== '' ? $pays : null,
        ];
    }

    /** @return array{0:?string,1:string} [type de voie, nom de voie] */
    private function streetFor(string $cc, string $road): array {
        $road = trim($road);
        if ($road === '' || !in_array($cc, self::FR_LIKE, true)) {
            return [null, $road];
        }

        $types = array_values(array_unique(array_values(AdresseFormType::MAP_VOIE)));
        usort($types, static fn($x, $y) => mb_strlen($y) <=> mb_strlen($x));
        foreach ($types as $type) {
            if (mb_strlen($type) <= 10 && preg_match('/^' . preg_quote($type, '/') . '\s+(.+)$/iu', $road, $m)) {
                return [$type, trim($m[1])];
            }
        }

        return [null, $road];
    }

    /** @return array{0:?string,1:?string} [numéro, bis/ter] ("12bis", "12 B", "12-14" …) */
    private function houseNumber(string $hn): array {
        $hn = trim($hn);
        if ($hn !== '' && preg_match('/^(\d+)\s*[-\/ ]?\s*(.*)$/u', $hn, $m)) {
            $rest = trim($m[2]);

            return [$m[1], $rest !== '' ? mb_substr($rest, 0, 10) : null];
        }

        return [null, null];
    }

    private function first(array $a, array $keys): ?string {
        foreach ($keys as $k) {
            if (isset($a[$k]) && trim((string) $a[$k]) !== '') {
                return trim((string) $a[$k]);
            }
        }

        return null;
    }
}
