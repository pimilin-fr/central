<?php

namespace App\Command;

use App\Entity\Depenses;
use App\Entity\Releve;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cherche les opérations rattachées à un relevé d'UN AUTRE portefeuille (données incohérentes).
 *
 * SANS DANGER : par défaut, lecture seule (liste). Avec --force, ces opérations sont simplement DÉTACHÉES
 * de leur relevé (releve_id et releve_ordre remis à NULL) : elles reviennent dans « À pointer » de leur
 * propre portefeuille. Aucune opération n'est supprimée ni modifiée autrement.
 */
#[AsCommand(name: 'app:releve:verifier', description: 'Détecte (et détache avec --force) les opérations d\'un autre portefeuille dans un relevé')]
class ReleveVerifierCommand extends Command {

    public function __construct(private readonly EntityManagerInterface $em) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Détache réellement (sinon : lecture seule)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $rows = [];
        $found = [];
        foreach ($this->em->getRepository(Releve::class)->findBy([], ['date' => 'ASC', 'id' => 'ASC']) as $releve) {
            /** @var Releve $releve */
            foreach ($releve->getDepenses() as $operation) {
                /** @var Depenses $operation */
                if ($operation->getPortefeuille()?->getId() === $releve->getPortefeuille()->getId()) {
                    continue;
                }
                $found[] = $operation;
                $rows[] = [
                    $releve->getId(), $releve->getPortefeuille()->getName(), $releve->getDate()->format('d/m/Y'),
                    $operation->getId(), $operation->getPortefeuille()?->getName() ?? '—',
                    $operation->getDate()->format('d/m/Y'), $operation->getMontant(),
                ];
            }
        }

        if ($rows === []) {
            $io->success('Aucune incohérence : chaque relevé ne contient que des opérations de son portefeuille.');

            return Command::SUCCESS;
        }

        $io->table(['Relevé', 'Portefeuille du relevé', 'Date', 'Opération', 'Portefeuille de l\'opération', 'Date op.', 'Montant'], $rows);

        if (!$force) {
            $io->warning(sprintf('%d opération(s) rattachée(s) au relevé d\'un autre portefeuille. LECTURE SEULE : relancez avec --force pour les détacher.', count($rows)));

            return Command::SUCCESS;
        }

        foreach ($found as $operation) {
            $operation->setReleve(null); // remet aussi le rang à null
        }
        $this->em->flush();
        $io->success(sprintf('%d opération(s) détachée(s) : elles sont de nouveau « à pointer » dans leur portefeuille.', count($found)));

        return Command::SUCCESS;
    }
}
