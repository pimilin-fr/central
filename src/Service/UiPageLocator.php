<?php

namespace App\Service;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Retrouve, pour un fichier (template ou JS), les pages de l'application où il s'affiche.
 *
 * Lecture seule : les contrôleurs donnent « route → template rendu », les `include` / `embed` / `extends` remontent
 * des composants partiels jusqu'aux pages, et un enregistrement existant (le premier) sert d'exemple pour les URL à
 * paramètre ({id}). Utilisé par l'inventaire des commandes du styleguide (temporaire).
 */
final class UiPageLocator
{
    private const MAX_PAGES = 3;

    /** @var array<string, list<array{name: string, params: list<string>, entities: array<string,string>, get: bool, ctrl?: string, depth?: int}>>|null */
    private ?array $templateRoutes = null;
    /** @var array<string, list<string>>|null fichier => fichiers qui l'utilisent */
    private ?array $usedBy = null;
    /** @var array<string, string|null> */
    private array $sampleIds = [];
    /** @var array<string, list<string>> « Classe::méthode » => templates rendus par une méthode sans route (fragments) */
    private array $fragments = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly UrlGeneratorInterface $urls,
        private readonly ManagerRegistry $registry,
    ) {
    }

    /**
     * @param list<string> $files chemins relatifs (templates/…, public/js/…)
     *
     * @return array<string, list<array{name: string, url: string}>>
     */
    public function pagesFor(array $files): array
    {
        $this->load();
        $out = [];
        foreach ($files as $file) {
            $out[$file] = $this->resolve($file);
        }

        return $out;
    }

    /** @return list<array{name: string, url: string}> */
    private function resolve(string $file): array
    {
        $routes = [];
        $global = str_starts_with($file, 'templates/layout/');
        if (!$global) {
            $seen = [$file => true];
            $queue = [$file];
            for ($depth = 0; $depth < 7 && $queue !== [] && !$global; ++$depth) {
                $next = [];
                foreach ($queue as $f) {
                    foreach ($this->templateRoutes[$f] ?? [] as $r) {
                        $routes[$r['name']] ??= $r + ['depth' => $depth];
                    }
                    foreach ($this->usedBy[$f] ?? [] as $parent) {
                        if (str_starts_with($parent, 'templates/layout/') && str_starts_with($file, 'public/')) {
                            $global = true; // script chargé par la structure commune
                        }
                        if (!isset($seen[$parent]) && !str_starts_with($parent, 'templates/layout/')) {
                            $seen[$parent] = true;
                            $next[] = $parent;
                        }
                    }
                }
                $queue = $next;
            }
            // Pages « propres » d'abord : GET, même domaine que le fichier (tiers/…), puis les plus proches.
            $parts = explode('/', preg_replace('/\.(html\.)?(twig|js)$/', '', $file) ?? $file);
            $domain = $parts[1] ?? '';
            $words = array_values(array_filter(array_map(static fn ($w) => trim($w, '_'), array_slice($parts, 2)), static fn ($w) => strlen($w) > 2));
            $score = static function (array $r) use ($domain, $words): array {
                $hits = count(array_filter($words, static fn ($w) => str_contains($r['name'], $w)));

                return [!$r['get'], !str_contains($r['name'], $domain), -$hits, $r['depth'] ?? 0];
            };
            uasort($routes, static fn ($a, $b) => $score($a) <=> $score($b));
        }
        if ($global) {
            $hint = str_starts_with($file, 'public/js/') ? basename($file, '.js') : '';
            $all = [];
            foreach ($this->templateRoutes as $list) {
                foreach ($list as $r) {
                    if ($r['get']) {
                        $all[$r['name']] = $r;
                    }
                }
            }
            $own = $hint === '' ? [] : array_filter($all, static fn ($r) => str_contains($r['name'], $hint));
            if ($own !== []) {
                $all = $own;
            } else {
                $all = array_filter($all, static fn ($r) => $r['params'] === [] && str_ends_with($r['name'], '_index'));
            }
            uasort($all, static fn ($a, $b) => [count($a['params']), $a['name']] <=> [count($b['params']), $b['name']]);
            $routes = array_slice($all, 0, $own !== [] ? 2 : 1, true);
        }

        $pages = [];
        foreach ($routes as $r) {
            if (!$r['get']) {
                continue; // une page ouverte depuis un lien est toujours en GET
            }
            $url = $this->url($r);
            if ($url !== null) {
                $pages[] = ['name' => $r['name'], 'url' => $url];
            }
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
        }

        return $pages;
    }

    /** @param array{name: string, params: list<string>, entities: array<string,string>, get: bool, ctrl?: string, depth?: int} $route */
    private function url(array $route): ?string
    {
        $params = [];
        foreach ($route['params'] as $var) {
            $class = $route['entities'][$var] ?? null;
            $id = $class === null ? null : $this->sampleId($class);
            if ($id === null) {
                return null;
            }
            $params[$var] = $id;
        }
        try {
            return $this->urls->generate($route['name'], $params);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sampleId(string $class): ?string
    {
        if (!array_key_exists($class, $this->sampleIds)) {
            $id = null;
            try {
                $em = $this->registry->getManagerForClass($class);
                $row = $em?->getRepository($class)->findOneBy([], ['id' => 'ASC']);
                $id = $row !== null && method_exists($row, 'getId') ? (string) $row->getId() : null;
            } catch (\Throwable) {
            }
            $this->sampleIds[$class] = $id;
        }

        return $this->sampleIds[$class];
    }

    // ------------------------------------------------------------------ lecture des sources

    private function load(): void
    {
        if ($this->templateRoutes !== null) {
            return;
        }
        $this->templateRoutes = [];
        $this->usedBy = [];

        foreach ($this->files('src/Controller', 'php') as $rel) {
            $this->readController((string) file_get_contents($this->projectDir . '/' . $rel), basename($rel, '.php'));
        }

        $templates = $this->files('templates', 'twig');
        $known = array_flip($templates);
        foreach ($templates as $rel) {
            $src = (string) file_get_contents($this->projectDir . '/' . $rel);
            if (preg_match_all('/[\'"]([\w\/.@-]+\.twig)[\'"]/', $src, $m)) {
                foreach (array_unique($m[1]) as $ref) {
                    if (isset($known['templates/' . $ref]) && 'templates/' . $ref !== $rel) {
                        $this->usedBy['templates/' . $ref][] = $rel;
                    }
                }
            }
            if (preg_match_all('/(\w+)Controller::(\w+)/', $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $call) {
                    foreach ($this->fragments[$call[1] . 'Controller::' . $call[2]] ?? [] as $tpl) {
                        $this->usedBy[$tpl][] = $rel;
                    }
                }
            }
            if (preg_match_all('#js/([\w./-]+\.js)#', $src, $m)) {
                foreach (array_unique($m[1]) as $js) {
                    $this->usedBy['public/js/' . $js][] = $rel;
                }
            }
        }
    }

    private function readController(string $src, string $class): void
    {
        $prefix = '';
        if (preg_match('/#\[Route\(\s*[\'"]([^\'"]*)[\'"][^\]]*\]\s*(?:#\[[^\n]*\]\s*)*(?:final\s+|abstract\s+)?class\b/s', $src, $c)) {
            $prefix = $c[1];
        }

        // Corps de toutes les méthodes (routes ou non), pour suivre les méthodes d'aide ($this->handleForm(…)).
        $bodies = [];
        if (preg_match_all('/function\s+(\w+)\(([^)]*)\)[^{;]*\{/s', $src, $f, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($f as $i => $fn) {
                $start = $fn[0][1] + strlen($fn[0][0]);
                $end = $f[$i + 1][0][1] ?? strlen($src);
                $bodies[$fn[1][0]] = substr($src, $start, $end - $start);
                foreach ($this->rendered($bodies[$fn[1][0]]) as $tpl) {
                    $this->fragments[$class . '::' . $fn[1][0]][] = 'templates/' . $tpl;
                }
            }
        }

        $re = '/#\[Route\(((?:(?!#\[).)*?)\)\]\s*\n(?:\s*#\[[^\n]*\]\s*\n)*\s*public function (\w+)\(([^)]*)\)/s';
        if (!preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
            return;
        }
        foreach ($m as $match) {
            $args = $match[1];
            $signature = $match[3];

            if (!preg_match('/name:\s*[\'"]([^\'"]+)[\'"]/', $args, $n)) {
                continue;
            }
            $path = '';
            if (preg_match('/^\s*[\'"]([^\'"]*)[\'"]/', $args, $p) || preg_match('/path:\s*[\'"]([^\'"]*)[\'"]/', $args, $p)) {
                $path = $p[1];
            }
            $methods = preg_match('/methods:\s*\[([^\]]*)\]/', $args, $mm) ? $mm[1] : '';
            $get = $methods === '' || stripos($methods, 'GET') !== false;

            $body = $bodies[$match[2]] ?? '';
            $todo = [$body];
            $seen = [$match[2] => true];
            for ($depth = 0; $depth < 3; ++$depth) {
                foreach ($todo as $text) {
                    if (preg_match_all('/\$this->(\w+)\(/', $text, $calls)) {
                        foreach (array_unique($calls[1]) as $callee) {
                            if (isset($bodies[$callee]) && !isset($seen[$callee])) {
                                $seen[$callee] = true;
                                $body .= "\n" . $bodies[$callee];
                                $todo[] = $bodies[$callee];
                            }
                        }
                    }
                }
            }

            $templates = $this->rendered($body);
            if ($templates === []) {
                continue;
            }
            preg_match_all('/\{(\w+)(?:[^}]*)\}/', $prefix . $path, $v);
            $vars = $v[1];
            $entities = $this->entities($signature, $vars);

            foreach ($templates as $tpl) {
                $this->templateRoutes['templates/' . $tpl][] = ['name' => $n[1], 'params' => $vars, 'entities' => $entities, 'get' => $get, 'ctrl' => $match[2]];
            }
        }
    }

    /** Templates cités dans les appels render*() d'un corps de méthode (y compris « cond ? 'a.twig' : 'b.twig' »). @return list<string> */
    private function rendered(string $body): array
    {
        $out = [];
        if (preg_match_all('/render\w*\(([^\[;]*)/s', $body, $m)) {
            foreach ($m[1] as $args) {
                if (preg_match_all('/[\'"]([^\'"]+\.twig)[\'"]/', $args, $t)) {
                    array_push($out, ...$t[1]);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Associe chaque variable de route à une entité (App\Entity\X) d'après la signature de la méthode.
     *
     * @param list<string> $vars
     *
     * @return array<string,string>
     */
    private function entities(string $signature, array $vars): array
    {
        $typed = [];
        foreach (explode(',', $signature) as $part) {
            if (preg_match('/\??\\\\?([A-Z]\w*)\s+\$(\w+)/', $part, $t) && is_file($this->projectDir . '/src/Entity/' . $t[1] . '.php')) {
                $typed[$t[2]] = 'App\\Entity\\' . $t[1];
            }
        }
        $out = [];
        foreach ($vars as $var) {
            if (isset($typed[$var])) {
                $out[$var] = $typed[$var];
            } elseif ($var === 'id' && $typed !== []) {
                $out[$var] = reset($typed);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function files(string $dir, string $ext): array
    {
        $found = [];
        $base = $this->projectDir . '/' . $dir;
        if (!is_dir($base)) {
            return [];
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === $ext) {
                $found[] = $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            }
        }
        sort($found);

        return $found;
    }
}
