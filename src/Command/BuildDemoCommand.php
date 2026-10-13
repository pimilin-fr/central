<?php

namespace App\Command;

use App\Demo\DemoBuilder;
use App\Demo\DemoProgress;
use App\Demo\DemoPurger;
use App\Demo\DemoSchemaCloner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:demo:build',
    description: 'Reconstruit la base démo à partir de la production (copie / anonymisation / exclusion)'
)]
class BuildDemoCommand extends Command {

    public function __construct(
        /** Base de production (source). */
        private EntityManagerInterface $em,
        /** Base démo (cible). */
        #[Autowire(service: 'doctrine.orm.demo_entity_manager')] private EntityManagerInterface $demoEm,
        private DemoBuilder $builder,
        private DemoPurger $purger,
        private DemoSchemaCloner $cloner,
        private DemoProgress $progress
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Recrée d’abord la STRUCTURE de la base démo (tables, index, clés, vues) à l’identique de la prod, après avoir supprimé son contenu : la démo est jetable')
            ->addOption('allow-any-name', null, InputOption::VALUE_NONE, 'Autorise une base démo dont le nom ne contient pas « demo » (déconseillé)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule : écrit dans une transaction annulée, ne vide rien, affiche le compte rendu')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Ne pas demander confirmation avant de vider la base démo')
            ->addOption('shift', null, InputOption::VALUE_REQUIRED, 'Décalage des dates : un nombre de jours, ou « auto » (rapproche la dernière opération d’aujourd’hui, par semaines entières)', '0')
            ->addOption('jitter', null, InputOption::VALUE_REQUIRED, 'Variation maximale (en %) des montants des opérations anonymisées (0 = inchangés)', '15')
            ->addOption('strip-notes', null, InputOption::VALUE_NONE, 'Vider aussi le champ « note » des opérations copiées (texte libre, risque de fuite)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Suivi pour l'interface (var/demo-build.progress.json) : avancement, étapes, erreur détaillée.
        $this->progress->begin();
        $io->writeln(sprintf('[%s] app:demo:build démarrée (pid %d)', date('H:i:s'), getmypid()));
        register_shutdown_function(function (): void {
            if ($this->progress->isRunning()) {
                $e = error_get_last();
                $this->progress->finish(false, 'La commande s\'est arrêtée brutalement' . ($e ? ' : ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')' : '.'));
            }
        });

        // ---- garde-fous : ne JAMAIS écrire dans la production ---------------------------------
        $source = $this->databaseKey($this->em);
        $target = $this->databaseKey($this->demoEm);
        if ($this->em === $this->demoEm || $source === $target) {
            $io->error(sprintf(
                "La base démo et la base source sont identiques (%s). Refus de continuer : vérifiez DEMO_DATABASE_URL.",
                $target
            ));
            $this->progress->finish(false, sprintf('Base démo et source identiques (%s) : refus.', $target));

            return Command::FAILURE;
        }

        [$sourceDb, $targetDb] = $this->cloner->databases();
        if (!$input->getOption('allow-any-name') && !str_contains(strtolower($targetDb), 'demo')) {
            $io->error(sprintf('Le nom de la base cible (« %s ») ne contient pas « demo » : refus par précaution (sinon --allow-any-name).', $targetDb));
            $this->progress->finish(false, sprintf('Le nom de la base cible « %s » ne contient pas « demo » : refus.', $targetDb));

            return Command::FAILURE;
        }

        $fresh = (bool) $input->getOption('fresh');
        if ($fresh && $dryRun) {
            $io->error('--fresh et --dry-run sont incompatibles (--fresh supprime le contenu de la base démo).');
            $this->progress->finish(false, '--fresh et --dry-run sont incompatibles.');

            return Command::FAILURE;
        }

        if (!$fresh) {
            $missing = array_filter(
                $this->purger->tables(),
                fn (string $table) => !$this->demoEm->getConnection()->createSchemaManager()->tablesExist([$table])
            );
            if ($missing) {
                $io->error([
                    'La base démo n’a pas le schéma attendu (tables manquantes : ' . implode(', ', $missing) . ').',
                    'Relancez avec --fresh pour recréer la structure depuis la production.',
                ]);
                $this->progress->finish(false, 'Schéma démo incomplet (tables manquantes : ' . implode(', ', $missing) . ').');

                return Command::FAILURE;
            }
        }

        // ---- options du run ------------------------------------------------------------------
        $context = $this->builder->getContext();
        $context->jitterPercent = max(0.0, (float) $input->getOption('jitter'));
        $context->stripNotes = (bool) $input->getOption('strip-notes');
        $context->dateShiftDays = $this->resolveShift((string) $input->getOption('shift'));

        $io->title('Construction de la base démo');
        $io->definitionList(
            ['Source (lecture seule)' => $source],
            ['Cible (démo)' => $target],
            ['Décalage des dates' => $context->dateShiftDays . ' jour(s)'],
            ['Variation des montants anonymisés' => '± ' . $context->jitterPercent . ' %'],
            ['Notes copiées vidées' => $context->stripNotes ? 'oui' : 'non'],
            ['Structure démo' => $fresh ? 'recréée depuis la prod (--fresh)' : 'conservée, données purgées'],
            ['Mode' => $dryRun ? 'SIMULATION (rien n’est conservé)' : 'réel']
        );

        if (!$dryRun) {
            if (!$input->getOption('yes') && !$io->confirm(sprintf('Tout le contenu de « %s » va être SUPPRIMÉ puis reconstruit. Continuer ?', $targetDb), false)) {
                $io->warning('Annulé.');
                $this->progress->finish(false, 'Annulé par l\'utilisateur.');

                return Command::SUCCESS;
            }

            try {
                if ($fresh) {
                    $io->section('Recréation de la structure (copie depuis la production)');
                    $this->progress->phase('Recréation de la structure (tables, index, vues)', 2);
                    $this->progress->step('Structure de la base démo', 'running');
                    $done = $this->cloner->recreate();
                    $io->writeln(sprintf(' • %d tables et %d vues recréées dans « %s »', $done['tables'], $done['views'], $targetDb));
                    $this->progress->step('Structure de la base démo', 'ok', sprintf('%d tables, %d vues', $done['tables'], $done['views']));
                } else {
                    $io->section('Purge de la base démo');
                    $this->progress->phase('Purge de la base démo', 2);
                    $this->progress->step('Purge de la base démo', 'running');
                    $this->purger->purge();
                    $this->progress->step('Purge de la base démo', 'ok');
                }
            } catch (Throwable $e) {
                $io->error('Échec avant la copie des données (la production n’a pas été touchée) : ' . $e->getMessage());
                $this->progress->finish(false, 'Échec avant la copie des données (la production n\'a pas été touchée).', $e);

                return Command::FAILURE;
            }
        }

        // ---- construction --------------------------------------------------------------------
        $connection = $this->demoEm->getConnection();
        $sourceConnection = $this->em->getConnection();

        // PRODUCTION EN LECTURE SEULE, garantie par MySQL lui-même : toute tentative d'écriture sur la
        // connexion source échouerait. Photo cohérente (REPEATABLE READ) pendant toute la copie.
        try {
            $sourceConnection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $sourceConnection->beginTransaction();
        } catch (Throwable $e) {
            $io->error('Impossible de passer la production en lecture seule, abandon (' . $e->getMessage() . ')');
            $this->progress->finish(false, 'Impossible de passer la production en lecture seule.', $e);

            return Command::FAILURE;
        }

        $connection->beginTransaction();
        try {
            $io->section('Transformation');
            $this->progress->phase('Copie et anonymisation des données', 5);
            $this->builder->build(static fn (string $line) => $io->writeln(' • ' . $line), function (array $i): void {
                // 5 % réservés à la structure, 95 % à la copie : au prorata des lignes (sinon des étapes)
                $fraction = $i['rowsTotal'] > 0
                    ? $i['rowsDone'] / $i['rowsTotal']
                    : (($i['index'] - 1) + ($i['kind'] === 'done' ? 1 : 0)) / max(1, $i['steps']);
                $label = sprintf('%s (%d/%d)', $i['label'], $i['index'], $i['steps']);
                if ($i['kind'] === 'done') {
                    $this->progress->step($i['label'], 'ok', $i['summary']);
                    $this->progress->tick($label . ' — terminé', 5 + 90 * $fraction);
                } elseif ($i['kind'] === 'start') {
                    $this->progress->step($i['label'], 'running', $i['curRows'] > 0 ? $i['curRows'] . ' ligne(s) à traiter' : '');
                    $this->progress->tick($label, 5 + 90 * $fraction);
                } else {
                    $this->progress->tick($label, 5 + 90 * $fraction, sprintf('%d / %d ligne(s)', $i['curDone'], $i['curRows']));
                }
            });
            $this->progress->phase($dryRun ? 'Simulation : annulation de la transaction' : 'Validation de la transaction (commit)', 97);

            if ($dryRun) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }
        } catch (Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $io->error('Échec, la production n’a pas été modifiée, la base démo est à refaire : ' . $e->getMessage());
            $io->writeln('<comment>' . $e::class . ' — ' . basename($e->getFile()) . ':' . $e->getLine() . '</comment>');
            $this->progress->finish(false, 'Échec pendant la copie : la production n\'a pas été modifiée, la base démo est à refaire.', $e);

            return Command::FAILURE;
        } finally {
            if ($sourceConnection->isTransactionActive()) {
                $sourceConnection->rollBack(); // la source ne reçoit jamais de commit
            }
        }

        $this->report($io);
        $this->progress->finish(true, $dryRun ? 'Simulation terminée : aucune donnée conservée.' : 'Base démo reconstruite.');
        $io->success($dryRun ? 'Simulation terminée : aucune donnée n’a été conservée.' : 'Base démo reconstruite.');

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io): void {
        $stats = $this->builder->getContext()->stats();
        $rows = [];
        foreach ($this->builder->getTransformers() as $transformer) {
            $s = $stats[$transformer->getSourceClass()] ?? [];
            $rows[] = [
                $transformer->getLabel(),
                $s['copy'] ?? 0,
                $s['anonymize'] ?? 0,
                $s['exclude'] ?? 0,
                $s['skipped'] ?? 0,
            ];
        }
        $io->table(['Entité', 'Copiés', 'Anonymisés', 'Exclus', 'Ignorés*'], $rows);
        $io->writeln('<comment>* ignoré = ligne conservée par sa propre règle mais dont une dépendance (tiers, portefeuille…) a été exclue.</comment>');
    }

    /** « 0 », « 30 » ou « auto » => nombre de jours (multiple de 7 en mode auto, pour garder les jours de semaine). */
    private function resolveShift(string $value): int {
        if ($value !== 'auto') {
            return (int) $value;
        }

        $last = $this->em->createQuery('SELECT MAX(d.date) FROM ' . \App\Entity\Depenses::class . ' d')->getSingleScalarResult();
        if (!$last) {
            return 0;
        }
        $days = (int) (new DateTimeImmutable($last))->diff(new DateTimeImmutable('today'))->format('%r%a');

        return intdiv($days, 7) * 7;
    }

    private function databaseKey(EntityManagerInterface $em): string {
        $p = $em->getConnection()->getParams();

        return sprintf('%s:%s/%s', $p['host'] ?? 'local', $p['port'] ?? '-', $p['dbname'] ?? $p['path'] ?? '?');
    }
}
