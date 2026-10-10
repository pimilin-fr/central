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
     *
     * Principe : pour chaque couple tiers + portefeuille, on cherche des CHAÎNES d'opérations séparées par un rythme
     * régulier (mois, trimestre…), quelles que soient les catégories. Les opérations ponctuelles (frais de déménagement,
     * régularisation…) sont simplement ignorées, un changement de montant ne casse pas la chaîne, et plusieurs flux
     * d'un même tiers (internet + mobile, électricité + gaz) donnent plusieurs chaînes : à date égale, c'est le montant
     * le plus proche qui départage.
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
                ->addOrderBy('d.id', 'ASC')
                ->getQuery()
                ->getResult();

        /** @var list<PrevisionRegle> $regles */
        $regles = $this->em->getRepository(PrevisionRegle::class)->findBy(['actif' => true]);

        $groupes = [];
        $derniere = null;
        foreach ($depenses as $d) {
            $derniere = $d->getDate();
            $groupes[$d->getTiers()->getId() . '|' . $d->getPortefeuille()?->getId()][] = $d;
        }

        $out = [];
        $rejets = [];
        foreach ($groupes as $list) {
            if (count($list) < 2) {
                continue;
            }
            $utilisees = [];
            $echecs = [];
            foreach ($this->series($list) as $serie) {
                $r = self::evaluer($this->points($serie), $today);
                if (isset($r['raison'])) {
                    $echecs[] = count($serie) . ' opérations en série : ' . $r['raison'];
                    continue;
                }
                foreach ($serie as $d) {
                    $utilisees[spl_object_id($d)] = true;
                }
                if ($this->couverte($r, $list[0], $regles, true)) {
                    continue;
                }
                $categories = array_unique(array_map(static fn (Depenses $d) => $d->getCategorie()->getId(), $serie));
                if (count($categories) > 1) {
                    $r['resume'] .= ' (' . count($categories) . ' catégories différentes)';
                }
                $out[] = $this->suggestion($r, $serie) + ['serie' => $this->detail($serie)];
            }

            $reste = array_values(array_filter($list, static fn (Depenses $d): bool => !isset($utilisees[spl_object_id($d)])));
            if (count($reste) >= 2) {
                $last = $reste[array_key_last($reste)];
                $raison = $echecs !== [] ? $echecs[0] : (self::evaluer($this->points($reste), $today)['raison'] ?? 'opérations isolées, sans rythme commun');
                $rejets[] = [
                    'tiers' => $last->getTiers(),
                    'categorie' => $last->getCategorie(),
                    'portefeuille' => $last->getPortefeuille(),
                    'occurrences' => count($reste),
                    'derniere' => $last->getDate(),
                    'raison' => $raison,
                    'serie' => $this->detail($reste),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        usort($rejets, static fn (array $a, array $b): int => $b['occurrences'] <=> $a['occurrences']);

        return [
            'suggestions' => $out,
            'rejets' => array_slice($rejets, 0, 150),
            'derniere' => $derniere ? DateTimeImmutable::createFromInterface($derniere) : null,
            'total' => count($depenses),
        ];
    }

    /**
     * Extrait les chaînes régulières d'une liste d'opérations (ordre chronologique), les plus longues d'abord.
     * Une chaîne = au moins 3 opérations (2 pour un rythme annuel) espacées d'un rythme à ±20 % près,
     * en tolérant un mois manquant.
     *
     * @param list<Depenses> $ops
     * @return list<list<Depenses>>
     */
    public function series(array $ops): array {
        $ops = array_values($ops);
        $n = count($ops);
        $pris = [];
        $series = [];

        while (true) {
            $best = null;
            for ($i = 0; $i < $n; $i++) {
                if (isset($pris[$i])) {
                    continue;
                }
                foreach (self::RYTHMES as $r) {
                    [$chaine, $cout] = $this->chaine($ops, $i, $r['jours'], $pris);
                    $len = count($chaine);
                    if ($len < 3 && !($len >= 2 && $r['jours'] > 300)) {
                        continue;
                    }
                    $cout /= max(1, $len - 1);
                    if ($best === null || $len > $best['len'] || ($len === $best['len'] && $cout < $best['cout'])) {
                        $best = ['len' => $len, 'cout' => $cout, 'chaine' => $chaine];
                    }
                }
            }
            if ($best === null) {
                break;
            }
            foreach ($best['chaine'] as $idx) {
                $pris[$idx] = true;
            }
            $series[] = array_map(static fn (int $idx): Depenses => $ops[$idx], $best['chaine']);
        }

        return $series;
    }

    /**
     * Chaîne gloutonne à partir de $start : à chaque pas, l'opération la mieux placée dans la fenêtre « précédente + rythme ».
     * Le coût mêle l'écart de date et, en second, l'écart de montant (sépare deux flux le même jour).
     *
     * @param list<Depenses> $ops
     * @param array<int, bool> $pris
     * @return array{0: list<int>, 1: float}
     */
    private function chaine(array $ops, int $start, float $f, array $pris): array {
        $n = count($ops);
        $chaine = [$start];
        $cout = 0.0;
        $cur = $start;
        $tolerance = 0.2 * $f;

        while (true) {
            $curDate = $ops[$cur]->getDate();
            $curMontant = (float) $ops[$cur]->getMontant();
            $trouve = null;
            $meilleur = INF;
            foreach ([1, 2] as $k) { // 2 = un cycle manquant (toléré hors annuel, une fois la chaîne amorcée)
                if ($k === 2 && ($f > 100 || count($chaine) < 2)) {
                    break;
                }
                for ($j = $cur + 1; $j < $n; $j++) {
                    if (isset($pris[$j])) {
                        continue;
                    }
                    $dd = (float) $curDate->diff($ops[$j]->getDate())->days;
                    if ($dd > $k * $f + $tolerance) {
                        break;
                    }
                    if (abs($dd - $k * $f) > $tolerance) {
                        continue;
                    }
                    $c = abs($dd - $k * $f) / $f + 0.25 * abs((float) $ops[$j]->getMontant() - $curMontant) / max(1.0, $curMontant) + ($k - 1) * 0.5;
                    if ($c < $meilleur) {
                        $meilleur = $c;
                        $trouve = $j;
                    }
                }
                if ($trouve !== null) {
                    break;
                }
            }
            if ($trouve === null) {
                break;
            }
            $chaine[] = $trouve;
            $cout += $meilleur;
            $cur = $trouve;
        }

        return [$chaine, $cout];
    }

    /** @param list<Depenses> $list @return list<array{date: DateTimeImmutable, montant: float, categorie: string}> */
    private function detail(array $list): array {
        return array_map(static fn (Depenses $d): array => [
            'date' => DateTimeImmutable::createFromInterface($d->getDate()),
            'montant' => (float) $d->getMontant(),
            'categorie' => $d->getCategorie()->getLibelle() ?: $d->getCategorie()->getName(),
        ], $list);
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
     * Cette récurrence est-elle déjà couverte par une règle ? Même tiers + portefeuille, ET même flux :
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

}
