<?php

namespace App\Service;

/**
 * Couleur d'une entité (type de tiers, portefeuille, projet, type d'adresse).
 *
 * Valeur stockée (colonne `couleur`, inchangée) :
 *   - null / ''      : couleur par défaut = accent du thème ;
 *   - '@1' … '@12'   : emplacement de la palette du thème (--palette-N) : la couleur
 *                      suit le thème (public/css/palette.css) ;
 *   - '@info' '@ok' '@warn' '@danger' : couleurs vives définies par le thème ;
 *   - '@text' '@soft' '@muted' '@line' : neutres définis par le thème ;
 *   - '#rrggbb'      : couleur libre, figée (non liée au thème).
 */
final class EntityColor {

    public const SLOTS = 12;

    /** Couleurs vives déjà définies par le thème (info, succès, alerte, danger). */
    public const VIVID = [
        'info' => 'Info',
        'ok' => 'Succès',
        'warn' => 'Alerte',
        'danger' => 'Danger',
    ];

    /** Neutres déjà définis par le thème (texte, texte secondaire, texte discret, bordure). */
    public const NEUTRAL = [
        'text' => 'Texte',
        'soft' => 'Texte secondaire',
        'muted' => 'Texte discret',
        'line' => 'Bordure',
    ];

    // @1..@12 = nuancier calculé ; @info… / @text… = couleurs du thème ; @n1..@n5 = ancien format (toujours accepté)
    private const TOKEN = '/^@([1-9]|1[0-2]|n[1-5]|info|ok|warn|danger|text|soft|muted|line)$/';
    private const HEX = '/^#[0-9A-Fa-f]{6}$/';

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
