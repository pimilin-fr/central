<?php

namespace App\Service\Job;

/**
 * Suivi des tâches planifiées (sauvegardes…) : registre, état de la dernière exécution, historique, demandes de relance.
 *
 * Classe volontairement SANS dépendance Symfony : elle est utilisée aussi par bin/jobs (lancé par cron, sans le noyau).
 *
 * Disque (dans <projet>/var/jobs) :
 *   state/<id>.json        dernière exécution (écrite par le lanceur)
 *   history/<id>.jsonl     une ligne JSON par exécution (200 dernières conservées)
 *   logs/<id>.log          sortie de la dernière exécution
 *   requests/<id>.req      demande de relance posée par le web, consommée par « bin/jobs dispatch »
 *   locks/<id>.lock        verrou anti double exécution
 */
final class JobStore {

    public const OK = 'ok';
    public const RUNNING = 'running';
    public const FAILED = 'failed';
    public const LATE = 'late';
    public const WARNING = 'warning';
    public const NEVER = 'never';

    /** Gravité pour l'indicateur global (le plus grave l'emporte). */
    public const SEVERITY = [self::OK => 0, self::RUNNING => 0, self::NEVER => 1, self::WARNING => 2, self::LATE => 3, self::FAILED => 4];

    public const HISTORY_MAX = 200;
    private const STALE_REQUEST_SECONDS = 900;

