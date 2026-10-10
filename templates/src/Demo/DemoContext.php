<?php

namespace App\Demo;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Mémoire d'un run de construction de la base démo.
 *
 *  - table de correspondance  « id source (prod) → id cible (démo) »,
 *  - entités exclues (avec, pour les arbres, la redirection vers le parent),
 *  - stratégie réellement appliquée à chaque entité (pour les dépendances),
 *  - options du run et statistiques.
 *
 * On ne stocke que des identifiants (jamais d'objets) : l'EntityManager démo est vidé
 * à chaque lot, les références sont recréées avec getReference().
 */
final class DemoContext {

    /** @var array<string, array<string, string|int>> */
    private array $targets = [];

    /** @var array<string, array<string, string|int|null>> class => [sourceId => redirection (id parent) ou null] */
    private array $excluded = [];

    /** @var array<string, array<string, DemoStrategy>> */
    private array $strategies = [];

    /** @var array<string, array<string, int>> */
    private array $stats = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** Décalage des dates, en jours (0 = aucun). */
    public int $dateShiftDays = 0;

    /** Amplitude (en %) de la variation des montants anonymisés (0 = montants identiques). */
    public float $jitterPercent = 0.0;

    /** Vider le champ « note » des opérations, même copiées. */
    public bool $stripNotes = false;

    public function reset(): void {
        $this->targets = $this->excluded = $this->strategies = $this->stats = $this->counters = [];
    }

    // ------------------------------------------------------------------
    // Correspondances
    // ------------------------------------------------------------------

    public function map(string $class, string|int $sourceId, string|int $targetId, DemoStrategy $strategy): void {
        $this->targets[$class][(string) $sourceId] = $targetId;
        $this->strategies[$class][(string) $sourceId] = $strategy;
        $this->count($class, $strategy->value);
    }

    /**
     * @param string|int|null $redirectTo id (source) du parent vers lequel rediriger les enfants
     */
    public function exclude(string $class, string|int $sourceId, string|int|null $redirectTo = null, string $reason = 'exclude'): void {
        $this->excluded[$class][(string) $sourceId] = $redirectTo;
        $this->count($class, $reason);
    }

    /** L'entité source a-t-elle déjà été traitée (copiée ou exclue) ? */
    public function isResolved(string $class, string|int $sourceId): bool {
        $key = (string) $sourceId;

        return isset($this->targets[$class][$key]) || array_key_exists($key, $this->excluded[$class] ?? []);
    }

    public function isExcluded(string $class, string|int|null $sourceId): bool {
        return $sourceId !== null && array_key_exists((string) $sourceId, $this->excluded[$class] ?? []);
    }

    /**
     * Id cible d'une entité source. Si elle est exclue, on remonte la chaîne de redirection
     * (arbres : l'enfant est rattaché au plus proche ancêtre conservé). Null si rien de conservé.
     */
    public function targetId(string $class, string|int|null $sourceId): string|int|null {
        $guard = 0;
        while ($sourceId !== null && $guard++ < 100) {
            $key = (string) $sourceId;
            if (isset($this->targets[$class][$key])) {
                return $this->targets[$class][$key];
            }
            if (!array_key_exists($key, $this->excluded[$class] ?? [])) {
                return null;
            }
            $sourceId = $this->excluded[$class][$key];
        }

        return null;
    }

    /** Stratégie appliquée (EXCLUDE si exclue, null si inconnue). */
    public function strategyOf(string $class, string|int|null $sourceId): ?DemoStrategy {
        if ($sourceId === null) {
            return null;
        }
        if ($this->isExcluded($class, $sourceId)) {
            return DemoStrategy::EXCLUDE;
        }

        return $this->strategies[$class][(string) $sourceId] ?? null;
    }

    // ------------------------------------------------------------------
    // Divers
    // ------------------------------------------------------------------

    /** Compteur nommé (1, 2, 3…) : « Compte 1 », « Compte 2 »… */
    public function next(string $counter): int {
        return $this->counters[$counter] = ($this->counters[$counter] ?? 0) + 1;
    }

    public function count(string $class, string $what): void {
        $this->stats[$class][$what] = ($this->stats[$class][$what] ?? 0) + 1;
    }

    /** @return array<string, array<string, int>> */
    public function stats(): array {
        return $this->stats;
    }

    /** Applique le décalage de dates du run (renvoie une copie). */
    public function shift(?DateTimeInterface $date): ?DateTimeInterface {
        if ($date === null || $this->dateShiftDays === 0) {
            return $date === null ? null : clone $date;
        }
        $modifier = sprintf('%+d days', $this->dateShiftDays);

        return $date instanceof DateTimeImmutable
            ? $date->modify($modifier)
            : (clone $date)->modify($modifier);
    }

    public function asDateTime(?DateTimeInterface $date): ?DateTime {
        $shifted = $this->shift($date);

        return $shifted === null ? null : DateTime::createFromInterface($shifted);
    }

    public function asImmutable(?DateTimeInterface $date): ?DateTimeImmutable {
        $shifted = $this->shift($date);

        return $shifted === null ? null : DateTimeImmutable::createFromInterface($shifted);
    }
}
