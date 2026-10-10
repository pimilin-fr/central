<?php

namespace App\Service;

use App\Entity\Depenses;
use App\Entity\PrevisionEcheance;
use App\Entity\PrevisionRegle;
use App\Entity\PrevisionTranche;
use App\Prevision\StatutEcheance;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prévisions : calcule les échéances d'une règle, les synchronise en base et les « concrétise »
 * en vraies opérations. Aucune opération existante n'est jamais modifiée.
 */
class PrevisionManager {

    /** Horizon de génération pour les montants connus (mois). */
    public const HORIZON_MOIS = 12;

    public function __construct(private readonly EntityManagerInterface $em) {
        
    }

    /**
     * Occurrences théoriques d'une règle à partir du 1er du mois courant (les plus anciennes sont de la réalité,
     * pas de la prévision). Pure : ne touche pas à la base.
     *
     * @return list<array{rang: int, date: DateTimeImmutable, dateFin: ?DateTimeImmutable, tranche: PrevisionTranche}>
     */
    public function occurrences(PrevisionRegle $regle, DateTimeInterface $today): array {
        $debut = $regle->getDebut();
        if ($debut === null || $regle->getTranches()->isEmpty()) {
            return [];
        }
        $today = DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
        $debut = DateTimeImmutable::createFromInterface($debut)->setTime(0, 0);
        $fin = $regle->getFin() ? DateTimeImmutable::createFromInterface($regle->getFin())->setTime(0, 0) : null;

        $debutMois = $today->modify('first day of this month');
        $horizon = $regle->isEstime() ?
                $today->modify('last day of this month') :
                $today->modify('+' . self::HORIZON_MOIS . ' months')->modify('last day of this month');

        $anchor = $debut->modify('first day of this month');
        if ($this->clamp($anchor, $regle->getJour()) < $debut) {
            $anchor = $anchor->modify('+1 month');
        }
        $step = $regle->getFrequence()->mois();

        $out = [];
        for ($n = 0; $n < 600; $n++) {
            if ($regle->getNbEcheances() !== null && $n >= $regle->getNbEcheances()) {
                break;
            }
            $month = $anchor->modify('+' . ($n * $step) . ' months');
            $date = $this->clamp($month, $regle->getJour());
            if ($fin !== null && $date > $fin) {
                break;
            }
            if ($date > $horizon) {
                break;
            }
            if ($date < $debutMois) {
                continue;
            }
            $dateFin = null;
            if ($regle->getJourFin() !== null && $regle->getJourFin() > $regle->getJour()) {
                $dateFin = $this->clamp($month, $regle->getJourFin());
            }
            $out[] = ['rang' => $n, 'date' => $date, 'dateFin' => $dateFin, 'tranche' => $this->trancheAt($regle, $date)];
        }

        return $out;
    }

    /** Tranche applicable à une date : la dernière commencée (sinon la première). */
    public function trancheAt(PrevisionRegle $regle, DateTimeInterface $date): PrevisionTranche {
        $tranches = $regle->getTranches()->toArray();
        usort($tranches, static fn (PrevisionTranche $a, PrevisionTranche $b) => $a->getAPartirDe() <=> $b->getAPartirDe());
        $found = $tranches[0];
        foreach ($tranches as $tranche) {
            if ($tranche->getAPartirDe() !== null && $tranche->getAPartirDe() <= $date) {
                $found = $tranche;
            }
        }

        return $found;
    }

