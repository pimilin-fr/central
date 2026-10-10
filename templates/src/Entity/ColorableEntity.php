<?php

namespace App\Entity;

use App\Service\EntityColor;

/**
 * Entité portant une couleur (voir App\Service\EntityColor pour les formats stockés :
 * vide = accent du thème, "@N" = emplacement de palette du thème, "#rrggbb" = couleur libre).
 */
abstract class ColorableEntity {

    abstract public function getCouleur(): ?string;

    /** Valeur brute stockée. */
    public function getColor() {
        return $this->getCouleur();
    }

    /** Valeur CSS prête à poser dans --entity-color (suit le thème pour les emplacements de palette). */
    public function getCssColor(string $fallback = 'var(--accent)'): string {
        return EntityColor::css($this->getCouleur(), $fallback);
    }

    /** Valeur CSS de la couleur du texte posé sur la couleur de l'entité (--entity-text). */
    public function getTextColor(): string {
        return EntityColor::text($this->getCouleur());
    }
}
