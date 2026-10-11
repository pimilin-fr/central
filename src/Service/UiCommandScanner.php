<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Inventaire automatique des commandes de l'interface : boutons, liens, onglets, dépliants, menus.
 *
 * Lit les sources (templates Twig + JS), jamais la base. Chaque commande est retournée avec son fichier et sa ligne,
 * puis rangée dans une FAMILLE (retour, ajout, enregistrer…) : à l'intérieur d'une famille, ce qui diffère de la
 * valeur majoritaire (icône, couleur, libellé) est signalé. Sont aussi signalés : l'absence d'icône, les caractères
 * utilisés à la place d'une icône, les libellés à revoir (abrégés, fautes probables) et les classes hors kit.
 *
 * Utilisé par la thématique « Inventaire » du styleguide (fonction Twig sg_commandes()).
 * Lecture seule : aucun fichier n'est modifié.
 */
final class UiCommandScanner
{
    /** Dossiers et fichiers ignorés (outils, composants génériques qui n'existent que via leurs appels). */
    private const SKIP = [
        'templates/styleguide', 'templates/verification', 'templates/public', 'templates/src', 'templates/templates',
        'templates/projet/old',
        'templates/layout/modern/components/_btns.html.twig',
        'templates/layout/modern/components/_tabs.html.twig',
    ];

    private const GLYPHS = ['✕' => 'fermer', '×' => 'fermer', '＋' => 'ajout', '+' => 'ajout', '✓' => 'valider', '✔' => 'valider',
        '↑' => 'deplacer', '↓' => 'deplacer', '▲' => 'deplacer', '▼' => 'deplacer', '⤒' => 'deplacer', '✂' => 'detail', '⧉' => 'detail'];

    private const ADD_ICONS = ['gridicons:add', 'ic:round-add', 'lucide:plus', 'ph:plus'];

    /** Verbes d'action connus : sert à repérer une faute probable (« Retaure » ≈ « Restaurer »). */
    private const VOCAB = ['restaurer', 'supprimer', 'enregistrer', 'ajouter', 'modifier', 'retour', 'finaliser', 'appliquer', 'annuler',
        'fermer', 'rouvrir', 'regrouper', 'actualiser', 'concretiser', 'ignorer', 'masquer', 'creer', 'valider', 'nouveau', 'nouvelle',
        'ordonner', 'completer', 'afficher', 'delier', 'filtrer', 'rechercher'];

    /** Familles : libellé affiché, libellé canonique attendu (null = pas de libellé imposé), ordre. */
    public const FAMILIES = [
        'retour' => ['Retour', 'Retour'],
        'ajout' => ['Ajout, création, nouveau', null],
        'enregistrer' => ['Enregistrer', 'Enregistrer'],
        'valider' => ['Valider, appliquer, finaliser, concrétiser', null],
        'supprimer' => ['Supprimer', 'Supprimer'],
        'restaurer' => ['Restaurer', 'Restaurer'],
        'remonter' => ['Remonter (parent, type)', null],
        'modifier' => ['Modifier', 'Modifier'],
        'fermer' => ['Fermer', 'Fermer'],
        'voir' => ['Voir, compléter, ouvrir', null],
        'masquer' => ['Masquer, ignorer, désélectionner', null],
        'deplacer' => ['Monter, descendre, placer', null],
        'detail' => ['Détail : dégrouper, sortir', null],
        'liste' => ['Aller à une autre liste', null],
        'onglet' => ['Onglets', null],
        'menu' => ['Menus et barre haute', null],
        'depliant' => ['Dépliants', null],
        'segment' => ['Filtres segmentés', null],
        'autre' => ['Autres commandes', null],
    ];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /**
     * @return array{items: list<array<string,mixed>>, groups: list<array<string,mixed>>, stats: array<string,int>, files: array<string,list<array<string,mixed>>>}
     */
    public function report(): array
    {
        $items = [];
        foreach ($this->files('templates', ['twig']) as $rel) {
            $src = (string) file_get_contents($this->projectDir . '/' . $rel);
            array_push($items, ...$this->scanObjects($rel, $src), ...$this->scanMarkup($rel, $src));
        }
        foreach ($this->files('public/js', ['js']) as $rel) {
            if (str_contains($rel, 'styleguide') || str_contains($rel, 'verification')) {
                continue;
            }
            array_push($items, ...$this->scanJs($rel, (string) file_get_contents($this->projectDir . '/' . $rel)));
        }

        foreach ($items as $i => &$item) {
            $item['id'] = $i + 1;
            $item['family'] = $this->family($item);
            $item['flags'] = [];
        }
        unset($item);

        $groups = $this->analyse($items);

        $stats = ['total' => count($items), 'a_revoir' => 0, 'divergent' => 0, 'sans_icone' => 0, 'caractere' => 0, 'libelle' => 0, 'hors_kit' => 0];
        foreach ($items as $item) {
            if ($item['flags'] !== []) {
                ++$stats['a_revoir'];
            }
            foreach (array_unique(array_column($item['flags'], 'type')) as $type) {
                if (isset($stats[$type])) {
                    ++$stats[$type];
                }
            }
        }

        $files = [];
        foreach ($items as $item) {
            $files[$item['file']][] = $item;
        }
        ksort($files);
        foreach ($files as &$list) {
            usort($list, static fn ($a, $b) => [$a['line'], $a['id']] <=> [$b['line'], $b['id']]);
        }
        unset($list);

        return ['items' => $items, 'groups' => $groups, 'stats' => $stats, 'files' => $files];
    }

