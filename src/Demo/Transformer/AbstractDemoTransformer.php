<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoFaker;
use App\Demo\DemoStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Doctrine\ORM\QueryBuilder;
use ReflectionProperty;

abstract class AbstractDemoTransformer implements DemoTransformerInterface {

    public function __construct(
        /** EntityManager de la base démo (cible). */
        #[Autowire(service: 'doctrine.orm.demo_entity_manager')] protected EntityManagerInterface $demoEm,
        protected DemoFaker $faker
    ) {
    }

    public function isHierarchical(): bool {
        return false;
    }

    public function getParentId(object $source): string|int|null {
        return null;
    }

    public function getSourceId(object $source): string|int {
        return $source->getId();
    }

    public function configureQuery(QueryBuilder $qb): void {
    }

    public function prepare(DemoContext $context): void {
    }

    public function strategyOf(object $source, DemoContext $context): DemoStrategy {
        return $source->getDemoStrategy();
    }

    /**
     * Référence (proxy) vers l'équivalent démo d'une entité source, ou null si elle n'existe pas
     * dans la démo (exclue / ignorée).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    protected function ref(DemoContext $context, string $class, string|int|null $sourceId): ?object {
        $targetId = $context->targetId($class, $sourceId);

        return $targetId === null ? null : $this->demoEm->getReference($class, $targetId);
    }

    /** Affecte une propriété privée sans setter (createdAt, deletedAt…). */
    protected function setProp(object $target, string $property, mixed $value): void {
        $reflection = new ReflectionProperty($target, $property);
        $reflection->setValue($target, $value);
    }
}
