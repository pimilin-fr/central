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
 * Un relevé = un portefeuille + une date + une liste ORDONNÉE de lignes (Depenses::releveOrdre,
 * 1 = première ligne du relevé de compte ; plusieurs opérations de même rang = un détail de cette ligne). Il se fait en plusieurs fois : tant qu'il n'est pas
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

        // sécurité : seules les opérations DU portefeuille du relevé comptent (voir foreignOperations)
        $operations = array_values(array_filter(
                $releve->getDepenses()->toArray(),
                fn (Depenses $d): bool => $this->belongs($releve, $d)
        ));
        usort($operations, static function (Depenses $a, Depenses $b): int {
            return [$a->getReleveOrdre() ?? PHP_INT_MAX, $a->getDate(), $a->getId()]
                    <=> [$b->getReleveOrdre() ?? PHP_INT_MAX, $b->getDate(), $b->getId()];
        });

        return $operations;
    }

    private function belongs(Releve $releve, Depenses $operation): bool {
        return $operation->getPortefeuille()?->getId() === $releve->getPortefeuille()->getId();
    }

    /**
     * Opérations rattachées au relevé mais appartenant à UN AUTRE portefeuille (données incohérentes,
     * ex. anciens relevés partagés entre comptes). Elles ne sont jamais affichées ni modifiées ici.
     *
     * @return list<Depenses>
     */
    public function foreignOperations(Releve $releve): array {
        if ($releve->getId() === null) {
            return [];
        }

        return array_values(array_filter(
                $releve->getDepenses()->toArray(),
                fn (Depenses $d): bool => !$this->belongs($releve, $d)
        ));
    }

    /** Montant signé d'une opération : dépense négative, revenu positif (un remboursement inverse le signe). */
    public static function signed(Depenses $operation): float {
        $amount = (float) $operation->getMontant();

        return $operation->getCategorie()->isDepense() ? -$amount : $amount;
    }

    /**
     * Résumé de relevés (à passer par ordre CHRONOLOGIQUE) : mouvement du relevé, nombre de lignes / opérations
     * et solde cumulé (somme des mouvements de tous les relevés jusqu'à celui-ci inclus).
     *
     * @param iterable<Releve> $relevesAsc
     * @return list<array{releve: Releve, total: float, ops: int, lines: int, cumul: float}>
     */
    public function summarize(iterable $relevesAsc): array {
        $rows = [];
        $cumul = 0.0;
        foreach ($relevesAsc as $releve) {
            $operations = $this->orderedOperations($releve);
            $total = 0.0;
            foreach ($operations as $operation) {
                $total += self::signed($operation);
            }
            $cumul += $total;
            $rows[] = [
                'releve' => $releve,
                'total' => round($total, 2),
                'ops' => count($operations),
                'lines' => count($this->orderedLines($releve)),
                'cumul' => round($cumul, 2),
            ];
        }

        return $rows;
    }

    /**
     * Repères pour composer un relevé à une date : le relevé précédent (mouvement) et le solde cumulé AVANT lui.
     *
     * @return array{previous: ?array{releve: Releve, total: float, ops: int, lines: int, cumul: float}, cumulBefore: float}
     */
    public function history(Releve $current): array {
        $before = $this->repoReleve->findBefore($current->getPortefeuille(), $current->getDate(), $current->getId());
        $rows = $this->summarize($before);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return ['previous' => $last, 'cumulBefore' => $last['cumul'] ?? 0.0];
    }

    /**
     * Lignes du relevé de compte, dans l'ordre. Une ligne = une opération, ou un « détail » :
     * plusieurs opérations (même date ; tiers libres) qui partagent le même rang parce qu'elles
     * se pointent ensemble (ex. une commande de 50 € éclatée en 10 € vêtements + 25 € sport + …).
     *
     * @return list<list<Depenses>>
     */
    public function orderedLines(Releve $releve): array {
        $lines = [];
        $byRang = [];
        foreach ($this->orderedOperations($releve) as $operation) {
            $rang = $operation->getReleveOrdre();
            if ($rang === null) {
                $lines[] = [$operation]; // relevé ancien : une ligne par opération
            } elseif (isset($byRang[$rang])) {
                $lines[$byRang[$rang]][] = $operation;
            } else {
                $byRang[$rang] = count($lines);
                $lines[] = [$operation];
            }
        }

        return $lines;
    }

    /**
     * Enregistre les LIGNES ordonnées du relevé (rangs 1..n sans trou ; les opérations d'un même
     * détail ont le même rang). Les opérations qui étaient dans le relevé et n'y sont plus en sortent.
     *
     * @param list<Depenses|list<Depenses>> $lines une opération seule, ou une liste d'opérations (= un détail)
     */
    public function compose(Releve $releve, array $lines): Releve {
        if ($releve->isClosed()) {
            throw new Exception('RM 03 - Relevé finalisé : rouvrez-le pour le modifier');
        }

        $lines = array_values(array_map(static fn ($line): array => $line instanceof Depenses ? [$line] : array_values($line), $lines));
        $lines = array_values(array_filter($lines, static fn (array $line): bool => $line !== []));

        $portefeuille = $releve->getPortefeuille();
        $seen = [];
        foreach ($lines as $line) {
            foreach ($line as $operation) {
                if (isset($seen[$operation->getId()])) {
                    throw new Exception('RM 04 - Opération en double');
                }
                $seen[$operation->getId()] = true;

                if ($operation->getPortefeuille()?->getId() !== $portefeuille->getId() && !$this->optionMultiplePtf) {
                    throw new Exception('RM 02 - Portefeuilles multiples interdits');
                }
                $other = $operation->getReleve();
                if ($other !== null && ($releve->getId() === null || $other->getId() !== $releve->getId())) {
                    throw new Exception('RM 05 - Une opération appartient déjà à un autre relevé');
                }
            }

            // un détail regroupe des opérations d'une même date (les tiers peuvent différer : cas rare mais réel)
            if (count($line) > 1) {
                $day = $line[0]->getDate()->format('Y-m-d');
                foreach ($line as $operation) {
                    if ($operation->getDate()->format('Y-m-d') !== $day) {
                        throw new Exception('RM 07 - Un détail ne regroupe que des opérations de la même date');
                    }
                }
            }
        }

        // retire du relevé les opérations qui n'y sont plus
        if ($releve->getId() !== null) {
            foreach ($releve->getDepenses() as $current) {
                if (!$this->belongs($releve, $current)) {
                    continue; // jamais touche aux opérations d'un autre portefeuille
                }
                if (!isset($seen[$current->getId()])) {
                    $current->setReleve(null); // remet aussi le rang à null
                }
            }
        }

        $this->em->persist($releve);
        foreach ($lines as $index => $line) {
            foreach ($line as $operation) {
                $operation->setReleve($releve)->setReleveOrdre($index + 1);
            }
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

        // rang sans trou (les opérations d'un même détail gardent le même rang)
        foreach ($this->orderedLines($releve) as $index => $line) {
            foreach ($line as $operation) {
                $operation->setReleveOrdre($index + 1);
            }
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

        $lines = $this->orderedLines($releve);
        $known = [];
        foreach ($lines as $line) {
            foreach ($line as $operation) {
                $known[$operation->getId()] = true;
            }
        }
        foreach ($operations as $operation) {
            if (!isset($known[$operation->getId()])) {
                $lines[] = [$operation];
            }
        }

        return $this->compose($releve, $lines);
    }
}
