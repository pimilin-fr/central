<?php

namespace App\Command;

use App\Demo\TiersNamer;
use App\Entity\Tiers;
use App\Entity\TypeTiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Montre, pour chaque type de tiers réel, comment un tiers anonymisé sera nommé dans la démo
 * (config/demo/tiers_naming.yaml). Lecture seule.
 */
#[AsCommand(name: 'app:demo:types', description: 'Couverture des règles de nommage de la démo par type de tiers (lecture seule)')]
class DemoTypesCommand extends Command {

    public function __construct(private readonly EntityManagerInterface $em, private readonly TiersNamer $namer) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->addOption('unmatched', 'u', InputOption::VALUE_NONE, 'Seulement les types SANS règle (triés par nombre de tiers)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        $rows = $this->em->createQueryBuilder()
            ->select('tt AS type', 'COUNT(t.id) AS total')
            ->from(TypeTiers::class, 'tt')
            ->leftJoin(Tiers::class, 't', 'WITH', 't.tiersType = tt')
            ->groupBy('tt.id')
            ->orderBy('tt.name', 'ASC')
            ->getQuery()->getResult();

        $table = [];
        $stats = ['types' => 0, 'matched' => 0, 'tiers' => 0, 'tiersMatched' => 0, 'persons' => 0];
        foreach ($rows as $row) {
            /** @var TypeTiers $type */
            $type = $row['type'];
            $count = (int) $row['total'];
            $resolution = $this->namer->resolve($type, false);
            $matched = $resolution['rule'] !== null;

            $stats['types']++;
            $stats['tiers'] += $count;
            if ($matched) {
                $stats['matched']++;
                $stats['tiersMatched'] += $count;
            }
            if ($input->getOption('unmatched') && $matched) {
                continue;
            }

            $table[] = [
                'count' => $count,
                'row' => [
                    $type->getName(),
                    $count,
                    $resolution['rule'] !== null ? sprintf('%s : %s', $resolution['level'], mb_strimwidth($resolution['rule'], 0, 40, '…')) : '— aucune —',
                    $this->namer->preview($type, false, 'apercu-' . $type->getId()),
                ],
            ];
        }

        if ($input->getOption('unmatched')) {
            usort($table, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        }

        $io->title('Noms factices des tiers anonymisés, par type');
        $io->table(['Type (N1/N2/N3)', 'Tiers', 'Règle (niveau : mots-clés)', 'Exemple (société)'], array_column($table, 'row'));
        $io->writeln(sprintf(
            '%d types : %d avec règle, %d sans (nom de société générique). Couverture : %d / %d tiers.',
            $stats['types'], $stats['matched'], $stats['types'] - $stats['matched'], $stats['tiersMatched'], $stats['tiers']
        ));
        $io->note('Ajoutez des règles dans config/demo/tiers_naming.yaml (mots-clés + modèles), puis relancez cette commande.');

        return Command::SUCCESS;
    }
}
