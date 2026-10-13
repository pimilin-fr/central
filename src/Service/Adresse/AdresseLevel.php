<?php

namespace App\Service\Adresse;

/**
 * Hiérarchie des adresses : Rue < Quartier < Ville < Département < Région < Pays < Zone géographique < Planète.
 *
 * Les niveaux sont reliés aux AdresseType EXISTANTS par leur nom (insensible à la casse et aux accents,
 * avec quelques synonymes) : aucune colonne n'est ajoutée en base.
 */
enum AdresseLevel: string {

    case Rue = 'rue';
    case Quartier = 'quartier';
    case Ville = 'ville';
    case Departement = 'departement';
    case Region = 'region';
    case Pays = 'pays';
    case Zone = 'zone';
    case Planete = 'planete';

    /** Niveaux parents proposés / créés par l'assistant, du plus proche de la rue au plus haut. */
    public static function chain(): array {
        return [self::Quartier, self::Ville, self::Departement, self::Region, self::Pays, self::Zone, self::Planete];
    }

    /** Nom d'AdresseType créé si le niveau n'existe pas encore en base. */
    public function label(): string {
        return match ($this) {
            self::Rue => 'Rue',
            self::Quartier => 'Quartier',
            self::Ville => 'Ville',
            self::Departement => 'Département',
            self::Region => 'Région',
            self::Pays => 'Pays',
            self::Zone => 'Zone géographique',
            self::Planete => 'Planète',
        };
    }

    /** Noms de type reconnus (normalisés). */
    public function aliases(): array {
        return match ($this) {
            self::Rue => ['rue', 'voie'],
            self::Quartier => ['quartier', 'secteur'],
            self::Ville => ['ville', 'commune'],
            self::Departement => ['departement', 'dept'],
            self::Region => ['region'],
            self::Pays => ['pays', 'country'],
            self::Zone => ['zone geographique', 'zone'],
            self::Planete => ['planete', 'terre'],
        };
    }

    /** Rayon (km) dans lequel une adresse existante de ce niveau est proposée par proximité. */
    public function radiusKm(): float {
        return match ($this) {
            self::Quartier => 5.0,
            self::Ville => 25.0,
            self::Departement => 120.0,
            self::Region => 400.0,
            default => 0.0,
        };
    }

    public static function normalize(?string $s): string {
        $s = mb_strtolower(trim((string) $s));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', '’' => "'", '–' => '-', '—' => '-',
        ]);

        return trim(preg_replace('/[\s\-]+/u', ' ', $s));
    }

    public static function fromTypeName(?string $name): ?self {
        $n = self::normalize($name);
        foreach (self::cases() as $case) {
            if (in_array($n, $case->aliases(), true)) {
                return $case;
            }
        }

        return null;
    }
}
