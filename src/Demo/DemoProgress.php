<?php

namespace App\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Suivi de la reconstruction de la démo : un petit fichier JSON (var/demo-build.progress.json)
 * écrit par la commande app:demo:build (avancement, étapes, erreur détaillée) et lu par l'interface
 * (menu + page « Suivi de la démo »). Le journal brut reste var/demo-build.log.
 *
 * Détection des reconstructions « mortes » (processus tué, script qui plante avant la commande…) :
 * reconcile() vérifie que le processus vit encore ; sinon l'état passe à « failed », le verrou est levé
 * et le menu cesse de tourner indéfiniment.
 */
final class DemoProgress {

    private array $state = [];
    private float $lastWrite = 0.0;

    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir) {
    }

    public static function file(string $projectDir): string {
        return $projectDir . '/var/demo-build.progress.json';
    }

    public static function logFile(string $projectDir): string {
        return $projectDir . '/var/demo-build.log';
    }

    // ------------------------------------------------------------------
    // Écriture (côté commande)
    // ------------------------------------------------------------------

    /** Démarre le suivi (conserve le pid du lanceur posé par le contrôleur). */
    public function begin(): void {
        $previous = self::read($this->projectDir) ?? [];
        if (($previous['state'] ?? null) !== 'starting') {
            $previous = []; // lancement en ligne de commande : on repart d'un suivi neuf
        }
        $this->state = [
            'runId' => $previous['runId'] ?? bin2hex(random_bytes(4)),
            'state' => 'running',
            'pid' => $previous['pid'] ?? null,
            'cmdPid' => getmypid() ?: null,
            'startedAt' => $previous['startedAt'] ?? time(),
            'updatedAt' => time(),
            'phase' => 'Démarrage de la commande',
            'percent' => 1,
            'steps' => [],
            'error' => null,
            'message' => null,
        ];
        $this->flush(true);
    }

    public function phase(string $label, ?float $percent = null): void {
        $this->state['phase'] = $label;
        if ($percent !== null) {
            $this->state['percent'] = max(0, min(100, round($percent, 1)));
        }
        $this->flush(true);
    }

    /** Ajoute / met à jour une étape (clé = libellé). status : running | ok | failed */
    public function step(string $label, string $status, string $detail = ''): void {
        foreach ($this->state['steps'] as &$s) {
            if ($s['label'] === $label) {
                $s['status'] = $status;
                $s['detail'] = $detail !== '' ? $detail : $s['detail'];
                $this->flush(true);

                return;
            }
        }
        unset($s);
        $this->state['steps'][] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        $this->flush(true);
    }

    /** Avancement en pourcentage + libellé (écriture limitée à ~2 par seconde). */
    public function tick(string $label, float $percent, ?string $detail = null): void {
        $this->state['phase'] = $label;
        $this->state['percent'] = max(0, min(100, round($percent, 1)));
        if ($detail !== null) {
            $this->state['detail'] = $detail;
        }
        $this->flush(false);
    }

    public function finish(bool $ok, string $message = '', ?Throwable $e = null): void {
        if ($this->state === []) {
            $this->state = self::read($this->projectDir) ?? [];
        }
        $this->state['state'] = $ok ? 'ok' : 'failed';
        $this->state['message'] = $message !== '' ? $message : null;
        if ($ok) {
            $this->state['percent'] = 100;
            $this->state['phase'] = 'Terminé';
        } else {
            $this->state['error'] = $e !== null ? self::describe($e) : ['message' => $message, 'class' => null, 'where' => null, 'trace' => null];
            foreach ($this->state['steps'] as &$s) {
                if ($s['status'] === 'running') {
                    $s['status'] = 'failed';
                }
            }
            unset($s);
        }
        $this->flush(true);
    }

    public function isRunning(): bool {
        return ($this->state['state'] ?? null) === 'running';
    }

    public static function describe(Throwable $e): array {
        $chain = [];
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $chain[] = get_class($x) . ' : ' . $x->getMessage();
        }

        return [
            'message' => $e->getMessage(),
            'class' => get_class($e),
            'where' => basename($e->getFile()) . ':' . $e->getLine(),
            'chain' => $chain,
            'trace' => implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 12)),
        ];
    }

    private function flush(bool $force): void {
        $now = microtime(true);
        if (!$force && ($now - $this->lastWrite) < 0.5) {
            return;
        }
        $this->lastWrite = $now;
        $this->state['updatedAt'] = time();
        self::write($this->projectDir, $this->state);
    }

    // ------------------------------------------------------------------
    // Lecture / surveillance (côté web)
    // ------------------------------------------------------------------

    public static function write(string $projectDir, array $state): void {
        $file = self::file($projectDir);
        @mkdir(dirname($file), 0775, true);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @chmod($tmp, 0664);
            @rename($tmp, $file);
        }
    }

    public static function read(string $projectDir): ?array {
        $json = @file_get_contents(self::file($projectDir));
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : null;
    }

    /** Lancement depuis le web : état « starting » + pid du lanceur. */
    public static function starting(string $projectDir, ?int $pid): void {
        self::write($projectDir, [
            'runId' => bin2hex(random_bytes(4)),
            'state' => 'starting',
            'pid' => $pid,
            'cmdPid' => null,
            'startedAt' => time(),
            'updatedAt' => time(),
            'phase' => 'Lancement (sauvegarde de la production, puis démarrage de la commande)',
            'percent' => 0,
            'steps' => [],
            'error' => null,
            'message' => null,
        ]);
    }

    public static function isAlive(?int $pid): bool {
        if (!$pid || $pid < 2) {
            return false;
        }
        if (is_dir('/proc')) {
            return is_dir('/proc/' . $pid) && !self::isZombie($pid);
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }

    private static function isZombie(int $pid): bool {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');

        return is_string($stat) && preg_match('/^\d+ \(.*\) Z /s', $stat) === 1;
    }

    /** Une commande app:demo:build tourne-t-elle sur la machine ? null = impossible à savoir (exec indisponible). */
    public static function anyBuildProcess(): ?bool {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        $out = [];
        $code = 1;
        @exec("pgrep -f '[a]pp:demo:build|[a]pp build' 2>/dev/null", $out, $code);

        return $code === 0 && $out !== [];
    }

    /**
     * Remet les fichiers d'état en cohérence avec la réalité :
     *  - suivi « starting/running » mais plus aucun processus vivant → échec constaté, verrou levé ;
     *  - suivi terminé mais statut du menu resté « running » → statut aligné.
     */
    public static function reconcile(string $projectDir): ?array {
        $p = self::read($projectDir);
        if ($p === null) {
            return null;
        }
        $active = in_array($p['state'] ?? '', ['starting', 'running'], true);

        if ($active) {
            $alive = self::isAlive($p['pid'] ?? null) || self::isAlive($p['cmdPid'] ?? null);
            if (!$alive && ($p['pid'] ?? null) === null && ($p['cmdPid'] ?? null) === null) {
                $any = self::anyBuildProcess();
                $alive = $any !== false; // inconnu => on ne conclut pas
            } elseif (!$alive) {
                $any = self::anyBuildProcess();
                $alive = $any === true;
            }
            if (!$alive && (time() - (int) ($p['updatedAt'] ?? 0)) > 8) {
                $p['state'] = 'failed';
                $p['message'] = 'Le processus de reconstruction s\'est arrêté sans la terminer (tué, mémoire épuisée, ou script en erreur avant la commande). Consultez le journal ci-dessous.';
                $p['error'] = $p['error'] ?? ['message' => $p['message'], 'class' => null, 'where' => null, 'trace' => null];
                foreach ($p['steps'] ?? [] as &$s) {
                    if (($s['status'] ?? '') === 'running') {
                        $s['status'] = 'failed';
                    }
                }
                unset($s);
                $p['updatedAt'] = time();
                self::write($projectDir, $p);
                self::clearLock($projectDir);
                DbMode::writeStatus($projectDir, 'failed');
            }
        } elseif (in_array($p['state'] ?? '', ['ok', 'failed'], true)) {
            $status = DbMode::lastBuild($projectDir);
            if ($status !== null && $status['state'] === 'running' && (time() - (int) ($p['updatedAt'] ?? 0)) > 30 && self::anyBuildProcess() !== true) {
                DbMode::writeStatus($projectDir, $p['state']);
                self::clearLock($projectDir);
            }
        }

        return $p;
    }

    /** Déblocage manuel : retourne false si un processus vit encore. */
    public static function unlock(string $projectDir): bool {
        $p = self::read($projectDir);
        if ($p !== null && (self::isAlive($p['pid'] ?? null) || self::isAlive($p['cmdPid'] ?? null))) {
            return false;
        }
        if (self::anyBuildProcess() === true) {
            return false;
        }
        self::clearLock($projectDir);
        DbMode::writeStatus($projectDir, 'failed');
        if ($p !== null && in_array($p['state'] ?? '', ['starting', 'running'], true)) {
            $p['state'] = 'failed';
            $p['message'] = 'Débloqué manuellement : aucun processus de reconstruction n\'était actif.';
            $p['updatedAt'] = time();
            self::write($projectDir, $p);
        }

        return true;
    }

    private static function clearLock(string $projectDir): void {
        $lock = DbMode::lockDir($projectDir);
        if (is_dir($lock)) {
            foreach (glob($lock . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($lock);
        }
    }

    /** @return array{offset:int, size:int, text:string} fin du journal (ou suite depuis $offset). */
    public static function tail(string $projectDir, ?int $offset = null, int $maxBytes = 60000): array {
        $file = self::logFile($projectDir);
        $size = is_file($file) ? (int) filesize($file) : 0;
        if ($size === 0) {
            return ['offset' => 0, 'size' => 0, 'text' => ''];
        }
        $from = ($offset === null || $offset > $size || $offset < 0) ? max(0, $size - $maxBytes) : max($offset, $size - $maxBytes);
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return ['offset' => $size, 'size' => $size, 'text' => '(journal illisible : droits sur ' . basename($file) . ' ?)'];
        }
        fseek($fh, $from);
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        // retire les codes couleur ANSI de la console Symfony
        $text = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;

        return ['offset' => $size, 'size' => $size, 'text' => $text];
    }
}
