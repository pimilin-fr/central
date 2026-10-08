<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un transformer sait fabriquer, pour UNE classe d'entité, l'équivalent « démo » d'une ligne de prod.
 * Les transformers sont découverts automatiquement (tag app.demo.transformer) et exécutés dans l'ordre getOrder().
 */
#[AutoconfigureTag('app.demo.transformer')]
interface DemoTransformerInterface {

    /** Ordre d'exécution : les dépendances d'abord (types, catégories… puis tiers, puis opérations). */
    public function getOrder(): int;

    /** @return class-string */
    public function getSourceClass(): string;

    /** Nom affiché dans le compte rendu. */
    public function getLabel(): string;

    /** Arbre (parent/enfants) : les parents doivent être traités avant les enfants. */
    public function isHierarchical(): bool;

    /** Id source du parent (arbres uniquement). */
    public function getParentId(object $source): string|int|null;

    public function getSourceId(object $source): string|int;

    /** Jointures à ajouter à la requête de lecture (alias de l'entité : « e »). */
    public function configureQuery(QueryBuilder $qb): void;

    /** Appelé une fois avant le traitement de la classe (pré-calculs, requêtes d'agrégat…). */
    public function prepare(DemoContext $context): void;

    /** Stratégie à appliquer à cette ligne (copie / anonymisation / exclusion). */
    public function strategyOf(object $source, DemoContext $context): DemoStrategy;

    /**
     * Construit l'entité cible (NON persistée). Renvoie null si une dépendance manque
     * (ex. tiers exclu) : la ligne est alors ignorée.
     */
    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object;
}
