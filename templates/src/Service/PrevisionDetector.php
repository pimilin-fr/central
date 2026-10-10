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
        return $this->diagnostic($today)['suggestions'];
    }

    /**
     * Détection + explication de ce qui a été écarté (« non retenues », avec la raison).
     * Passes successives : (1) tiers + catégorie + portefeuille ; (2) flux distincts séparés par montant quand un tiers
     * a plusieurs opérations par mois ; (3) tiers + portefeuille sans tenir compte de la catégorie (catégorie qui change).
     *
     * @return array{suggestions: list<array<string, mixed>>, rejets: list<array<string, mixed>>, derniere: ?DateTimeImmutable, total: int}
     */
    public function diagnostic(?DateTimeInterface $today = null): array {
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

        /** @var list<PrevisionRegle> $regles */
        $regles = $this->em->getRepository(PrevisionRegle::class)->findBy(['actif' => true]);
        $avecRegle = []; // groupes (tiers|catégorie|portefeuille) déjà touchés par au moins une règle
        foreach ($regles as $regle) {
            $avecRegle[$this->key($regle->getTiers()?->getId(), $regle->getCategorie()?->getId(), $regle->getPortefeuille()?->getId())] = true;
        }

        $strict = [];
        $parTiers = [];
        $derniere = null;
        foreach ($depenses as $d) {
            $derniere = $d->getDate();
            $strict[$this->key($d->getTiers()->getId(), $d->getCategorie()->getId(), $d->getPortefeuille()?->getId())][] = $d;
            $parTiers[$this->key($d->getTiers()->getId(), '*', $d->getPortefeuille()?->getId())][] = $d;
        }

        $out = [];
        $rejets = [];
        $tiersTrouves = [];

        // passe 1 + 2
        foreach ($strict as $key => $list) {
            $r = self::evaluer($this->points($list), $today);
            if (!isset($r['raison'])) {
                $tiersTrouves[$this->key($list[0]->getTiers()->getId(), '*', $list[0]->getPortefeuille()?->getId())] = true;
                if (!$this->couverte($r, $list[0], $regles, false)) {
                    $out[] = $this->suggestion($r, $list);
                }
                continue;
            }
            $trouve = false;
            if (!empty($r['frequent'])) { // plusieurs flux mélangés (électricité + gaz, internet + mobile…) : on les sépare
                foreach ($this->fluxValides($list, $today) as [$sub, $rs]) {
                    $trouve = true;
                    if (!$this->couverte($rs, $list[0], $regles, false)) {
                        $rs['resume'] = 'flux vers le ' . $rs['jour'] . ', env. ' . number_format($rs['montant'], 2, ',', ' ') . ' € — ' . $rs['resume'];
                        $out[] = $this->suggestion($rs, $sub);
                    }
                }
            }
            if ($trouve) {
                $tiersTrouves[$this->key($list[0]->getTiers()->getId(), '*', $list[0]->getPortefeuille()?->getId())] = true;
            } elseif (count($list) >= 2 && !isset($avecRegle[$key])) {
                $rejets[$key] = ['list' => $list, 'raison' => $r['raison']];
            }
        }

        // passe 3 : catégorie qui change au fil du temps
        foreach ($parTiers as $key => $list) {
            if (isset($tiersTrouves[$key]) || count($list) < 3) {
                continue;
            }
            $r = self::evaluer($this->points($list), $today);
            if (!isset($r['raison'])) {
                $already = $this->couverte($r, $list[0], $regles, true);
                if (!$already) {
                    $r['resume'] .= ' (catégories variables : ' . count(array_unique(array_map(static fn (Depenses $d) => $d->getCategorie()->getId(), $list))) . ')';
                    $out[] = $this->suggestion($r, $list);
                    // ces groupes ne sont plus « rejetés »
                    foreach (array_keys($rejets) as $rk) {
                        if (str_starts_with($rk, $list[0]->getTiers()->getId() . '|') && str_ends_with($rk, '|' . $list[0]->getPortefeuille()?->getId())) {
                            unset($rejets[$rk]);
                        }
                    }
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $rows = [];
        foreach ($rejets as $item) {
            $last = $item['list'][array_key_last($item['list'])];
            $rows[] = [
                'tiers' => $last->getTiers(),
                'categorie' => $last->getCategorie(),
                'portefeuille' => $last->getPortefeuille(),
                'occurrences' => count($item['list']),
                'derniere' => $last->getDate(),
                'raison' => $item['raison'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['occurrences'] <=> $a['occurrences']);

        return [
            'suggestions' => $out,
            'rejets' => array_slice($rows, 0, 150),
            'derniere' => $derniere ? DateTimeImmutable::createFromInterface($derniere) : null,
            'total' => count($depenses),
        ];
    }

    /** @param list<Depenses> $list @return list<array{date: DateTimeImmutable, montant: float}> */
    private function points(array $list): array {
        return array_map(static fn (Depenses $d): array => ['date' => DateTimeImmutable::createFromInterface($d->getDate()), 'montant' => (float) $d->getMontant()], $list);
    }

    /** @param list<Depenses> $list @return array<string, mixed> */
    private function suggestion(array $analyse, array $list): array {
        $last = $list[array_key_last($list)];

        return $analyse + [
            'tiers' => $last->getTiers(),
            'categorie' => $last->getCategorie(),
            'portefeuille' => $last->getPortefeuille(),
            'projet' => $last->getProjet(),
        ];
    }

    /**
     * Sépare plusieurs flux d'un même tiers et ne garde que ceux qui forment une vraie récurrence.
     * Stratégie A : par jour du mois (électricité le 3, gaz le 17) ; stratégie B : par montants voisins (±12 %)
     * quand les flux tombent le même jour (internet 30 € et mobile 15 € le 5).
     *
     * @param list<Depenses> $list
     * @return list<array{0: list<Depenses>, 1: array<string, mixed>}>
     */
    private function fluxValides(array $list, DateTimeImmutable $today): array {
        $essai = function (array $clusters) use ($today): array {
            $ok = [];
            foreach ($clusters as $sub) {
                if (count($sub) < 3) {
                    continue;
                }
                usort($sub, static fn (Depenses $a, Depenses $b): int => $a->getDate() <=> $b->getDate());
                $r = self::evaluer($this->points($sub), $today);
                if (!isset($r['raison'])) {
                    $ok[] = [$sub, $r];
                }
            }

            return $ok;
        };

        $a = $essai($this->clustersParJour($list));
        if (count($a) >= 2) {
            return $a;
        }
        $b = $essai($this->clustersParMontant($list));

        return count($b) >= max(1, count($a)) ? $b : $a;
    }

    /** @param list<Depenses> $list @return list<list<Depenses>> */
    private function clustersParJour(array $list): array {
        $sorted = $list;
        usort($sorted, static fn (Depenses $a, Depenses $b): int => (int) $a->getDate()->format('j') <=> (int) $b->getDate()->format('j'));
        $clusters = [];
        $prev = null;
        foreach ($sorted as $d) {
            $day = (int) $d->getDate()->format('j');
            if ($prev === null || $day - $prev > 5) {
                $clusters[] = [];
            }
            $clusters[array_key_last($clusters)][] = $d;
            $prev = $day;
        }
        // fin / début de mois : le 30 et le 2 sont voisins
        if (count($clusters) > 1) {
            $lastDay = (int) end($clusters)[array_key_last(end($clusters))]->getDate()->format('j');
            $firstDay = (int) $clusters[0][0]->getDate()->format('j');
            if ($firstDay + 31 - $lastDay <= 5) {
                $tail = array_pop($clusters);
                $clusters[0] = array_merge($clusters[0], $tail);
            }
        }

        return $clusters;
    }

    /** @param list<Depenses> $list @return list<list<Depenses>> */
    private function clustersParMontant(array $list): array {
        $sorted = $list;
        usort($sorted, static fn (Depenses $a, Depenses $b): int => (float) $a->getMontant() <=> (float) $b->getMontant());
        $clusters = [];
        foreach ($sorted as $d) {
            $m = (float) $d->getMontant();
            $placed = false;
            foreach ($clusters as &$cluster) {
                $mean = array_sum(array_map(static fn (Depenses $x): float => (float) $x->getMontant(), $cluster)) / count($cluster);
                if ($mean > 0 && abs($m - $mean) / $mean <= 0.12) {
                    $cluster[] = $d;
                    $placed = true;
                    break;
                }
            }
            unset($cluster);
            if (!$placed) {
                $clusters[] = [$d];
            }
        }

        return $clusters;
    }

    /**
     * Cette récurrence est-elle déjà couverte par une règle ? Même tiers + portefeuille (+ catégorie), ET même flux :
     * jour du mois voisin (±6) et montant voisin (±15 % d'une tranche) — sauf montants variables.
     * C'est ce qui permet d'avoir plusieurs règles pour un même tiers (internet ET mobile).
     *
     * @param array<string, mixed> $analyse
     * @param list<PrevisionRegle> $regles
     */
    private function couverte(array $analyse, Depenses $ref, array $regles, bool $toutesCategories): bool {
        foreach ($regles as $regle) {
            if ($regle->getTiers()?->getId() !== $ref->getTiers()->getId() || $regle->getPortefeuille()?->getId() !== $ref->getPortefeuille()?->getId()) {
                continue;
            }
            if (!$toutesCategories && $regle->getCategorie()?->getId() !== $ref->getCategorie()->getId()) {
                continue;
            }
            $ecart = abs($regle->getJour() - $analyse['jour']);
            if (min($ecart, 31 - $ecart) > 6) {
                continue;
            }
            if ($regle->isEstime() || $analyse['estime']) {
                return true;
            }
            foreach ($regle->getTranches() as $tranche) {
                $m = (float) $tranche->getMontant();
                if ($m > 0 && abs($m - $analyse['montant']) / $m <= 0.15) {
                    return true;
                }
            }
        }

        return false;
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
        $r = self::evaluer($points, $today, $souple);

        return isset($r['raison']) ? null : $r;
    }

    /**
     * Comme analyse(), mais explique l'échec : retourne ['raison' => '…'] au lieu de null.
     *
     * @param list<array{date: DateTimeImmutable, montant: float}> $points
     * @return array<string, mixed>
     */
    public static function evaluer(array $points, DateTimeImmutable $today, bool $souple = false): array {
        $n = count($points);
        if ($n < 2) {
            return ['raison' => 'une seule opération'];
        }
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $gaps[] = (float) $points[$i - 1]['date']->diff($points[$i]['date'])->days;
        }
        // achats fréquents (courses…) : pas une récurrence
        $courts = count(array_filter($gaps, static fn (float $g): bool => $g < 20));
        if ($courts / count($gaps) > 0.25) {
            return ['raison' => sprintf('plusieurs opérations rapprochées (%d %% des intervalles < 20 jours) : sans doute plusieurs flux mélangés ou des achats courants', round(100 * $courts / count($gaps))), 'frequent' => true];
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
            return ['raison' => sprintf('intervalle médian de %d jours : ne correspond à aucun rythme (mois, trimestre, semestre, année)', round($median))];
        }
        $f = $best['jours'];
        $conformes = count(array_filter($gaps, static fn (float $g): bool => abs($g - $f) / $f <= 0.2));
        $regularite = $conformes / count($gaps);
        // 2 occurrences seulement : on n'accepte que l'annuel ; moins de 3 sinon = trop peu d'indices
        if ($n < 3 && ($best['freq'] !== Frequence::ANNUELLE || $regularite < 1.0)) {
            return ['raison' => sprintf('seulement %d occurrences (3 minimum, 2 pour un rythme annuel)', $n)];
        }
        if ($regularite < ($souple ? 0.5 : self::REGULARITE_MIN)) {
            return ['raison' => sprintf('rythme %s probable mais seulement %d %% des intervalles sont conformes (70 %% requis)', mb_strtolower($best['freq']->label()), round($regularite * 100))];
        }

        $last = $points[$n - 1]['date'];
        $age = (float) $last->diff($today)->days;
        if (!$souple && $age > 1.6 * $f) {
            return ['raison' => sprintf('dernière opération le %s (il y a %d jours) : considérée comme terminée', $last->format('d/m/Y'), $age)];
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
