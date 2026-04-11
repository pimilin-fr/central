<?php

namespace App\Command;

use App\Entity\Adresse;
use App\Entity\AdresseType;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
            name: 'app:import-adresse',
            description: 'Add a short description for your command',
    )]
class ImportAdresseCommand extends Command {

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em) {
        parent::__construct();
        $this->em = $em;
    }

    protected function configure(): void {
        $this
                ->setDescription('Import des adresses depuis un CSV')
                ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $output->writeln('<error>Fichier introuvable !</error>');
            return Command::FAILURE;
        }

        $csv = Reader::createFromPath($file, 'r');
        $csv->setHeaderOffset(0); // première ligne = header

        $adresseLookup = []; // clé = name, valeur = entité Adresse

        foreach ($csv as $row) {
            $adresse = new Adresse();

            $adresse->setName($row['Nom Adresse'])
                    ->setAdresse($row['Adresse'])
                    ->setPrefix($row['Préfix'])
                    ->setNum(intval($row['Num']))
                    ->setBisTer($row['BisTer'])
                    ->setTypeVoie($row['TypeRue'])
                    ->setNomVoie($row['Nom Rue'])
                    ->setCodePostal($row['CodePostal'])
                    ->setVille($row['Ville'])
                    ->setCedex($row['Cedex'])
                    ->setPays($row['Pays'])
                    ->setAdresseForcee($row['AdresseForcee'])
                    ->setAdresseExact($row['AdresseExacte'])
                    ->setAdresseGeo($row['AdresseGeo']);
//            $adresse->setRue($row['rue'] ?? null)
            // ===== Gestion de l'adresseType =====
            $typeName = trim($row['adresse_type'] ?? '');

            $type = $this->em->getRepository(AdresseType::class)
                    ->findOneBy(['name' => $row['Colonne 3']]);
            if (!$type) {
                $type = new AdresseType();
                $type->setName($typeName);
                $type->setColor('#000000'); // couleur par défaut
                $this->em->persist($type);
            }
            $adresse->setAdresseType($type);

            $this->em->persist($adresse);

            $adresseLookup[$row['Nom Adresse']] = $adresse;
        }

        // Une seule flush à la fin pour optimiser
        $this->em->flush();
        foreach ($csv as $row) {
            $child = $adresseLookup[$row['Nom Adresse']];
            $parentName = trim($row['Parent'] ?? '');

            if ($parentName && isset($adresseLookup[$parentName])) {
                $child->setAdresseParent($adresseLookup[$parentName]);
            }
        }
        $this->em->flush();

        $output->writeln('<info>Import terminé ✅</info>');

        return Command::SUCCESS;
    }
}
