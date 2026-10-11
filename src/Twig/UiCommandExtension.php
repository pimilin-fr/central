<?php

namespace App\Twig;

use App\Service\UiCommandScanner;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose l'inventaire automatique des commandes (boutons, liens, onglets…) à la thématique « Inventaire » du styleguide.
 * Temporaire : à retirer avec UiCommandScanner une fois l'uniformisation terminée.
 */
final class UiCommandExtension extends AbstractExtension
{
    public function __construct(private readonly UiCommandScanner $scanner)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('sg_commandes', $this->scanner->report(...))];
    }
}
