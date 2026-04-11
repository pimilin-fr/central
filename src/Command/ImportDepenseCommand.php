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
            name: 'app:import-depense',
            description: 'Import des dépenses depuis un CSV'
    )]
class ImportDepenseCommand extends Command {

    public function __construct(
            private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
                ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $output->writeln('<error>Fichier introuvable !</error>');
            return Command::FAILURE;
        }
        $projetRepo = $this->em->getRepository(\App\Entity\Projet::class);
        $ptfRepo = $this->em->getRepository(\App\Entity\Portefeuille::class);
        $categRepo = $this->em->getRepository(\App\Entity\Categorie::class);
        $tiersRepo = $this->em->getRepository(\App\Entity\Tiers::class);
        $projets = [];
        $categories = [];
        $tiers = [];
        $passed = 0;

        $comptes = [
            "Banque" => $ptfRepo->findOneBy(['type' => "Banque", "isDefault" => true]),
            "Espèce" => $ptfRepo->findOneBy(['type' => "Banque", "isDefault" => false]),
            "Héritage" => $ptfRepo->findOneBy(['type' => "Espèce", "isDefault" => true])
        ];
//        $banque = $this->em->getRepository(\App\Entity\Portefeuille::class)
//                ->findOneBy(['type' => "Banque", "isDefault" => true]);
//        $heritage = $this->em->getRepository(\App\Entity\Portefeuille::class)
//                ->findOneBy(['type' => "Banque", "isDefault" => false]);
//        $esp = $this->em->getRepository(\App\Entity\Portefeuille::class)
//                ->findOneBy(['type' => "Espèce", "isDefault" => true]);

        /** ----------------------------
         * 2️⃣ Lecture CSV
         * ---------------------------- */
        $csv = Reader::createFromPath($file, 'r');
        $csv->setHeaderOffset(0);

        foreach ($csv as $row) {
            $depenses = new \App\Entity\Depenses();
            $date = \DateTime::createFromFormat('d/m/Y', $row['Date']);

            if (!$date) {
                $passed++;
                continue;
//                var_dump($row['Date'],$row);die;
            }
            $categorieLabel = $row['Categorie'];
            if (!isset($categories[$categorieLabel])) {
                $parts = array_map('trim', explode('/', $categorieLabel));
                $niveau3 = end($parts);
                $categorie = $categRepo->findOneBy(['name' => $niveau3]);
                if (!$categorie) {
                    var_dump("Cat : " . $categorieLabel, $row);
                    die;
                }
                $categories[$categorieLabel] = $categorie;
            }
            $tiersLabel = $row['Tiers'];
            if (!isset($tiers[$tiersLabel])) {
                $tempTiers = $tiersRepo->findOneBy(["searchText" => $tiersLabel]);
                $tiers[$tiersLabel] = $tempTiers;
                if (!$tempTiers) {
                    var_dump("Tiers : " . $tiersLabel, $row);
                    die;
                }
            }
            $montant = (float) str_replace(
                            ',', '.',
                            preg_replace('/\s/u', '', str_replace('€', '', $row['Montant']))
                    );

//            var_dump($montant);
            $depenses->setDate(\DateTime::createFromFormat('d/m/Y', $row['Date']))
                    ->setMontant($montant)
                    ->setNumCommande($row['Num_Commande'])
                    ->setEtat($row['Etat'])
                    ->setNote($row['Notes'])
                    ->setPortefeuille($comptes[$row['Portefeuille']])
                    ->setCategorie($categories[$categorieLabel])
                    ->setTiers($tiers[$tiersLabel]);

            if ("" != $row['Date paiement']) {
                $depenses->setDatePaiement(\DateTime::createFromFormat('d/m/Y', $row['Date paiement']));
            }
            if ("" != $row['Relevé']) {
                $depenses->setDateReleve(\DateTime::createFromFormat('d/m/Y', $row['Date paiement']));
            }

            if ('' != $row['Projet']) {
                if (!isset($projets[$row['Projet']])) {
                    $projet = $projetRepo->findOneBy(['name' => $row["Projet"]]);
                    $projets[$row['Projet']] = $projet;
                }
                $depenses->setProjet($projets[$row['Projet']]);
            }
            switch ($row['Fonctionnement']) {
                case "Dépense & Revenu":
                case "Rembroucement Emprunt":
                    break;
                case "Transfert & Retraits":
                    $clone = new \App\Entity\Depenses();
                    $clone->setDate(\DateTime::createFromFormat('d/m/Y', $row['Date']))
                            ->setNumCommande($row['Num_Commande'])
                            ->setEtat($row['Etat'])
                            ->setNote($row['Notes'])
                            ->setPortefeuille($comptes[$row['Portefeuille']])
                            ->setCategorie($categories[$categorieLabel])
                            ->setTiers($tiers[$tiersLabel])
                            ->setProjet($depenses->getProjet())
                            ->setDatePaiement($depenses->getDatePaiement())
                            ->setDateReleve($depenses->getDateReleve())
                            ->setPortefeuille($comptes["Espèce"])
                            ->setMontant(-$montant);
                    $this->em->persist($clone);
                    break;
                case "Annule Dépense Rembourcement":
                    $depenses->setMontant(-$montant);
                    break;
                default :
                    var_dump($row['Fonctionnement']);
                    die;
                    break;
            }

            $this->em->persist($depenses);
//                    ->set
        }

        $this->em->flush();

        $output->writeln('<info>Import terminé ✔ (sans doublons)</info>');

        return Command::SUCCESS;
    }
}
