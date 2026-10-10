<?php

namespace App\Service;

/**
 * Couleur d'une entité (type de tiers, portefeuille, projet, type d'adresse).
 *
 * Valeur stockée (colonne `couleur`, inchangée) :
 *   - null / ''        : couleur par défaut = accent du thème ;
 *   - '@a1' … '@a5'    : nuance d'une famille du thème, de 1 (très foncé) à 5 (très clair) ;
 *                        familles : a accent · i info · s succès · w alerte · d danger · n neutre ;
 *   - '@h60-1' … '@h300-5' : nuance d'une « autre teinte » (décalage de teinte depuis l'accent) ;
 *   - ancien format (toujours accepté, plus proposé) : '@1'…'@12', '@n1', '@info', '@ok', '@warn',
 *     '@danger', '@text', '@soft', '@muted', '@line' ;
 *   - '#rrggbb'        : couleur libre, figée (non liée au thème).
 * Colonne de 7 caractères : les jetons ne dépassent pas 7 caractères (« @h300-5 »).
 */
final class EntityColor {

    public const SLOTS = 12;

    /** Libellés des 5 nuances, de la plus foncée à la plus claire. */
    public const SHADES = [
        1 => 'Très foncé',
        2 => 'Foncé',
        3 => 'Classique',
        4 => 'Clair',
        5 => 'Très clair',
    ];

    /** Familles de couleurs définies par le thème (préfixe => libellé). */
    public const THEME_FAMILIES = [
        'a' => 'Accent',
        'i' => 'Info',
        's' => 'Succès',
        'w' => 'Alerte',
        'd' => 'Danger',
        'n' => 'Neutre',
    ];

    /** Autres teintes, décalées depuis l'accent (préfixe => libellé). */
    public const HUE_FAMILIES = [
        'h60' => 'Teinte +60°',
        'h120' => 'Teinte +120°',
        'h180' => 'Teinte +180°',
        'h240' => 'Teinte +240°',
        'h300' => 'Teinte +300°',
    ];

    private const TOKEN = '/^@(?:[aisdwn][1-5]|h(?:60|120|180|240|300)-[1-5]|[1-9]|1[0-2]|info|ok|warn|danger|text|soft|muted|line)$/';
    private const HEX = '/^#[0-9A-Fa-f]{6}$/';

    /**
     * Familles pour le sélecteur : groupes → familles → 5 nuances (foncé → clair).
     *
     * @return list<array{title:string, families:list<array{key:string,label:string,shades:list<array{value:string,var:string,label:string}>}>}>
     */
    public static function families(): array {
        $build = static function (array $defs, bool $dash): array {
            $out = [];
            foreach ($defs as $key => $label) {
                $shades = [];
                foreach (self::SHADES as $n => $shadeLabel) {
                    $slot = $key . ($dash ? '-' : '') . $n;
                    $shades[] = ['value' => '@' . $slot, 'var' => 'var(--palette-' . $slot . ')', 'label' => $label . ' · ' . mb_strtolower($shadeLabel)];
                }
                $out[] = ['key' => $key, 'label' => $label, 'shades' => $shades];
            }

            return $out;
        };

        return [
            ['title' => 'Couleurs du thème', 'families' => $build(self::THEME_FAMILIES, false)],
            ['title' => 'Autres teintes', 'families' => $build(self::HUE_FAMILIES, true)],
        ];
    }

    public static function isToken(?string $raw): bool {
        return $raw !== null && preg_match(self::TOKEN, $raw) === 1;
    }

    public static function isHex(?string $raw): bool {
        return $raw !== null && preg_match(self::HEX, $raw) === 1;
    }

    /** Valeur acceptée en saisie : vide, emplacement de palette ou #rrggbb. */
    public static function isValid(?string $raw): bool {
        return $raw === null || $raw === '' || self::isToken($raw) || self::isHex($raw);
    }

    /** Emplacement ("1".."12", "n1".."n5") ou null. */
    public static function slot(?string $raw): ?string {
        return self::isToken($raw) ? substr($raw, 1) : null;
    }

    /** Valeur CSS de la couleur (à poser dans --entity-color ou background). */
    public static function css(?string $raw, string $fallback = 'var(--accent)'): string {
        if (self::isToken($raw)) {
            return 'var(--palette-' . self::slot($raw) . ')';
        }
        if (self::isHex($raw)) {
            return $raw;
        }

        return $fallback;
    }

    /** Valeur CSS de la couleur du texte posé dessus (--entity-text). */
    public static function text(?string $raw): string {
        if (self::isToken($raw)) {
            return 'var(--palette-' . self::slot($raw) . '-ink)';
        }
        if (self::isHex($raw)) {
            $r = hexdec(substr($raw, 1, 2));
            $g = hexdec(substr($raw, 3, 2));
            $b = hexdec(substr($raw, 5, 2));

            return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 165 ? '#1F2937' : '#FFFFFF';
        }

        return 'var(--accent-contrast, #FFFFFF)';
    }
}
