<?php

namespace App\Twig;

use App\Service\Job\JobStore;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Fonctions Twig du suivi des sauvegardes : indicateur global (barre du haut) et mise en forme. */
final class JobsExtension extends AbstractExtension {

    private ?array $summary = null;

    private const LABELS = [
        JobStore::OK => 'OK', JobStore::RUNNING => 'En cours', JobStore::FAILED => 'Échec',
        JobStore::LATE => 'En retard', JobStore::WARNING => 'À surveiller', JobStore::NEVER => 'Jamais exécutée',
    ];

    public function __construct(private JobStore $store) {
    }

    public function getFunctions(): array {
        return [new TwigFunction('app_jobs_summary', $this->summary(...))];
    }

    public function getFilters(): array {
        return [
            new TwigFilter('job_size', $this->size(...)),
            new TwigFilter('job_dt', $this->dt(...)),
            new TwigFilter('job_ago', $this->ago(...)),
            new TwigFilter('job_status', static fn (?string $s): string => self::LABELS[$s ?? ''] ?? (string) $s),
            new TwigFilter('job_duration', $this->duration(...)),
            new TwigFilter('job_spark', $this->spark(...)),
        ];
    }

    /** Synthèse (mise en cache pour la requête) ; ne doit jamais faire échouer une page. */
    public function summary(): array {
        if ($this->summary === null) {
            try {
                $this->summary = $this->store->summary();
            } catch (\Throwable) {
                $this->summary = ['status' => 'ok', 'level' => 'none', 'count' => 0, 'total' => 0, 'label' => 'Suivi indisponible', 'worst' => null, 'running' => 0];
            }
        }

        return $this->summary;
    }

    public function size(int|float|null $bytes): string {
        if ($bytes === null) {
            return '—';
        }
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < 4) {
            $v /= 1024;
            ++$i;
        }

        return ($i === 0 ? (string) (int) $v : number_format($v, $v < 10 ? 2 : 1, ',', ' ')) . ' ' . $units[$i];
    }

    public function dt(int|float|null $ts): string {
        return $ts ? date('d/m/Y H:i', (int) $ts) : '—';
    }

    public function ago(int|float|null $ts): string {
        if (!$ts) {
            return '—';
        }
        $s = max(0, time() - (int) $ts);
        if ($s < 90) {
            return 'à l\'instant';
        }
        if ($s < 5400) {
            return 'il y a ' . (int) round($s / 60) . ' min';
        }
        if ($s < 172800) {
            return 'il y a ' . (int) round($s / 3600) . ' h';
        }

        return 'il y a ' . (int) round($s / 86400) . ' j';
    }

    public function duration(int|float|null $s): string {
        if ($s === null) {
            return '—';
        }
        if ($s < 60) {
            return number_format((float) $s, $s < 10 ? 1 : 0, ',', '') . ' s';
        }

        return floor($s / 60) . ' min ' . str_pad((string) ((int) $s % 60), 2, '0', STR_PAD_LEFT) . ' s';
    }

    /**
     * Courbe de taille (SVG) à partir de l'historique (du plus récent au plus ancien).
     *
     * @return array{points:string,area:string,min:float,max:float,n:int,w:int,h:int}|null
     */
    public function spark(array $history, int $w = 640, int $h = 120): ?array {
        $sizes = [];
        foreach (array_reverse($history) as $row) {
            if (($row['status'] ?? '') !== JobStore::FAILED && isset($row['artifact']['size']) && is_numeric($row['artifact']['size'])) {
                $sizes[] = (float) $row['artifact']['size'];
            }
        }
        $n = count($sizes);
        if ($n < 2) {
            return null;
        }
        $min = min($sizes);
        $max = max($sizes);
        $span = $max - $min ?: 1.0;
        $pad = 8;
        $pts = [];
        foreach ($sizes as $i => $v) {
            $x = $pad + ($w - 2 * $pad) * $i / ($n - 1);
            $y = $pad + ($h - 2 * $pad) * (1 - ($v - $min) / $span);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $area = implode(' ', $pts) . ' ' . ($w - $pad) . ',' . ($h - $pad) . ' ' . $pad . ',' . ($h - $pad);

        return ['points' => implode(' ', $pts), 'area' => $area, 'min' => $min, 'max' => $max, 'n' => $n, 'w' => $w, 'h' => $h];
    }
}
