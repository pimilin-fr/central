<?php

namespace App\Twig;

use App\Entity\Tiers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Fonctions Twig liées aux tiers. */
final class TiersExtension extends AbstractExtension {

    public function getFunctions(): array {
        return [
            // Personne = case « Personne physique » du tiers (société par défaut)
            new TwigFunction('tiers_is_person', static fn (Tiers $tiers): bool => $tiers->isPersonne()),
        ];
    }
}
