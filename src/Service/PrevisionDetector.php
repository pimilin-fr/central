<?php

namespace App\Service;

use App\Entity\Depenses;
use App\Entity\PrevisionRegle;
use App\Entity\Tiers;
use App\Prevision\Certitude;
use App\Prevision\Frequence;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saisie assistée : repère, dans l'historique des opérations, ce qui revient régulièrement
 * (même tiers + catégorie + portefeuille, à intervalle régulier) et propose des règles prévisionnelles.
 * Lecture seule : ne modifie rien.
 */
class PrevisionDetector {

    /** Fenêtre d'analyse (mois). */
    public const FENETRE_MOIS = 24;
    /** Part minimale d'intervalles conformes au rythme pour parler de récurrence. */
    private const REGULARITE_MIN = 0.7;

    /** @var array<string, array{freq: Frequence, jours: float}> */
    private const RYTHMES = [
        'mensuelle' => ['freq' => Frequence::MENSUELLE, 'jours' => 30.44],
        'bimestrielle' => ['freq' => Frequence::BIMESTRIELLE, 'jours' => 60.88],
        'trimestrielle' => ['freq' => Frequence::TRIMESTRIELLE, 'jours' => 91.31],
        'semestrielle' => ['freq' => Frequence::SEMESTRIELLE, 'jours' => 182.62],
        'annuelle' => ['freq' => Frequence::ANNUELLE, 'jours' => 365.25],
    ];

    public function __construct(private readonly EntityManagerInterface $em) {
        
    }

    /**
     * Récurrences probables, non encore couvertes par une règle active, les plus solides d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function detect(?DateTimeInterface $today = null): array {
        $today = DateTimeImmutable::createFromInterface($today ?? new DateTimeImmutable('today'));
        $since = $today->modify('-' . self::FENETRE_MOIS . ' months');

        /** @var list<Depenses> $depenses */
        $depenses = $this->em->createQueryBuilder()
                ->select('d', 't', 'c', 'p', 'pr')
                ->from(Depenses::class, 'd')
                ->innerJoin('d.tiers', 't')
                ->innerJoin('d.categorie', 'c')
                ->innerJoin('d.portefeuille', 'p')
                ->leftJoin('d.projet', 'pr')
                ->andWhere('d.date >= :since')
                ->setParameter('since', $since)
                ->orderBy('d.date', 'ASC')
                ->getQuery()
                ->getResult();

        $couvertes = [];
        foreach ($this->em->getRepository(PrevisionRegle::class)->findBy(['actif' => true]) as $regle) {
            $couvertes[$this->key($regle->getTiers()?->getId(), $regle->getCategorie()?->getId(), $regle->getPortefeuille()?->getId())] = true;
        }

        $groups = [];
        foreach ($depenses as $d) {
            $key = $this->key($d->getTiers()->getId(), $d->getCategorie()->getId(), $d->getPortefeuille()?->getId());
            if (isset($couvertes[$key])) {
                continue;
            }
            $groups[$key][] = $d;
        }

