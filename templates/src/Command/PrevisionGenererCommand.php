<?php

namespace App\Command;

use App\Repository\PrevisionRegleRepository;
use App\Service\PrevisionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:prevision:generer', description: 'Génère les échéances prévisionnelles des règles actives (simulation par défaut)')]
final class PrevisionGenererCommand extends Command {

    public function __construct(
            private readonly PrevisionRegleRepository $regleRepo,
            private readonly PrevisionManager $manager,
            private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Écrit réellement en base (sinon : simulation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $total = ['creees' => 0, 'maj' => 0, 'retirees' => 0];

        foreach ($this->regleRepo->findBy(['actif' => true]) as $regle) {
            $s = $this->manager->synchroniser($regle);
            foreach ($s as $k => $v) {
                $total[$k] += $v;
            }
            $io->writeln(sprintf('%s : +%d, ~%d, -%d', $regle->getLibelle(), $s['creees'], $s['maj'], $s['retirees']));
        }

        if ($force) {
            $this->em->flush();
            $io->success(sprintf('%d créée(s), %d mise(s) à jour, %d retirée(s).', $total['creees'], $total['maj'], $total['retirees']));
        } else {
            $this->em->clear(); // rien n'est écrit
            $io->note('Simulation : relancez avec --force pour écrire en base.');
        }

        return Command::SUCCESS;
    }
}