    /**
     * Crée / met à jour / retire les échéances encore « prévues » et non ajustées à la main.
     *
     * @return array{creees: int, maj: int, retirees: int}
     */
    public function synchroniser(PrevisionRegle $regle, ?DateTimeInterface $today = null): array {
        $today ??= new DateTimeImmutable('today');
        $stats = ['creees' => 0, 'maj' => 0, 'retirees' => 0];
        if (!$regle->isActif()) {
            return $stats;
        }

        $existantes = [];
        foreach ($regle->getEcheances() as $echeance) {
            $existantes[$echeance->getRang()] = $echeance;
        }

        $vus = [];
        foreach ($this->occurrences($regle, $today) as $occ) {
            $vus[$occ['rang']] = true;
            $echeance = $existantes[$occ['rang']] ?? null;
            if ($echeance === null) {
                $echeance = (new PrevisionEcheance())->setRegle($regle)->setRang($occ['rang']);
                $this->hydrate($echeance, $regle, $occ);
                $this->em->persist($echeance);
                $stats['creees']++;
            } elseif ($echeance->isPrevue() && !$echeance->isAjustee()) {
                $this->hydrate($echeance, $regle, $occ);
                $stats['maj']++;
            }
        }

        $debutMois = DateTimeImmutable::createFromInterface($today)->modify('first day of this month')->setTime(0, 0);
        foreach ($existantes as $rang => $echeance) {
            if (isset($vus[$rang]) || !$echeance->isPrevue() || $echeance->isAjustee()) {
                continue;
            }
            if ($echeance->getDatePrevue() >= $debutMois) { // ne retire que du futur : le passé est de la réalité
                $this->em->remove($echeance);
                $stats['retirees']++;
            }
        }

        return $stats;
    }

    /**
     * Transforme une échéance en vraie opération (pré-remplie), puis la marque réalisée.
     * Les valeurs réelles (date, montant) sont celles saisies par la personne.
     */
    public function concretiser(PrevisionEcheance $echeance, DateTimeInterface $date, string|float $montant): Depenses {
        if (!$echeance->isPrevue()) {
            throw new \RuntimeException('Cette échéance est déjà traitée.');
        }
        $regle = $echeance->getRegle();
        $depense = (new Depenses())
                ->setDate(DateTime::createFromInterface($date))
                ->setMontant(number_format(abs((float) $montant), 2, '.', ''))
                ->setCategorie($regle->getCategorie())
                ->setTiers($regle->getTiers())
                ->setPortefeuille($regle->getPortefeuille())
                ->setProjet($regle->getProjet())
                ->setNote('Prévision : ' . $regle->getLibelle());
        $this->em->persist($depense);

        $echeance->setStatut(StatutEcheance::REALISEE)->setDepense($depense);

        return $depense;
    }

    /** Moyenne des N dernières opérations du même tiers (aide à estimer un montant variable), ou null. */
    public function estimation(PrevisionRegle $regle, int $n = 6): ?float {
        if ($regle->getTiers() === null) {
            return null;
        }
        $rows = $this->em->createQueryBuilder()
                ->select('d.montant')
                ->from(Depenses::class, 'd')
                ->andWhere('d.tiers = :t')
                ->setParameter('t', $regle->getTiers())
                ->orderBy('d.date', 'DESC')
                ->setMaxResults($n)
                ->getQuery()
                ->getSingleColumnResult();
        if ($rows === []) {
            return null;
        }

        return round(array_sum(array_map('floatval', $rows)) / count($rows), 2);
    }

    /** @param array{date: DateTimeImmutable, dateFin: ?DateTimeImmutable, tranche: PrevisionTranche} $occ */
    private function hydrate(PrevisionEcheance $echeance, PrevisionRegle $regle, array $occ): void {
        $tranche = $occ['tranche'];
        $echeance->setDatePrevue(DateTime::createFromImmutable($occ['date']))
                ->setDateFin($occ['dateFin'] ? DateTime::createFromImmutable($occ['dateFin']) : null)
                ->setMontant($tranche->getMontant())
                ->setMontantMin($tranche->getMontantMin())
                ->setMontantMax($tranche->getMontantMax())
                ->setCertitude($regle->getCertitude());
    }

    /** Jour demandé dans le mois de $month, borné à la fin du mois (31 → 28/29/30). */
    private function clamp(DateTimeImmutable $month, int $jour): DateTimeImmutable {
        $first = $month->modify('first day of this month');

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($jour, (int) $first->format('t')));
    }
}
