<?php

namespace App\Twig;

use App\Service\EntityColor;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Couleurs d'entités liées au thème.
 *   {{ (entity.couleur ?? null)|entity_color }}                 → var(--palette-3) | #1a2b3c | var(--accent)
 *   {{ (entity.couleur ?? null)|entity_color('var(--border)') }} → avec une autre valeur par défaut
 *   {{ (entity.couleur ?? null)|entity_text }}                  → couleur du texte posé dessus
 *   {{ entity_palette_slots() }}                                → [1..12]
 *   {{ entity_vivid_slots() }} / {{ entity_neutral_slots() }}   → [clé => libellé] des couleurs vives / neutres du thème
 */
final class EntityColorExtension extends AbstractExtension {

    public function getFilters(): array {
        return [
            new TwigFilter('entity_color', static fn (?string $raw, string $fallback = 'var(--accent)'): string => EntityColor::css($raw, $fallback)),
            new TwigFilter('entity_text', static fn (?string $raw): string => EntityColor::text($raw)),
        ];
    }

    public function getFunctions(): array {
        return [
            new TwigFunction('entity_palette_slots', static fn (): array => range(1, EntityColor::SLOTS)),
            new TwigFunction('entity_vivid_slots', static fn (): array => EntityColor::VIVID),
            new TwigFunction('entity_neutral_slots', static fn (): array => EntityColor::NEUTRAL),
        ];
    }
}