        $out = [];
        foreach ($groups as $list) {
            $points = array_map(static fn (Depenses $d): array => ['date' => DateTimeImmutable::createFromInterface($d->getDate()), 'montant' => (float) $d->getMontant()], $list);
            $analyse = self::analyse($points, $today);
            if ($analyse === null) {
                continue;
            }
            $last = $list[array_key_last($list)];
            $out[] = $analyse + [
                'tiers' => $last->getTiers(),
                'categorie' => $last->getCategorie(),
                'portefeuille' => $last->getPortefeuille(),
                'projet' => $last->getProjet(),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $out;
    }

    /**
     * Pré-remplissage du formulaire dès qu'un tiers est choisi : sa dernière opération (catégorie, portefeuille,
     * projet, montant) et, si on le repère, son rythme.
     *
     * @return array<string, mixed>|null
     */
    public function profilTiers(Tiers $tiers, ?DateTimeInterface $today = null): ?array {
        $today = DateTimeImmutable::createFromInterface($today ?? new DateTimeImmutable('today'));
        /** @var list<Depenses> $depenses */
        $depenses = $this->em->createQueryBuilder()
                ->select('d', 'c', 'p', 'pr')
                ->from(Depenses::class, 'd')
                ->innerJoin('d.categorie', 'c')
                ->innerJoin('d.portefeuille', 'p')
                ->leftJoin('d.projet', 'pr')
                ->andWhere('d.tiers = :t')
                ->setParameter('t', $tiers)
                ->orderBy('d.date', 'DESC')
                ->setMaxResults(30)
                ->getQuery()
                ->getResult();
        if ($depenses === []) {
            return null;
        }

        $last = $depenses[0];
        $same = array_values(array_filter($depenses, static fn (Depenses $d): bool => $d->getCategorie()->getId() === $last->getCategorie()->getId() && $d->getPortefeuille()?->getId() === $last->getPortefeuille()?->getId()));
        $points = array_reverse(array_map(static fn (Depenses $d): array => ['date' => DateTimeImmutable::createFromInterface($d->getDate()), 'montant' => (float) $d->getMontant()], $same));
        $analyse = self::analyse($points, $today, true);

        return [
            'categorie' => ['id' => $last->getCategorie()->getId(), 'label' => $last->getCategorie()->getLibelle() ?: $last->getCategorie()->getName()],
            'portefeuille' => $last->getPortefeuille()?->getId(),
            'projet' => $last->getProjet() ? ['id' => $last->getProjet()->getId(), 'label' => $last->getProjet()->getName()] : null,
            'montant' => (float) $last->getMontant(),
            'jour' => (int) $last->getDate()->format('j'),
            'frequence' => $analyse['frequence']->value ?? null,
            'estime' => $analyse['estime'] ?? false,
            'min' => $analyse['min'] ?? null,
            'max' => $analyse['max'] ?? null,
            'resume' => $analyse['resume'] ?? null,
        ];
    }

    /**
     * Analyse pure d'une série d'opérations (ordre chronologique) : rythme, jour, montant, solidité.
     * Retourne null si rien de régulier.
     *
     * @param list<array{date: DateTimeImmutable, montant: float}> $points
     * @return array<string, mixed>|null
     */
    public static function analyse(array $points, DateTimeImmutable $today, bool $souple = false): ?array {
        $n = count($points);
        if ($n < 2) {
            return null;
        }
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $gaps[] = (float) $points[$i - 1]['date']->diff($points[$i]['date'])->days;
        }
        // achats fréquents (courses…) : pas une récurrence
        $courts = count(array_filter($gaps, static fn (float $g): bool => $g < 20));
        if ($courts / count($gaps) > 0.25) {
            return null;
        }

        $median = self::median($gaps);
        $best = null;
        foreach (self::RYTHMES as $r) {
            $ecart = abs($median - $r['jours']) / $r['jours'];
            if ($ecart <= 0.15 && ($best === null || $ecart < $best['ecart'])) {
                $best = $r + ['ecart' => $ecart];
            }
        }
        if ($best === null) {
            return null;
        }
        $f = $best['jours'];
        $conformes = count(array_filter($gaps, static fn (float $g): bool => abs($g - $f) / $f <= 0.2));
        $regularite = $conformes / count($gaps);
        // 2 occurrences seulement : on n'accepte que l'annuel ; moins de 3 sinon = trop peu d'indices
        if ($n < 3 && ($best['freq'] !== Frequence::ANNUELLE || $regularite < 1.0)) {
            return null;
        }
        if ($regularite < ($souple ? 0.5 : self::REGULARITE_MIN)) {
            return null;
        }

        $last = $points[$n - 1]['date'];
        $age = (float) $last->diff($today)->days;
        if (!$souple && $age > 1.6 * $f) {
            return null; // plus d'occurrence récente : terminée
        }

        $jours = array_map(static fn (array $p): int => (int) $p['date']->format('j'), $points);
        $jour = (int) round(self::median(array_map('floatval', $jours)));

        $montants = array_map(static fn (array $p): float => $p['montant'], $points);
        $derniers = array_slice($montants, -min(6, $n));
        $dernier = $montants[$n - 1];
        $recents3 = array_slice($montants, -min(3, $n));
        $stable = max($recents3) - min($recents3) <= 0.01 * max(0.01, $dernier);
        $changed = null;
        if ($stable) {
            for ($i = $n - 1; $i >= 0; $i--) {
                if (abs($montants[$i] - $dernier) > 0.01 * max(0.01, $dernier)) {
                    $changed = $points[$i + 1]['date'] ?? null;
                    break;
                }
            }
        }
        $estime = !$stable;
        $moyenne = round(array_sum($derniers) / count($derniers), 2);

        $certitude = match (true) {
            $estime => Certitude::PROBABLE,
            $regularite >= 0.9 && $n >= 6 => Certitude::CERTAIN,
            default => Certitude::PROBABLE,
        };

        $step = $best['freq']->mois();
        $next = $last->modify('first day of this month')->modify('+' . $step . ' months');
        $next = $next->setDate((int) $next->format('Y'), (int) $next->format('n'), min($jour, (int) $next->format('t')));

        $resume = sprintf('%d occurrence%s, %s autour du %d', $n, $n > 1 ? 's' : '', mb_strtolower($best['freq']->label()), $jour);
        $resume .= $estime ?
                sprintf(', montant variable (%s à %s €)', number_format(min($derniers), 2, ',', ' '), number_format(max($derniers), 2, ',', ' ')) :
                sprintf(', %s € constant', number_format($dernier, 2, ',', ' '));
        if ($changed !== null) {
            $resume .= ' depuis le ' . $changed->format('d/m/Y');
        }

        return [
            'frequence' => $best['freq'],
            'jour' => $jour,
            'montant' => $estime ? $moyenne : round($dernier, 2),
            'min' => $estime ? round(min($derniers), 2) : null,
            'max' => $estime ? round(max($derniers), 2) : null,
            'estime' => $estime,
            'certitude' => $certitude,
            'occurrences' => $n,
            'regularite' => round($regularite, 2),
            'derniere' => $last,
            'prochaine' => $next,
            'score' => round($regularite * 0.6 + min($n, 12) / 12 * 0.4, 3),
            'resume' => $resume,
        ];
    }

    /** @param list<float> $values */
    private static function median(array $values): float {
        sort($values);
        $c = count($values);
        $mid = intdiv($c, 2);

        return $c % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private function key(mixed $tiers, mixed $categorie, mixed $portefeuille): string {
        return $tiers . '|' . $categorie . '|' . $portefeuille;
    }
}
