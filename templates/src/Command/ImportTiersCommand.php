<?php

namespace App\Command;

use App\Entity\Adresse;
use App\Entity\Tiers;
use App\Entity\TiersAdresse;
use App\Entity\TypeTiers;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
            name: 'app:import-tiers',
            description: 'Add a short description for your command',
    )]
class ImportTiersCommand extends Command {

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em) {
        parent::__construct();
        $this->em = $em;
    }

    protected function configure(): void {
        $this
                ->setDescription('Import des adresses depuis un CSV')
                ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier CSV');
        ;
    }

    private function parseDate(?string $value): DateTimeImmutable {
        if (!$value || trim($value) === '') {
            return new DateTimeImmutable(); // fallback
        }

        $value = trim($value);

        // 1️⃣ Excel / Google Sheets (nombre de jours)
        if (is_numeric($value)) {
            return (new DateTimeImmutable('1899-12-30'))
                            ->modify("+{$value} days");
        }

        // 2️⃣ Formats explicites
        $formats = [
            'Y-m-d',
            'd/m/Y',
            'm/d/Y',
            'd-m-Y',
            'Y/m/d',
            'd-m-y',
            'd/m/y',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        // 3️⃣ Dernier recours (strtotime)
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return (new DateTimeImmutable())->setTimestamp($timestamp);
        }

        // 4️⃣ Ultime fallback
        return new DateTimeImmutable();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $output->writeln('<error>Fichier introuvable !</error>');
            return Command::FAILURE;
        }

        $csv = Reader::createFromPath($file, 'r');
        $csv->setHeaderOffset(0); // première ligne = header

        $types = [];

        foreach ($csv as $row) {
            $tiers = new Tiers();

            $tiers->setName($row['Nom'])
                    ->setSearchText($row['TexteRecherche'])
                    ->setCreatedAt($this->parseDate($row['DateCreation']));

            if ($row['DateCloture'] != "") {
                $tiers->setDeletedAt($this->parseDate($row['DateCloture']));
            }
            
            $str = "<info> Type '".$row['Type']."' -";
            
            if (!isset($types[$row['Type']])) {
                $str.=" NF ";
                $tiersType = new TypeTiers();
//                var_dump($row['Type']);die;
                $tiersType->setName($row['Type']);
                $tiersType->computeFields();
                $this->em->persist($tiersType);
                $types[$row['Type']] = $tiersType;
            } else {
                $str.=" OK ";
                $tiersType = $types[$row['Type']];
            }
             $output->writeln($str.'</info>');
            
            $adresse = $this->em->getRepository(Adresse::class)
                    ->findOneBy(['name' => $row['Adresse']]);
            if (!$adresse) {
                throw new Exception('adresse not found :' . $row["Adresse"]);
            }

            $tiersAdresse = new TiersAdresse();
            $tiersAdresse->setTiers($tiers)
                    ->setAdresse($adresse)
                    ->setIsPrincipale(true);

            $tiers->setTiersType($tiersType);
            $tiers->regenerateCode();
            $this->em->persist($tiers);
            $this->em->persist($tiersAdresse);
        }
        $this->em->flush();

        $output->writeln('<info>Import terminé ✅</info>');

        return Command::SUCCESS;
    }
}
