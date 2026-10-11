<?php

namespace App\Twig;

use App\Service\UiCommandScanner;
use App\Service\UiPageLocator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose à la thématique « Inventaire » du styleguide :
 *  - sg_commandes() : l'inventaire automatique des commandes (boutons, liens, onglets…) ;
 *  - sg_vues(rapport) : pour chaque fichier, les pages de l'application où il s'affiche (lien « Voir »).
 * Temporaire : à retirer avec UiCommandScanner et UiPageLocator une fois l'uniformisation terminée.
 */
final class UiCommandExtension extends AbstractExtension
{
    public function __construct(private readonly UiCommandScanner $scanner, private readonly UiPageLocator $locator)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sg_commandes', $this->scanner->report(...)),
            new TwigFunction('sg_vues', fn (array $rapport): array => $this->locator->pagesFor(array_keys($rapport['files']))),
        ];
    }
}
