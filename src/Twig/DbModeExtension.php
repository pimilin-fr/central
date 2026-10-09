<?php

namespace App\Twig;

use App\Demo\DbMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Fonctions Twig du sélecteur d'environnement (menu en haut à droite + bandeau démo). */
final class DbModeExtension extends AbstractExtension {

    public const TOKEN_KEY = '_env_switch_token';

    public function __construct(
        private RequestStack $requestStack,
        #[Autowire('%kernel.project_dir%')] private string $projectDir
    ) {
    }

    public function getFunctions(): array {
        return [
            new TwigFunction('app_db_mode', fn (): string => DbMode::current()),
            new TwigFunction('app_db_building', fn (): bool => DbMode::isBuilding($this->projectDir)),
            new TwigFunction('app_db_last_build', fn (): ?array => DbMode::lastBuild($this->projectDir)),
            new TwigFunction('app_env_token', $this->token(...)),
        ];
    }

    /** Jeton anti-CSRF léger (session) pour les actions de bascule / reconstruction. */
    public function token(): string {
        $session = $this->requestStack->getSession();
        $token = $session->get(self::TOKEN_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $session->set(self::TOKEN_KEY, $token);
        }

        return $token;
    }
}
