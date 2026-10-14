<?php

namespace App\Service\Job;

/**
 * Exécute une tâche du registre en mesurant : code retour, durée, sortie, fichier produit (nom + taille).
 * Sans dépendance Symfony (voir JobStore).
 */
final class JobRunner {

    private const LOG_MAX_BYTES = 200000;

    public function __construct(private JobStore $store) {
    }

    /**
     * @return array|null état final, ou null si la tâche est déjà en cours / inconnue
     */
    public function run(string $id, string $trigger = 'cron'): ?array {
        $job = $this->store->job($id);
        if ($job === null) {
            return null;
        }
        $this->store->ensureDirs();

        $lock = fopen($this->store->dir('locks') . '/' . $id . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null; // déjà en cours
        }
        @chmod($this->store->dir('locks') . '/' . $id . '.lock', 0664);

        $started = time();
        $t0 = microtime(true);
        $logFile = $this->store->dir('logs') . '/' . $id . '.log';
        $base = ['id' => $id, 'trigger' => $trigger, 'startedAt' => $started, 'pid' => getmypid(), 'host' => gethostname()];
        $this->store->writeState($id, $base + ['status' => JobStore::RUNNING]);

        $command = (string) ($job['command'] ?? '');
        $exit = 127;
        $output = '';
        if ($command === '') {
            $output = 'Aucune commande définie dans config/jobs.json.';
        } else {
            $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open(['/bin/bash', '-c', $command], $spec, $pipes);
            if (!is_resource($proc)) {
                $output = 'Impossible de lancer la commande.';
            } else {
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $open = [$pipes[1], $pipes[2]];
                while ($open) {
                    $read = $open;
                    $w = $e = null;
                    if (stream_select($read, $w, $e, 1) === false) {
                        break;
                    }
                    foreach ($read as $r) {
                        $chunk = fread($r, 8192);
                        if ($chunk === '' || $chunk === false) {
                            if (feof($r)) {
                                $open = array_values(array_filter($open, static fn ($x): bool => $x !== $r));
                            }
                            continue;
                        }
                        if (strlen($output) < self::LOG_MAX_BYTES) {
                            $output .= $chunk;
                        }
                    }
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($proc);
            }
        }
        $ended = time();
        $duration = round(microtime(true) - $t0, 1);

        // --- fichier produit
        $artifact = null;
        $problem = null;
        if (isset($job['artifact']['dir'])) {
            $artifact = $this->newestArtifact($job['artifact']['dir'], (string) ($job['artifact']['glob'] ?? '*'), $started - 120);
            $min = (int) ($job['artifact']['minBytes'] ?? 1);
            if ($artifact === null) {
                $problem = 'Aucun fichier de sauvegarde récent trouvé dans ' . $job['artifact']['dir'] . '.';
            } elseif ($artifact['size'] < $min) {
                $problem = sprintf('Fichier produit trop petit (%d octets, minimum attendu %d) : %s.', $artifact['size'], $min, $artifact['name']);
            }
        }

        $status = JobStore::OK;
        $message = 'Terminée sans erreur.';
        if ($exit !== 0) {
            $status = JobStore::FAILED;
            $message = 'Code retour ' . $exit . ' — ' . $this->lastLine($output);
        } elseif ($problem !== null) {
            $status = JobStore::FAILED;
            $message = $problem;
        }

        $final = $base + [
            'status' => $status, 'message' => $message, 'exit' => $exit,
            'endedAt' => $ended, 'duration' => $duration, 'artifact' => $artifact,
        ];
        unset($final['pid']);

        @file_put_contents($logFile, '# ' . date('c', $started) . ' — ' . $id . ' (' . $trigger . ")\n" . $output, LOCK_EX);
        @chmod($logFile, 0664);
        $final['tail'] = $this->tail($output, 25);
        $this->store->writeState($id, $final);
        $this->store->appendHistory($id, array_diff_key($final, ['tail' => 1]) + ['tailShort' => $this->tail($output, 6)]);

        flock($lock, LOCK_UN);
        fclose($lock);

        return $final;
    }

    /** @return array{name:string,size:int,mtime:int}|null */
    public function newestArtifact(string $dir, string $glob, int $since): ?array {
        $best = null;
        foreach (glob(rtrim($dir, '/') . '/' . $glob) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $m = (int) filemtime($file);
            if ($m >= $since && ($best === null || $m > $best['mtime'])) {
                $best = ['name' => basename($file), 'size' => (int) filesize($file), 'mtime' => $m];
            }
        }

        return $best;
    }

    private function tail(string $text, int $lines): string {
        $all = preg_split('/\R/', rtrim($text)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }

    private function lastLine(string $text): string {
        $t = trim($this->tail($text, 1));

        return $t === '' ? 'aucune sortie' : mb_substr($t, 0, 300);
    }
}
