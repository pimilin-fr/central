<?php

namespace App\Demo;

/**
 * Environnement de base de données de l'application : « prod » (Central) ou « demo ».
 *
 * L'état tient dans UN petit fichier : var/db_mode (« prod » ou « demo »). Il est lu à chaque
 * démarrage du Kernel (voir Kernel::boot) : changer d'environnement ne demande donc NI cache:clear
 * NI modification de .env / .env.local, et supprimer ce fichier = retour à la configuration du .env (prod).
 *
 * Sécurité : le mode « demo » n'est appliqué que si DATABASE_DEMO_URL est définie, différente de la prod
 * et que le nom de sa base contient « demo ». Sinon on reste sur la prod.
 */
final class DbMode {

    public const PROD = 'prod';
    public const DEMO = 'demo';

    public static function file(string $projectDir): string {
        return $projectDir . '/var/db_mode';
    }

    public static function lockDir(string $projectDir): string {
        return $projectDir . '/var/demo.lock';
    }

    public static function statusFile(string $projectDir): string {
        return $projectDir . '/var/demo-build.status';
    }

    /** Mode demandé par var/db_mode, ou null si le fichier n'existe pas / est invalide. */
    public static function requested(string $projectDir): ?string {
        $content = @file_get_contents(self::file($projectDir));
        $mode = is_string($content) ? strtolower(trim($content)) : '';

        return in_array($mode, [self::PROD, self::DEMO], true) ? $mode : null;
    }

    /**
     * À appeler AVANT la construction du conteneur : fixe DATABASE_URL selon var/db_mode.
     * Sans fichier, on ne touche à rien (comportement historique).
     */
    public static function apply(string $projectDir): void {
        if (self::env('APP_DB_MODE_IGNORE') !== null) {
            return; // ex. ./app build : DATABASE_URL est imposée explicitement
        }
        $mode = self::requested($projectDir);
        if ($mode === null) {
            return;
        }

        $prod = self::env('DATABASE_PROD_URL');
        $demo = self::env('DATABASE_DEMO_URL');

        if ($mode === self::PROD) {
            $url = $prod;
        } else {
            $url = self::isUsableDemoUrl($prod, $demo) ? $demo : null;
        }
        if ($url === null || $url === '') {
            return;
        }

        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $url;
        putenv('DATABASE_URL=' . $url);
    }

    /** Mode réellement en vigueur (d'après l'URL effective), indépendamment de la façon dont elle a été fixée. */
    public static function current(): string {
        $demo = self::env('DATABASE_DEMO_URL');
        $url = self::env('DATABASE_URL');

        return ($demo !== null && $demo !== '' && $url === $demo) ? self::DEMO : self::PROD;
    }

    public static function write(string $projectDir, string $mode): void {
        if (!in_array($mode, [self::PROD, self::DEMO], true)) {
            throw new \InvalidArgumentException('Mode inconnu : ' . $mode);
        }
        $dir = dirname(self::file($projectDir));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer ' . $dir);
        }
        $tmp = self::file($projectDir) . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $mode . "\n") === false || !@rename($tmp, self::file($projectDir))) {
            @unlink($tmp);
            throw new \RuntimeException('Impossible d\'écrire ' . self::file($projectDir) . ' (droits sur var/ ?)');
        }
    }

    /**
     * Une reconstruction de la démo est-elle en cours ?
     * Verrou posé par ./app, ou état « running » dans demo-build.status (posé dès le clic) ; ignorés au-delà de 3 h.
     */
    public static function isBuilding(string $projectDir): bool {
        $lock = self::lockDir($projectDir);
        if (is_dir($lock) && (time() - (int) @filemtime($lock)) < 3 * 3600) {
            return true;
        }
        $status = self::lastBuild($projectDir);

        return $status !== null && $status['state'] === 'running'
            && (time() - (int) @filemtime(self::statusFile($projectDir))) < 3 * 3600;
    }

    public static function writeStatus(string $projectDir, string $state): void {
        $dir = dirname(self::statusFile($projectDir));
        @mkdir($dir, 0775, true);
        $tmp = self::statusFile($projectDir) . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $state . '|' . date('Y-m-d H:i') . "\n") !== false) {
            @chmod($tmp, 0664);
            @rename($tmp, self::statusFile($projectDir));
        }
    }

    /** @return array{state: string, date: string}|null  state = ok|failed */
    public static function lastBuild(string $projectDir): ?array {
        $content = @file_get_contents(self::statusFile($projectDir));
        if (!is_string($content) || !str_contains($content, '|')) {
            return null;
        }
        [$state, $date] = array_map('trim', explode('|', $content, 2));

        return ['state' => $state, 'date' => $date];
    }

    public static function databaseName(?string $url): string {
        $path = $url ? (parse_url($url, PHP_URL_PATH) ?: '') : '';

        return ltrim($path, '/');
    }

    public static function isUsableDemoUrl(?string $prodUrl, ?string $demoUrl): bool {
        if ($demoUrl === null || $demoUrl === '' || $demoUrl === $prodUrl) {
            return false;
        }
        $name = strtolower(self::databaseName($demoUrl));

        return $name !== '' && $name !== strtolower(self::databaseName($prodUrl)) && str_contains($name, 'demo');
    }

    private static function env(string $name): ?string {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }
}
