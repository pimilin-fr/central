<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Audit du style (LECTURE SEULE) : applique la règle « pas de nouvelle classe sans exemple ».
 *
 *   1. classes UTILISÉES dans l'application mais SANS exemple dans le styleguide (templates/styleguide)
 *   2. classes définies dans le CSS mais utilisées NULLE PART (ni application, ni styleguide) : à supprimer
 *   3. classes de type « utilitaire Tailwind » (px-4, text-right…) utilisées sans CSS : à retirer des gabarits
 *
 * Usage : php bin/console app:ui:audit [--list] [--strict]
 *   --list    affiche aussi les classes sans exemple
 *   --strict  code de retour 1 si une classe orpheline ou un utilitaire mort est trouvé
 * Ne touche à aucun fichier ni à la base de données.
 */
#[AsCommand(name: 'app:ui:audit', description: 'Audit du style : classes sans exemple, orphelines, utilitaires morts (lecture seule)')]
class UiAuditCommand extends Command
{
    /** classes ignorées : états posés par script, bibliothèques tierces, réservées */
    private const IGNORE = '/^(leaflet-|is-|has-|level-|sg-)/';

    /** utilitaires de type Tailwind (aucun CSS ne les définit dans ce projet) */
    private const UTILITY = '/^(?:(?:sm|md|lg|xl):)?(?:p[xytblr]?-\d+|m[xytblr]?-\d+|gap-\d+|w-\d+|h-\d+|text-(?:xs|sm|base|lg|left|right|center)|font-(?:medium|semibold|bold)|rounded(?:-\w+)?|inline-flex|items-center|justify-\w+|grid-cols-\d+|space-[xy]-\d+)$/';

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('list', null, InputOption::VALUE_NONE, 'Liste aussi les classes sans exemple')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Échec (code 1) si orphelines ou utilitaires morts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $strictFail = false;

        // 1. classes définies dans le CSS (hors feuille du styleguide) : fichier + ligne de la 1re définition
        $defined = [];
        foreach (glob($this->projectDir.'/public/css/*.css') ?: [] as $file) {
            if (str_ends_with($file, 'styleguide.css')) {
                continue;
            }
            $rel = 'public/css/'.basename($file);
            $raw = (string) file_get_contents($file);
            // on remplace commentaires et corps de règles par des espaces/retours à la ligne pour garder les numéros de ligne
            $css = preg_replace_callback('~/\*.*?\*/~s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $raw);
            $css = preg_replace_callback('/\{[^{}]*\}/', static fn (array $m): string => '{'.str_repeat("\n", substr_count($m[0], "\n")).'}', (string) $css);
            foreach (preg_split('/\R/', (string) $css) ?: [] as $n => $line) {
                if (preg_match_all('/\.([A-Za-z_][\w-]*)/', $line, $m)) {
                    foreach ($m[1] as $c) {
                        $defined[$c][$rel.':'.($n + 1)] ??= true;
                    }
                }
            }
        }

        // 2. fichiers de l'application et du styleguide
        $appFiles = $this->read(['templates', 'public/js', 'src'], ['templates/styleguide', 'templates/verification', 'public/js/verification', 'src/Command/UiAuditCommand.php']);
        $guideFiles = $this->read(['templates/styleguide'], []);
        $app = implode("\n", $appFiles);
        $guide = implode("\n", $guideFiles);

        $used = static fn (string $c, string $text): bool => (bool) preg_match('/(?<![\w-])'.preg_quote($c, '/').'(?![\w-])/', $text);
        /** fichiers (max 4) où une classe est utilisée */
        $where = static function (string $c, array $files): string {
            $out = [];
            foreach ($files as $rel => $content) {
                if (preg_match('/(?<![\w-])'.preg_quote($c, '/').'(?![\w-])/', $content)) {
                    $out[] = $rel;
                    if (count($out) === 4) {
                        break;
                    }
                }
            }

            return implode(', ', $out);
        };

        $noExample = $orphans = [];
        foreach ($defined as $class => $defs) {
            if (preg_match(self::IGNORE, $class)) {
                continue;
            }
            $inApp = $used($class, $app);
            $inGuide = $used($class, $guide);
            $def = implode(', ', array_slice(array_keys($defs), 0, 2));
            if (!$inApp && !$inGuide) {
                $orphans[] = $class.'  → défini dans '.$def;
            } elseif ($inApp && !$inGuide) {
                $noExample[] = $class.'  → défini dans '.$def.' ; utilisé dans '.$where($class, $appFiles);
            }
        }

        // 3. utilitaires morts : jetons de class="…" qui ressemblent à du Tailwind et n'ont aucun CSS (fichier:ligne)
        $deadUtil = [];
        foreach ($appFiles as $rel => $content) {
            foreach (preg_split('/\R/', $content) ?: [] as $n => $line) {
                if (!preg_match_all('/class\s*=\s*(["\'])(.*?)\1/', $line, $m)) {
                    continue;
                }
                foreach ($m[2] as $attr) {
                    foreach (preg_split('/\s+/', trim($attr)) ?: [] as $tok) {
                        if ($tok !== '' && !isset($defined[$tok]) && preg_match(self::UTILITY, $tok)) {
                            $deadUtil[$tok][] = $rel.':'.($n + 1);
                        }
                    }
                }
            }
        }

        $io->title('Audit du style');
        $io->definitionList(
            ['Classes définies dans le CSS' => (string) count($defined)],
            ['Sans exemple dans le styleguide' => (string) count($noExample)],
            ['Orphelines (ni application ni styleguide)' => (string) count($orphans)],
            ['Utilitaires morts utilisés dans les gabarits' => (string) count($deadUtil)],
        );

        if ($orphans) {
            $io->section('Orphelines : à supprimer du CSS (fichier:ligne)');
            $io->listing($orphans);
            $strictFail = true;
        }
        if ($deadUtil) {
            $io->section('Utilitaires sans CSS : à retirer des gabarits (fichier:ligne)');
            uasort($deadUtil, static fn (array $a, array $b): int => count($b) <=> count($a));
            $io->listing(array_map(
                static fn (string $c, array $places): string => $c.' ×'.count($places).'  → '.implode(', ', array_slice($places, 0, 6)).(count($places) > 6 ? ' …' : ''),
                array_keys($deadUtil),
                $deadUtil
            ));
            $strictFail = true;
        }
        if ($noExample && $input->getOption('list')) {
            $io->section('Utilisées mais sans exemple : ajouter un exemple vivant (ou une ligne « Référence »)');
            $io->listing($noExample);
        }

        if (!$orphans && !$deadUtil && !$noExample) {
            $io->success('Style conforme : toute classe est utilisée et documentée.');

            return Command::SUCCESS;
        }

        return $strictFail && $input->getOption('strict') ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $dirs
     * @param list<string> $exclude
     *
     * @return array<string, string> chemin relatif => contenu
     */
    private function read(array $dirs, array $exclude): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $base = $this->projectDir.'/'.$dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($this->projectDir) + 1));
                if (!preg_match('/\.(twig|js|php)$/', $rel)) {
                    continue;
                }
                foreach (array_merge($exclude, ['templates/public/', 'templates/src/', 'templates/templates/']) as $ex) {
                    if (str_starts_with($rel, $ex)) {
                        continue 2;
                    }
                }
                $files[$rel] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