    private string $dir;
    private ?array $config = null;

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')] private string $projectDir,
        ?string $configFile = null,
        ?string $varDir = null
    ) {
        $this->dir = ($varDir ?? $projectDir . '/var') . '/jobs';
        $this->configFile = $configFile ?? $projectDir . '/config/jobs.json';
    }

    private string $configFile;

    public function dir(string $sub = ''): string {
        return $sub === '' ? $this->dir : $this->dir . '/' . $sub;
    }

    /** Crée l'arborescence (setgid + 2775 pour que le serveur web, groupe www-data, puisse poser des demandes). */
    public function ensureDirs(): void {
        foreach (['', 'state', 'history', 'logs', 'requests', 'locks'] as $sub) {
            $path = $this->dir($sub);
            if (!is_dir($path)) {
                @mkdir($path, 02775, true);
                @chmod($path, 02775);
            }
        }
    }

    public function config(): array {
        if ($this->config === null) {
            $raw = is_file($this->configFile) ? json_decode((string) file_get_contents($this->configFile), true) : null;
            $this->config = is_array($raw) ? $raw : ['jobs' => []];
        }

        return $this->config;
    }

    /** @return array<string, array> tâches indexées par identifiant */
    public function jobs(): array {
        $out = [];
        foreach ($this->config()['jobs'] ?? [] as $job) {
            if (is_array($job) && isset($job['id']) && preg_match('/^[a-z0-9_-]+$/', (string) $job['id'])) {
                $out[$job['id']] = $job;
            }
        }

        return $out;
    }

    public function job(string $id): ?array {
        return $this->jobs()[$id] ?? null;
    }

    // ---------------------------------------------------------------- état

    public function state(string $id): ?array {
        return $this->readJson($this->dir('state') . '/' . $id . '.json');
    }

    public function writeState(string $id, array $state): void {
        $this->ensureDirs();
        $this->writeJson($this->dir('state') . '/' . $id . '.json', $state);
    }

    /** @return list<array> du plus récent au plus ancien */
    public function history(string $id, int $limit = 60): array {
        $file = $this->dir('history') . '/' . $id . '.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return array_slice(array_reverse($rows), 0, $limit);
    }

    public function appendHistory(string $id, array $entry): void {
        $this->ensureDirs();
        $file = $this->dir('history') . '/' . $id . '.jsonl';
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines = array_slice($lines, -self::HISTORY_MAX);
        @file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX);
        @chmod($file, 0664);
    }

    public function logTail(string $id, int $lines = 60): string {
        $file = $this->dir('logs') . '/' . $id . '.log';
        if (!is_file($file)) {
            return '';
        }
        $all = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }

    // ------------------------------------------------------------ demandes

    public function requestRun(string $id): bool {
        if ($this->job($id) === null) {
            return false;
        }
        $this->ensureDirs();
        $file = $this->dir('requests') . '/' . $id . '.req';

        return @file_put_contents($file, date('c')) !== false && @chmod($file, 0664);
    }

    public function hasRequest(string $id): bool {
        $file = $this->dir('requests') . '/' . $id . '.req';

        return is_file($file) && (time() - (int) @filemtime($file)) < self::STALE_REQUEST_SECONDS;
    }

    /** @return list<string> identifiants demandés (fichiers consommés) */
    public function takeRequests(): array {
        $ids = [];
        foreach (glob($this->dir('requests') . '/*.req') ?: [] as $file) {
            $id = basename($file, '.req');
            $fresh = (time() - (int) @filemtime($file)) < self::STALE_REQUEST_SECONDS;
            @unlink($file);
            if ($fresh && $this->job($id) !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // --------------------------------------------------------- évaluation

    public static function pidAlive(?int $pid): bool {
        if (!$pid || $pid < 2) {
            return false;
        }
        if (is_dir('/proc')) {
            return is_dir('/proc/' . $pid);
        }

        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    /**
     * Évalue une tâche : statut + détails prêts à afficher.
     *
     * @return array{id:string,job:array,status:string,severity:int,reason:string,state:?array,lastOk:?array,sizeDeltaPct:?float,pending:bool}
     */
    public function evaluate(string $id, ?int $now = null): ?array {
        $job = $this->job($id);
        if ($job === null) {
            return null;
        }
        $now ??= time();
        $state = $this->state($id);
        $pending = $this->hasRequest($id);
        $status = self::NEVER;
        $reason = 'Jamais exécutée depuis la mise en place du suivi.';
        $delta = null;

        $lastOk = null;
        foreach ($this->history($id, self::HISTORY_MAX) as $row) {
            if (($row['status'] ?? '') === self::OK || ($row['status'] ?? '') === self::WARNING) {
                $lastOk = $row;
                break;
            }
        }

        if ($state !== null) {
            $st = $state['status'] ?? self::FAILED;
            if ($st === self::RUNNING) {
                if (self::pidAlive($state['pid'] ?? null)) {
                    $status = self::RUNNING;
                    $reason = 'En cours depuis ' . $this->since((int) ($state['startedAt'] ?? $now), $now) . '.';
                } else {
                    $status = self::FAILED;
                    $reason = 'Exécution interrompue (processus disparu, redémarrage du Pi ?).';
                }
            } elseif ($st === self::FAILED) {
                $status = self::FAILED;
                $reason = (string) ($state['message'] ?? 'Échec.');
            } else {
                $status = self::OK;
                $reason = 'Dernière exécution réussie.';
                $delta = $this->sizeDelta($id, $state, $job);
                $drop = (float) ($job['sizeDropWarnPct'] ?? 0);
                if ($drop > 0 && $delta !== null && $delta <= -$drop) {
                    $status = self::WARNING;
                    $reason = sprintf('Taille du fichier en baisse de %d %% par rapport à la sauvegarde précédente.', (int) round(-$delta));
                }
                $ref = (int) ($state['endedAt'] ?? 0);
                $max = ((float) ($job['everyHours'] ?? 0) + (float) ($job['graceHours'] ?? 0)) * 3600;
                if ($max > 0 && $ref > 0 && ($now - $ref) > $max) {
                    $status = self::LATE;
                    $reason = 'En retard : dernière réussite il y a ' . $this->since($ref, $now) . ' (attendu toutes les ' . (int) $job['everyHours'] . ' h).';
                }
            }
            // Un échec ancien dépassant le délai reste un échec ; une réussite hors délai devient « en retard ».
        } elseif ($pending) {
            $reason = 'Relance demandée, en attente du planificateur.';
        }

        return [
            'id' => $id, 'job' => $job, 'status' => $status, 'severity' => self::SEVERITY[$status],
            'reason' => $reason, 'state' => $state, 'lastOk' => $lastOk, 'sizeDeltaPct' => $delta, 'pending' => $pending,
        ];
    }

    /** @return list<array> */
    public function evaluateAll(?int $now = null): array {
        $out = [];
        foreach (array_keys($this->jobs()) as $id) {
            $out[] = $this->evaluate($id, $now);
        }

        return $out;
    }

    /** État du disque de sauvegarde : monté ? place libre ? */
    public function disk(): ?array {
        $path = (string) ($this->config()['storage'] ?? '');
        if ($path === '') {
            return null;
        }
        $exists = is_dir($path);
        $mounted = false;
        if ($exists) {
            $a = @stat($path);
            $b = @stat(dirname($path));
            $mounted = $a && $b && $a['dev'] !== $b['dev'];
        }
        $total = $exists ? (float) @disk_total_space($path) : 0.0;
        $free = $exists ? (float) @disk_free_space($path) : 0.0;
        $freePct = $total > 0 ? $free / $total * 100 : 0.0;
        $warn = (float) ($this->config()['diskWarnFreePct'] ?? 10);

        $status = self::OK;
        $reason = 'Disque monté, espace suffisant.';
        if (!$exists || !$mounted) {
            $status = self::FAILED;
            $reason = 'Disque de sauvegarde non monté : les sauvegardes risquent d\'être écrites sur la carte SD.';
        } elseif ($freePct < $warn) {
            $status = self::WARNING;
            $reason = sprintf('Espace libre faible (%.0f %%).', $freePct);
        }

        return ['path' => $path, 'mounted' => $mounted, 'total' => $total, 'free' => $free, 'freePct' => $freePct, 'status' => $status, 'severity' => self::SEVERITY[$status], 'reason' => $reason];
    }

    /**
     * Synthèse unique pour l'indicateur global : le pire statut de toutes les tâches + du disque.
     *
     * @return array{status:string,level:string,count:int,total:int,label:string,worst:?string}
     */
    public function summary(?int $now = null): array {
        $items = $this->evaluateAll($now);
        $disk = $this->disk();
        $worst = self::OK;
        $worstId = null;
        $bad = 0;
        foreach ($items as $it) {
            if ($it['severity'] >= 2) {
                ++$bad;
            }
            if ($it['severity'] > self::SEVERITY[$worst]) {
                $worst = $it['status'];
                $worstId = $it['id'];
            }
        }
        if ($disk !== null) {
            if ($disk['severity'] >= 2) {
                ++$bad;
            }
            if ($disk['severity'] > self::SEVERITY[$worst]) {
                $worst = $disk['status'];
                $worstId = 'disque';
            }
        }
        $running = array_filter($items, static fn (array $i): bool => $i['status'] === self::RUNNING);
        $level = match (true) {
            self::SEVERITY[$worst] >= 3 => 'danger',
            self::SEVERITY[$worst] === 2 => 'warning',
            $items === [] => 'none',
            default => 'ok',
        };
        $label = match ($level) {
            'danger' => $bad . ' sauvegarde' . ($bad > 1 ? 's' : '') . ' en défaut',
            'warning' => $bad . ' point' . ($bad > 1 ? 's' : '') . ' à surveiller',
            'none' => 'Aucune tâche suivie',
            default => $running ? 'Sauvegarde en cours' : 'Sauvegardes OK',
        };

        return ['status' => $worst, 'level' => $level, 'count' => $bad, 'total' => count($items), 'label' => $label, 'worst' => $worstId, 'running' => count($running)];
    }

    // -------------------------------------------------------------- utils

    private function sizeDelta(string $id, array $state, array $job): ?float {
        $size = $state['artifact']['size'] ?? null;
        if (!is_int($size) && !is_float($size)) {
            return null;
        }
        $seen = false;
        foreach ($this->history($id, 30) as $row) {
            if (!$seen) { // première ligne = l'exécution courante elle-même
                $seen = true;
                if (($row['startedAt'] ?? null) === ($state['startedAt'] ?? 0)) {
                    continue;
                }
            }
            $prev = $row['artifact']['size'] ?? null;
            if (($row['status'] ?? '') !== self::FAILED && is_numeric($prev) && $prev > 0) {
                return ($size - $prev) / $prev * 100;
            }
        }

        return null;
    }

    private function since(int $from, int $now): string {
        $s = max(0, $now - $from);
        if ($s < 90) {
            return $s . ' s';
        }
        if ($s < 5400) {
            return (int) round($s / 60) . ' min';
        }
        if ($s < 172800) {
            return (int) round($s / 3600) . ' h';
        }

        return (int) round($s / 86400) . ' j';
    }

    private function readJson(string $file): ?array {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function writeJson(string $file, array $data): void {
        $tmp = $file . '.tmp' . getmypid();
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($tmp, 0664);
        rename($tmp, $file);
    }
}
