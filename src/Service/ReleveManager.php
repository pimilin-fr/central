<?php

namespace App\Service;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\Releve;
use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

/**
 * Relevés de compte.
 *
 * Un relevé = un portefeuille + une date + une liste ORDONNÉE d'opérations (Depenses::releveOrdre,
 * 1 = première ligne du relevé de compte). Il se fait en plusieurs fois : tant qu'il n'est pas
 * finalisé (Releve::closedAt), on peut ajouter, retirer et réordonner. Une fois finalisé, l'ordre
 * est figé et c'est toujours celui qui est affiché.
 */
class ReleveManager {

    private EntityManagerInterface $em;
    private $repoReleve;
    private $optionMultiplePtf;

    public function __construct(EntityManagerInterface $em, bool $allowMultiplePortefeuille = false) {
        $this->em = $em;
        $this->repoReleve = $em->getRepository(Releve::class);
        $this->optionMultiplePtf = $allowMultiplePortefeuille;
    }

    /** Relevé existant de ce portefeuille à cette date, ou nouveau relevé (non persisté). */
    public function findOrCreate(Portefeuille $portefeuille, DateTime $date): Releve {
        $releve = $this->repoReleve->findOneBy([
            'portefeuille' => $portefeuille,
            'date' => $date
        ]);

        if ($releve) {
            return $releve;
        }

        return (new Releve())
                        ->setDate($date)
                        ->setPortefeuille($portefeuille)
                        ->setLabel('Relevé du ' . $date->format('d/m/Y'));
    }

    /**
     * Opérations du relevé dans l'ordre : rang croissant ; les opérations sans rang
     * (relevé ancien) suivent, par date puis identifiant.
     *
     * @return list<Depenses>
     */
    public function orderedOperations(Releve $releve): array {
        if ($releve->getId() === null) {
            return [];
        }

        $operations = $releve->getDepenses()->toArray();
        usort($operations, static function (Depenses $a, Depenses $b): int {
            return [$a->getReleveOrdre() ?? PHP_INT_MAX, $a->getDate(), $a->getId()]
                    <=> [$b->getReleveOrdre() ?? PHP_INT_MAX, $b->getDate(), $b->getId()];
        });

        return $operations;
    }

    /**
     * Enregistre la liste ORDONNÉE des opérations du relevé (rangs 1..n, sans trou).
     * Les opérations qui étaient dans le relevé et n'y sont plus en sortent.
     *
     * @param list<Depenses> $ordered
     */
    public function compose(Releve $releve, array $ordered): Releve {
        if ($releve->isClosed()) {
            throw new Exception('RM 03 - Relevé finalisé : rouvrez-le pour le modifier');
        }

        $portefeuille = $releve->getPortefeuille();
        $seen = [];
        foreach ($ordered as $operation) {
            if (isset($seen[$operation->getId()])) {
                throw new Exception('RM 04 - Opération en double');
            }
            $seen[$operation->getId()] = true;

            if ($operation->getPortefeuille()?->getId() !== $portefeuille->getId() && !$this->optionMultiplePtf) {
                throw new Exception('RM 02 - Portefeuilles multiples interdits');
            }
            $other = $operation->getReleve();
            if ($other !== null && $releve->getId() !== null && $other->getId() !== $releve->getId()) {
                throw new Exception('RM 05 - Une opération appartient déjà à un autre relevé');
            }
            if ($other !== null && $releve->getId() === null) {
                throw new Exception('RM 05 - Une opération appartient déjà à un autre relevé');
            }
        }

        // retire du relevé les opérations qui n'y sont plus
        if ($releve->getId() !== null) {
            foreach ($releve->getDepenses() as $current) {
                if (!isset($seen[$current->getId()])) {
                    $current->setReleve(null); // remet aussi le rang à null
                }
            }
        }

        $this->em->persist($releve);
        foreach (array_values($ordered) as $index => $operation) {
            $operation->setReleve($releve)->setReleveOrdre($index + 1);
        }
        $this->em->flush();
        $this->em->refresh($releve);

        return $releve;
    }

    /** Finalise : l'ordre des opérations est figé. */
    public function finalize(Releve $releve): void {
        if ($releve->isClosed()) {
            return;
        }
        if ($releve->getId() === null || $releve->getDepenses()->isEmpty()) {
            throw new Exception('RM 06 - Un relevé vide ne peut pas être finalisé');
        }

        // rang sans trou, au cas où des opérations auraient été supprimées entre-temps
        foreach ($this->orderedOperations($releve) as $index => $operation) {
            $operation->setReleveOrdre($index + 1);
        }
        $releve->setClosedAt(new DateTimeImmutable());
        $this->em->flush();
    }

    /** Rouvre un relevé finalisé (correction). Les rangs sont conservés. */
    public function reopen(Releve $releve): void {
        $releve->setClosedAt(null);
        $this->em->flush();
    }

    /**
     * Ajoute des opérations à la fin du relevé de cette date (créé si besoin), dans l'ordre fourni.
     * Les opérations déjà dans ce relevé gardent leur place.
     *
     * @param list<Depenses> $operations
     */
    public function addOperations(DateTime $date, array $operations, bool $flush = true): Releve {
        if (empty($operations)) {
            throw new Exception('RM 01 - Aucune opération fournie');
        }

        /** @var Depenses $first */
        $first = $operations[0];
        $releve = $this->findOrCreate($first->getPortefeuille(), $date);

        if (!$flush) { // simulation (aperçu)
            return $releve->setDepenses(new ArrayCollection($operations));
        }

        $ordered = $this->orderedOperations($releve);
        $known = array_flip(array_map(static fn (Depenses $d) => $d->getId(), $ordered));
        foreach ($operations as $operation) {
            if (!isset($known[$operation->getId()])) {
                $ordered[] = $operation;
            }
        }

        return $this->compose($releve, $ordered);
    }
}