    // ------------------------------------------------------------------ lecture des fichiers

    /** @return list<string> chemins relatifs, triés */
    private function files(string $dir, array $ext): array
    {
        $base = $this->projectDir . '/' . $dir;
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !in_array($file->getExtension(), $ext, true)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($this->projectDir) + 1));
            foreach (self::SKIP as $skip) {
                if ($rel === $skip || str_starts_with($rel, $skip . '/')) {
                    continue 2;
                }
            }
            $out[] = $rel;
        }
        sort($out);

        return $out;
    }

    // ------------------------------------------------------------------ 1. objets Twig { label: …, path: …, icon: … }

    /** Boutons d'en-tête (_btns) et onglets (_tabs) déclarés comme objets Twig. */
    private function scanObjects(string $rel, string $src): array
    {
        $found = [];
        if (!preg_match_all('/\blabel\s*:/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $seen = [];
        foreach ($m[0] as [$tok, $pos]) {
            $start = $this->openBrace($src, $pos);
            if ($start === null || isset($seen[$start])) {
                continue;
            }
            $end = $this->closeBrace($src, $start);
            if ($end === null) {
                continue;
            }
            $seen[$start] = true;
            $pairs = $this->pairs(substr($src, $start + 1, $end - $start - 1));
            if (isset($pairs['live']) || isset($pairs['prefixes']) || isset($pairs['hint']) || isset($pairs['route'])) {
                continue;
            }
            $line = substr_count($src, "\n", 0, $pos) + 1;
            $isTab = isset($pairs['id']) && (isset($pairs['content']) || isset($pairs['badge']) || isset($pairs['url']));
            $isSubmit = isset($pairs['type']) && $this->literals($pairs['type'])[0] === 'submit';
            $isLink = isset($pairs['path']);
            if (!$isTab && !$isSubmit && !$isLink) {
                continue;
            }
            if ($isLink && !$isTab && str_contains(substr($src, max(0, $start - 200), 200), 'breadcrumb_trail')) {
                continue;
            }

            $labels = $this->literals($pairs['label']);
            $icon = isset($pairs['icon']) ? ($this->literals($pairs['icon'])[0] ?? null) : null;
            $color = isset($pairs['color']) ? ($this->literals($pairs['color'])[0] ?? null) : null;
            if ($isTab) {
                $kind = 'onglet';
                $classes = 'tab-button';
                $tint = null;
            } else {
                $kind = 'bouton';
                $tint = in_array($color, [null, 'null', ''], true) ? 'neutral' : $color;
                $classes = 'btn btn-' . $tint;
                if ($isSubmit && $icon === null) {
                    $icon = 'mdi:content-save-outline';
                }
            }
            if ($labels === [] && $isSubmit) {
                $labels = ['Enregistrer'];
            }
            foreach ($labels ?: ['…'] as $label) {
                $found[] = $this->item($rel, $line, $isTab ? 'onglet' : $kind, $label, $icon, $classes, $tint, 'objet', dyn: $labels === [], extra: [
                    'submit' => $isSubmit,
                    'tint_declared' => $color !== null && $color !== 'null',
                ]);
            }
        }

        return $found;
    }

    private function openBrace(string $src, int $pos): ?int
    {
        $depth = 0;
        for ($i = $pos - 1; $i >= 0; --$i) {
            $c = $src[$i];
            if ($c === '}') {
                ++$depth;
            } elseif ($c === '{') {
                if ($depth === 0) {
                    $n = $src[$i + 1] ?? '';
                    return ($n === '{' || $n === '%' || $n === '#') ? null : $i;
                }
                --$depth;
            }
        }

        return null;
    }

    private function closeBrace(string $src, int $start): ?int
    {
        $depth = 0;
        $len = strlen($src);
        for ($i = $start; $i < $len; ++$i) {
            $c = $src[$i];
            if ($c === "'" || $c === '"') {
                $i = $this->skipString($src, $i);
                continue;
            }
            if ($c === '{') {
                ++$depth;
            } elseif ($c === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private function skipString(string $src, int $i): int
    {
        $q = $src[$i];
        $len = strlen($src);
        for (++$i; $i < $len; ++$i) {
            if ($src[$i] === '\\') {
                ++$i;
            } elseif ($src[$i] === $q) {
                return $i;
            }
        }

        return $len;
    }

    /** @return array<string,string> clés/valeurs de premier niveau d'un objet Twig */
    private function pairs(string $body): array
    {
        $parts = [];
        $depth = 0;
        $cur = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; ++$i) {
            $c = $body[$i];
            if ($c === "'" || $c === '"') {
                $e = $this->skipString($body, $i);
                $cur .= substr($body, $i, $e - $i + 1);
                $i = $e;
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                ++$depth;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                --$depth;
            }
            if ($c === ',' && $depth === 0) {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $parts[] = $cur;

        $out = [];
        foreach ($parts as $part) {
            if (preg_match('/^\s*([A-Za-z_]\w*)\s*:\s*(.*)$/s', $part, $mm)) {
                $out[$mm[1]] = trim($mm[2]);
            }
        }

        return $out;
    }

    /** @return list<string> littéraux entre guillemets d'une valeur (un ternaire en donne plusieurs) */
    private function literals(string $value): array
    {
        $value = trim($value);
        if ($value === 'null' || $value === '') {
            return ['null'];
        }
        if (preg_match_all('/([\'"])((?:\\\\.|(?!\1).)*)\1/s', $value, $m)) {
            // On ignore les littéraux d'un appel de fonction (path('app_x', …)).
            if (preg_match('/^[a-z_]+\(/i', $value)) {
                return [];
            }

            return array_map(static fn ($s) => str_replace(["\\'", '\\"'], ["'", '"'], $s), $m[2]);
        }

        return [];
    }

    // ------------------------------------------------------------------ 2. balises <a>, <button>, <summary>

    private function scanMarkup(string $rel, string $src): array
    {
        $found = [];
        // Les commentaires Twig {# … #} sont retirés en gardant les retours à la ligne (numéros de ligne exacts).
        $clean = preg_replace_callback('/\{#.*?#\}/s', static fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), $src) ?? $src;
        if (!preg_match_all('#<(a|button|summary)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i', $clean, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }
        foreach ($m as $tag) {
            [$full, $offset] = $tag[0];
            $name = strtolower($tag[1][0]);
            $attrs = $tag[2][0];
            $line = substr_count($clean, "\n", 0, $offset) + 1;

            $classes = '';
            if (preg_match('/\bclass="([^"]*)"/', $attrs, $c)) {
                $classes = trim(preg_replace('/\s+/', ' ', preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', ' ', $c[1])) ?? '');
            }
            $tokens = $classes === '' ? [] : explode(' ', $classes);
            if (array_intersect($tokens, ['entity-card-link', 'map-popup-link']) || str_contains($classes, 'topbar-breadcrumb')) {
                continue;
            }

            $closePos = stripos($clean, '</' . $name . '>', $offset + strlen($full));
            $inner = $closePos === false ? '' : substr($clean, $offset + strlen($full), $closePos - $offset - strlen($full));

            $icon = null;
            $iconDyn = false;
            if (preg_match('/<twig:ux:icon\s+name="([^"]*)"/', $inner, $im)) {
                $iconDyn = str_contains($im[1], '{{');
                $icon = $iconDyn ? null : $im[1];
            }
            $text = preg_replace('#<twig:ux:icon[^>]*/>#', ' ', $inner) ?? $inner;
            $labels = $this->textLabels($text);
            $hasName = (bool) preg_match('/\b(title|aria-label)="/', $attrs);
            // Bouton composite (icône + plusieurs <span> de valeurs) : le libellé est le début du title.
            if ($icon !== null && substr_count($inner, '<span') >= 2 && preg_match('/\btitle="([^"{]+)/', $attrs, $tm)) {
                $labels = ['values' => [trim($tm[1], " :")], 'dynamic' => false];
            }

            $kind = match (true) {
                $name === 'summary' => 'depliant',
                in_array('tab-button', $tokens, true) => 'onglet',
                (bool) array_filter($tokens, static fn ($t) => $t === 'segmented-item') => 'segment',
                (bool) array_filter($tokens, static fn ($t) => preg_match('/^(sidebar-link|app-switcher-item|theme-option|env-option)$/', $t)) => 'menu',
                $name === 'button' => 'bouton',
                in_array('btn', $tokens, true) => 'bouton',
                default => 'lien',
            };
            $tint = null;
            foreach ($tokens as $t) {
                if (preg_match('/^btn-(primary|neutral|danger|info|success|warning|soft|ghost|link|icon)$/', $t, $tm)) {
                    $tint ??= $tm[1];
                }
            }
            if ($kind === 'bouton' && in_array('btn', $tokens, true) && $tint === null) {
                $tint = 'nu';
            }

            foreach ($labels['values'] ?: [''] as $label) {
                $found[] = $this->item($rel, $line, $kind, $label, $icon, $classes, $tint, 'balise', dyn: $labels['dynamic'], extra: [
                    'tag' => $name,
                    'named' => $hasName,
                    'icon_dyn' => $iconDyn,
                    'submit' => $name === 'button' && str_contains($attrs, 'type="submit"'),
                ]);
            }
        }

        return $found;
    }

    /** @return array{values: list<string>, dynamic: bool} */
    private function textLabels(string $html): array
    {
        $dynamic = false;
        $values = [];
        $text = preg_replace('/\{%.*?%\}/s', ' ', $html) ?? $html;
        if (preg_match_all('/\{\{(.*?)\}\}/s', $text, $em)) {
            foreach ($em[1] as $expr) {
                if (preg_match_all('/([\'"])((?:\\\\.|(?!\1).)*)\1/s', $expr, $lm)) {
                    foreach ($lm[2] as $lit) {
                        if (!preg_match('/^[a-z0-9_-]+$/', $lit) && preg_match('/\p{L}{3}/u', $lit) && !preg_match('#^[dmYHisjnyGDlFMaA/:. -]+$#', $lit)) {
                            $values[] = trim($lit);
                        }
                    }
                }
            }
            $dynamic = true;
            $text = preg_replace('/\{\{.*?\}\}/s', ' ', $text) ?? $text;
        }
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($text !== '') {
            array_unshift($values, $text);
        }

        return ['values' => array_values(array_unique($values)), 'dynamic' => $dynamic && $values === []];
    }

    // ------------------------------------------------------------------ 3. boutons fabriqués par le JS

    private function scanJs(string $rel, string $src): array
    {
        $found = [];
        foreach (explode("\n", $src) as $n => $lineText) {
            if (!str_contains($lineText, '<button')) {
                continue;
            }
            if (!preg_match('/<button\b[^>]*?class="([\w\s-]+)/', $lineText, $c)) {
                continue;
            }
            $label = '';
            if (preg_match('/>([^<>]*)<\/button>/', $lineText, $l)) {
                $label = trim(html_entity_decode($l[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $tint = preg_match('/btn-(primary|neutral|danger|info|success|warning|soft|ghost|icon)/', $c[1], $tm) ? $tm[1] : (str_contains($c[1], 'btn') ? 'nu' : null);
            $found[] = $this->item($rel, $n + 1, 'bouton', $label, null, trim($c[1]), $tint, 'js', extra: [
                'tag' => 'button', 'named' => (bool) preg_match('/(title|aria-label)=/', $lineText), 'icon_dyn' => false, 'submit' => false,
            ]);
        }

        return $found;
    }

    private function item(string $file, int $line, string $kind, string $label, ?string $icon, string $classes, ?string $tint, string $source, bool $dyn = false, array $extra = []): array
    {
        return $extra + [
            'file' => $file, 'line' => $line, 'kind' => $kind, 'label' => $label, 'icon' => $icon === 'null' ? null : $icon,
            'classes' => $classes, 'tint' => $tint, 'source' => $source, 'dyn' => $dyn,
            'scope' => str_starts_with($file, 'templates/layout/') ? 'global' : 'page',
            'glyph' => isset(self::GLYPHS[$label]) || (mb_strlen($label) === 1 && !ctype_alnum($label) && $label !== ''),
            'tag' => $extra['tag'] ?? ($kind === 'onglet' ? 'tab' : 'a'), 'named' => $extra['named'] ?? true,
        ];
    }

    // ------------------------------------------------------------------ familles et analyse

    private function norm(string $s): string
    {
        return strtolower(strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'É' => 'E', 'È' => 'E']));
    }

    /** @param array<string,mixed> $it */
    private function family(array $it): string
    {
        $label = $this->norm(trim((string) $it['label']));
        $icon = (string) $it['icon'];

        if ($it['glyph'] && isset(self::GLYPHS[$it['label']])) {
            return self::GLYPHS[$it['label']];
        }

        return match (true) {
            $it['kind'] === 'onglet' => 'onglet',
            $it['kind'] === 'depliant' => 'depliant',
            $it['kind'] === 'segment' => 'segment',
            $it['kind'] === 'menu' => 'menu',
            $label === 'retour' || $icon === 'mdi:arrow-left' => 'retour',
            (bool) preg_match('/^(suppr|supprimer)/', $label) || str_contains($icon, 'trash') && !str_contains($icon, 'restore') => 'supprimer',
            (bool) preg_match('/^(restaur|retaure)/', $label) || str_contains($icon, 'restore') => 'restaurer',
            $icon === 'icon-park-solid:up-c' => 'remonter',
            (bool) preg_match('/^(enregistrer|sauvegarder)/', $label) || (str_starts_with($label, 'creer') && $icon === 'mdi:content-save-outline') => 'enregistrer',
            (bool) preg_match('/^(appliquer|finaliser|concretiser|valider|ajouter au releve)/', $label) => 'valider',
            (bool) preg_match('/^(ajouter|ajout|nouveau|nouvelle|creer|faire un releve|tout ajouter|en un detail|\+)/', $label) || in_array($icon, self::ADD_ICONS, true) => 'ajout',
            (bool) preg_match('/^(modifier|editer)/', $label) => 'modifier',
            (bool) preg_match('/^(fermer|annuler)/', $label) => 'fermer',
            (bool) preg_match('/^(voir|completer|ordonner|tout voir|compl)/', $label) => 'voir',
            (bool) preg_match('/^(masquer|ignorer|deselectionner|reafficher)/', $label) => 'masquer',
            in_array($icon, ['mage:users-fill', 'grommet-icons:cubes', 'ix:project', 'lucide:list-ordered'], true) && $it['kind'] === 'bouton' => 'liste',
            default => 'autre',
        };
    }

    /**
     * Compare chaque commande à la valeur majoritaire de sa famille et pose les signaux.
     *
     * @param list<array<string,mixed>> $items
     *
     * @return list<array<string,mixed>>
     */
    private function analyse(array &$items): array
    {
        $byFamily = [];
        foreach ($items as $i => $it) {
            $byFamily[$it['family']][] = $i;
        }

        $groups = [];
        foreach (self::FAMILIES as $key => [$title, $canon]) {
            $idx = $byFamily[$key] ?? [];
            if ($idx === []) {
                continue;
            }
            // Les onglets se comparent entre onglets de même libellé ; les autres, dans la famille entière.
            $buckets = $key === 'onglet' ? $this->bucketsByLabel($items, $idx) : [$idx];

            foreach ($buckets as $bucket) {
                if (in_array($key, ['menu', 'autre', 'segment', 'depliant'], true)) {
                    break;
                }
                $this->compare($items, $bucket, $key !== 'onglet' && $key !== 'menu' && $key !== 'depliant' && $key !== 'segment' && $key !== 'autre');
            }

            foreach ($idx as $i) {
                $this->flagCommon($items[$i], $canon);
            }

            $icons = array_unique(array_filter(array_column(array_map(fn ($i) => $items[$i], $idx), 'icon')));
            $tints = array_unique(array_filter(array_column(array_map(fn ($i) => $items[$i], $idx), 'tint')));
            $labels = array_unique(array_map(fn ($i) => $items[$i]['label'], $idx));
            $groups[] = [
                'key' => $key, 'title' => $title, 'count' => count($idx),
                'alerts' => count(array_filter($idx, fn ($i) => $items[$i]['flags'] !== [])),
                'icons' => count($icons), 'tints' => count($tints), 'labels' => count($labels),
                'ids' => array_map(fn ($i) => $items[$i]['id'], $idx),
            ];
        }

        return $groups;
    }

    /** @return list<list<int>> */
    private function bucketsByLabel(array $items, array $idx): array
    {
        $by = [];
        foreach ($idx as $i) {
            $by[$this->norm($items[$i]['label'])][] = $i;
        }

        return array_values($by);
    }

    private function compare(array &$items, array $idx, bool $compareTint): void
    {
        if (count($idx) < 2) {
            return;
        }
        $mode = static function (array $values): ?string {
            $values = array_filter($values, static fn ($v) => $v !== null && $v !== '');
            if ($values === []) {
                return null;
            }
            $count = array_count_values($values);
            arsort($count);

            return (string) array_key_first($count);
        };
        $icons = array_map(fn ($i) => $items[$i]['icon'], $idx);
        $tints = array_map(fn ($i) => in_array($items[$i]['tint'], ['soft', 'ghost', 'link', 'icon'], true) ? null : $items[$i]['tint'], $idx);
        $modeIcon = $mode($icons);
        $modeTint = $mode($tints);
        $distinctIcons = count(array_unique(array_filter($icons)));
        $distinctTints = count(array_unique(array_filter($tints)));

        foreach ($idx as $i) {
            $it = &$items[$i];
            $it['ref_icon'] = $modeIcon;
            $it['ref_tint'] = $modeTint;
            if ($distinctIcons > 1 && $it['icon'] !== null && $it['icon'] !== $modeIcon && !$it['glyph']) {
                $it['flags'][] = ['type' => 'divergent', 'msg' => 'icône ' . $it['icon'] . ' (majoritaire : ' . $modeIcon . ')'];
                $it['diff_icon'] = true;
            }
            if ($compareTint && $distinctTints > 1 && $it['tint'] !== null && !in_array($it['tint'], ['soft', 'ghost', 'link', 'icon'], true) && $it['tint'] !== $modeTint) {
                $it['flags'][] = ['type' => 'divergent', 'msg' => 'couleur ' . $it['tint'] . ' (majoritaire : ' . $modeTint . ')'];
                $it['diff_tint'] = true;
            }
            unset($it);
        }
    }

    /** @param array<string,mixed> $it */
    private function flagCommon(array &$it, ?string $canon): void
    {
        $label = trim((string) $it['label']);
        $family = $it['family'];

        // Libellé différent du libellé attendu de la famille.
        if ($canon !== null && $family === 'enregistrer' && str_starts_with($this->norm($label), 'creer')) {
            $canon = null;
        }
        if ($canon !== null && $label !== '' && !$it['glyph'] && $this->norm($label) !== $this->norm($canon) && !$it['dyn']) {
            $it['flags'][] = ['type' => 'libelle', 'msg' => 'libellé « ' . $label . ' » (attendu : « ' . $canon . ' »)'];
            $it['diff_label'] = true;
        }
        if ($label !== '' && !$it['glyph'] && !$it['dyn']) {
            if (str_ends_with($label, '.') && $canon === null || str_ends_with($label, '.') && $this->norm($label) !== $this->norm($canon ?? '')) {
                $it['flags'][] = ['type' => 'libelle', 'msg' => 'libellé abrégé'];
                $it['diff_label'] = true;
            } else {
                $word = $this->norm(preg_split('/\s+/', rtrim($label, '.…'))[0] ?? '');
                if (strlen($word) >= 5 && !in_array($word, self::VOCAB, true)) {
                    foreach (self::VOCAB as $v) {
                        if (levenshtein($word, $v) <= 2 && abs(strlen($word) - strlen($v)) <= 2) {
                            $it['flags'][] = ['type' => 'libelle', 'msg' => 'faute probable : « ' . $label . ' » ≈ « ' . $v . ' »'];
                            $it['diff_label'] = true;
                            break;
                        }
                    }
                }
            }
        }

        // Caractère à la place d'une icône.
        if ($it['glyph']) {
            $it['flags'][] = ['type' => 'caractere', 'msg' => 'caractère « ' . $label . ' » au lieu d’une icône'];
        }

        // Sans icône.
        $needsIcon = in_array($it['kind'], ['bouton', 'onglet'], true)
            && $it['icon'] === null && empty($it['icon_dyn']) && !$it['glyph'] && $label !== ''
            && !preg_match('/(^|\s)(btn-link|btn-icon|rc-done|pill)(\s|$)/', $it['classes']);
        if ($needsIcon) {
            $it['flags'][] = ['type' => 'sans_icone', 'msg' => $it['kind'] === 'onglet' ? 'onglet sans icône' : 'bouton sans icône'];
        }

        // Icône seule sans nom accessible.
        if ($it['label'] === '' && !$it['glyph'] && !$it['named'] && in_array($it['kind'], ['bouton', 'lien'], true) && $it['source'] === 'balise' && ($it['icon'] !== null || !empty($it['icon_dyn']))) {
            $it['flags'][] = ['type' => 'libelle', 'msg' => 'aucun libellé ni title / aria-label'];
        }

        // Teinte non déclarée / lien nu / hors kit.
        if ($it['kind'] === 'bouton' && $it['tint'] === 'nu') {
            $it['flags'][] = ['type' => 'hors_kit', 'msg' => '.btn sans teinte (btn-neutral attendu)'];
        } elseif ($it['kind'] === 'bouton' && $it['source'] === 'objet' && empty($it['tint_declared']) && !$it['submit']) {
            $it['flags'][] = ['type' => 'hors_kit', 'msg' => 'couleur non déclarée (retombe sur neutre)'];
        } elseif ($it['kind'] === 'lien' && $it['classes'] === '' && $it['source'] === 'balise') {
            $it['flags'][] = ['type' => 'hors_kit', 'msg' => 'lien nu, sans classe'];
        } elseif (in_array($it['kind'], ['bouton', 'menu'], true) && $it['source'] !== 'objet' && !preg_match('/(^|\s)(btn|pill|tab-button|segmented-item)/', $it['classes'])) {
            $it['flags'][] = ['type' => 'hors_kit', 'msg' => 'classe propre : .' . (explode(' ', $it['classes'])[0] ?: '∅')];
        }

        // Dédoublonnage des signaux identiques.
        $seen = [];
        $it['flags'] = array_values(array_filter($it['flags'], static function ($f) use (&$seen) {
            $k = $f['type'] === 'libelle' ? 'libelle' : $f['type'] . $f['msg'];

            return !isset($seen[$k]) && ($seen[$k] = true);
        }));
    }
}
