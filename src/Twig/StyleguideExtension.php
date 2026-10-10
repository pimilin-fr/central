<?php

namespace App\Twig;

use Throwable;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Styleguide : {{ sg_render(code) }} exécute un extrait Twig/HTML pour en montrer l'aperçu.
 * Le MÊME texte sert à l'aperçu et au bloc « Voir le code » : ce qu'on voit est exactement ce qu'on copie.
 * Une erreur dans un extrait s'affiche dans son aperçu sans casser le reste de la page.
 */
final class StyleguideExtension extends AbstractExtension {

    public function getFunctions(): array {
        return [
            new TwigFunction('sg_render', $this->render(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    public function render(Environment $twig, string $code): Markup {
        // Les icônes <twig:ux:icon name="…" /> sont converties en ux_icon() quand la fonction existe (comportement identique, plus robuste dans un extrait).
        if ($twig->getFunction('ux_icon') !== false && $twig->getFunction('ux_icon') !== null) {
            $code = preg_replace_callback(
                '#<twig:ux:icon\s+name="([^"]+)"(?:\s+class="([^"]*)")?\s*/>#',
                static fn (array $m): string => "{{ ux_icon('" . $m[1] . "'" . (isset($m[2]) && $m[2] !== '' ? ", {class: '" . $m[2] . "'}" : '') . ') }}',
                $code
            ) ?? $code;
        }

        try {
            return new Markup($twig->createTemplate($code)->render([]), 'UTF-8');
        } catch (Throwable $e) {
            return new Markup('<p class="rc-msg" role="alert">Aperçu indisponible : ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>', 'UTF-8');
        }
    }
}
