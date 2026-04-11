<?php

namespace App\Command;

use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:import-categories',
    description: 'Import des catégories depuis un CSV'
)]
class ImportCategorieCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $output->writeln('<error>Fichier introuvable !</error>');
            return Command::FAILURE;
        }

        /** ----------------------------
         * 1️⃣ Cache des catégories existantes
         * ---------------------------- */
        $cache = [];

        $existing = $this->em->getRepository(Categorie::class)->findAll();
        foreach ($existing as $cat) {
            $parentKey = $cat->getParent()
                ? $cat->getParent()->getId()
                : 'ROOT';

            $key = $parentKey . '|' . mb_strtolower($cat->getName());
            $cache[$key] = $cat;
        }

        /** ----------------------------
         * 2️⃣ Lecture CSV
         * ---------------------------- */
        $csv = Reader::createFromPath($file, 'r');
        $csv->setHeaderOffset(0);

        foreach ($csv as $row) {
            $parts = array_values(array_filter(array_map(
                fn ($p) => trim($p),
                explode('/', $row['Categorie'])
            )));

            $parent = null;

            foreach ($parts as $label) {
                $parentKey = $parent ? $parent->getId() : 'ROOT';
                $cacheKey = $parentKey . '|' . mb_strtolower($label);

                if (!isset($cache[$cacheKey])) {
                    $categorie = (new Categorie())
                        ->setName($label)
                        ->setLibelle($label)
                        ->setParent($parent);

                    $this->em->persist($categorie);
                    $cache[$cacheKey] = $categorie;
                }

                $parent = $cache[$cacheKey];
            }

            /** ----------------------------
             * 3️⃣ Nature uniquement sur la feuille
             * ---------------------------- */
            if ($parent && $parent->getNature() === null) {
                $parent
                    ->setNature($row['Nature'])
                    ->setNatureCode($row['NatureID']);
            }
        }

        $this->em->flush();

        $output->writeln('<info>Import terminé ✔ (sans doublons)</info>');

        return Command::SUCCESS;
    }
}
