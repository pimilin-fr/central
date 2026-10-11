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

        // 1. classes définies dans le CSS (hors thèmes et feuille du styleguide)
        $defined = [];
        foreach (glob($this->projectDir.'/public/css/*.css') ?: [] as $file) {
            if (str_ends_with($file, 'styleguide.css')) {
                continue;
            }
            $css = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
            $css = preg_replace('/\{[^{}]*\}/', '{}', (string) $css);
            if (preg_match_all('/\.([A-Za-z_][\w-]*)/', (string) $css, $m)) {
                foreach ($m[1] as $c) {
                    $defined[$c][basename($file)] = true;
                }
            }
        }

        // 2. texte de l'application et du styleguide
        $app = $this->read(['templates', 'public/js', 'src'], ['templates/styleguide', 'templates/verification', 'public/js/verification', 'src/Command/UiAuditCommand.php']);
        $guide = $this->read(['templates/styleguide'], []);

        $used = static fn (string $c, string $text): bool => (bool) preg_match('/(?<![\w-])'.preg_quote($c, '/').'(?![\w-])/', $text);

        $noExample = $orphans = [];
        foreach ($defined as $class => $files) {
            if (preg_match(self::IGNORE, $class)) {
                continue;
            }
            $inApp = $used($class, $app);
            $inGuide = $used($class, $guide);
            if (!$inApp && !$inGuide) {
                $orphans[] = $class;
            } elseif ($inApp && !$inGuide) {
                $noExample[] = $class.'  ('.implode(', ', array_keys($files)).')';
            }
        }

        // 3. utilitaires morts : jetons de class="…" qui ressemblent à du Tailwind et n'ont aucun CSS
        $deadUtil = [];
        if (preg_match_all('/class\s*=\s*(["\'])(.*?)\1/s', $app, $m)) {
            foreach ($m[2] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) ?: [] as $tok) {
                    if ($tok !== '' && !isset($defined[$tok]) && preg_match(self::UTILITY, $tok)) {
                        $deadUtil[$tok] = ($deadUtil[$tok] ?? 0) + 1;
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
            $io->section('Orphelines : à supprimer du CSS');
            $io->listing($orphans);
        }
        if ($deadUtil) {
            $io->section('Utilitaires sans CSS : à retirer des gabarits');
            arsort($deadUtil);
            $io->listing(array_map(static fn ($c, $n) => $c.' ×'.$n, array_keys($deadUtil), $deadUtil));
        }
        if ($noExample && $input->getOption('list')) {
            $io->section('Utilisées mais sans exemple : ajouter un exemple vivant (ou une ligne « Référence »)');
            $io->listing($noExample);
        }

        if (!$orphans && !$deadUtil && !$noExample) {
            $io->success('Style conforme : toute classe est utilisée et documentée.');

            return Command::SUCCESS;
        }

        return ($orphans || $deadUtil) && $input->getOption('strict') ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param list<string> $dirs @param list<string> $exclude */
    private function read(array $dirs, array $exclude): string
    {
        $text = '';
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
                $text .= file_get_contents($file->getPathname())."\n";
            }
        }

        return $text;
    }
}
