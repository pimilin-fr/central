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
 * Relevés créés avant la gestion de l'ordre : leur donne un rang (dans l'ordre d'affichage actuel)
 * et les finalise, pour qu'ils s'affichent comme des relevés de compte.
 *
 * SANS DANGER : par défaut c'est une simulation (aucune écriture). Avec --force, seules les colonnes
 * releve_ordre (opérations) et closed_at (relevés) des relevés CONCERNÉS sont renseignées ; rien n'est supprimé.
 * Un relevé finalisé se rouvre depuis l'écran « Faire le relevé ».
 */
#[AsCommand(name: 'app:releve:finaliser-existants', description: 'Ordonne et finalise les relevés existants (simulation par défaut)')]
class ReleveFinaliserExistantsCommand extends Command {

    public function __construct(private readonly EntityManagerInterface $em) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
                ->addOption('force', null, InputOption::VALUE_NONE, 'Écrit réellement (sinon : simulation)')
                ->addOption('ordre', null, InputOption::VALUE_REQUIRED, 'Ordre à donner : desc (récent d\'abord, comme aujourd\'hui) ou asc', 'desc')
                ->addOption('portefeuille', 'p', InputOption::VALUE_REQUIRED, 'Se limiter à un portefeuille (identifiant)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $ordre = strtolower((string) $input->getOption('ordre'));
        if (!in_array($ordre, ['asc', 'desc'], true)) {
            $io->error('--ordre doit valoir asc ou desc');

            return Command::FAILURE;
        }

        $qb = $this->em->createQueryBuilder()->select('r')->from(Releve::class, 'r')
                ->where('r.closedAt IS NULL')->orderBy('r.date', 'ASC')->addOrderBy('r.id', 'ASC');
        if ($input->getOption('portefeuille')) {
            $qb->andWhere('r.portefeuille = :p')->setParameter('p', (int) $input->getOption('portefeuille'));
        }

        $rows = [];
        $now = new \DateTimeImmutable();
        foreach ($qb->getQuery()->getResult() as $releve) {
            /** @var Releve $releve */
            if ($releve->hasOrderedOperations()) {
                continue; // déjà ordonné via l'écran « Faire le relevé » : on n'y touche pas
            }
            $operations = $releve->getDepenses()->toArray();
            if ($operations === []) {
                continue;
            }
            usort($operations, static fn (Depenses $a, Depenses $b): int => $ordre === 'asc'
                            ? [$a->getDate(), $a->getId()] <=> [$b->getDate(), $b->getId()]
                            : [$b->getDate(), $b->getId()] <=> [$a->getDate(), $a->getId()]);

            foreach ($operations as $i => $operation) {
                $operation->setReleveOrdre($i + 1);
            }
            $releve->setClosedAt($now);
            $rows[] = [$releve->getId(), $releve->getPortefeuille()->getName(), $releve->getDate()->format('d/m/Y'), count($operations)];
        }

        $io->table(['Relevé', 'Portefeuille', 'Date', 'Opérations'], $rows);
        if ($rows === []) {
            $io->success('Aucun relevé ancien à traiter.');

            return Command::SUCCESS;
        }

        if (!$force) {
            $this->em->clear(); // simulation : rien n'est écrit
            $io->note(sprintf('SIMULATION : %d relevé(s) seraient ordonnés (%s) et finalisés. Relancez avec --force pour écrire.', count($rows), $ordre));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d relevé(s) ordonnés (%s) et finalisés.', count($rows), $ordre));

        return Command::SUCCESS;
    }
}
