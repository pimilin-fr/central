<?php

namespace App\Services;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\Releve;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class ReleveManager {

    private EntityManagerInterface $em;
    private $repoReleve;
    private $optionMultiplePtf;

    public function __construct(EntityManagerInterface $em, bool $allowMultiplePortefeuille = false) {
        $this->em = $em;
        $this->repoReleve = $em->getRepository(Releve::class);
        $this->optionMultiplePtf = $allowMultiplePortefeuille;
    }

    public function addOperations(DateTime $date, array $operations, bool $flush = true): Releve {
        if (empty($operations)) {
            throw new Exception('RM 01 - Aucune opération fournie');
        }

        /** @var Depenses $first */
        $first = $operations[0];
        $portefeuille = $first->getPortefeuille();

        // 🔍 1. récupérer ou créer relevé
        $releve = $this->findOrCreateReleve($date, $portefeuille);

        // 🔁 2. ajout des lignes
        foreach ($operations as $ligne) {
            if (
                    $ligne->getPortefeuille()->getId() !== $portefeuille->getId() && !$this->optionMultiplePtf
            ) {
                throw new Exception('RM 02 - Portefeuilles multiples interdits');
            }

            // 👉 lien
            if ($flush) {
                $ligne->setReleve($releve);
                $this->em->persist($ligne);
            }
        }

        if ($flush) {
            $this->em->persist($releve);
            $this->em->flush();
        } else {
            $collection = new ArrayCollection($operations);
            $releve->setDepenses($collection);
        }

        return $releve;
    }

    private function findOrCreateReleve(DateTime $date, Portefeuille $portefeuille): Releve {
        $releve = $this->repoReleve->findOneBy([
            'portefeuille' => $portefeuille,
            'date' => $date
        ]);

        if ($releve) {
            return $releve;
        }

        // 🔍 nouveau relevé
        $newreleve = new Releve();
        $newreleve->setDate($date)
                ->setPortefeuille($portefeuille)
                ->setLabel('relevé du ' . $date->format('dd/MM/YYYY'));
        return $newreleve;
    }
}
