<?php

namespace App\Demo;

use App\Demo\Transformer\DemoTransformerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Orchestrateur : lit la base de production, applique les transformers dans l'ordre des dépendances
 * et écrit le résultat dans la base démo.
 *
 * Mémoire maîtrisée : les entités sont persistées par lots (flush + clear), seuls des identifiants
 * sont conservés dans le DemoContext.
 */
class DemoBuilder {

    private const BATCH_SIZE = 300;

    /** @var list<DemoTransformerInterface> */
    private array $transformers;

    /**
     * @param iterable<DemoTransformerInterface> $transformers
     */
    public function __construct(
        /** Base de production (source, lecture seule). */
        private EntityManagerInterface $em,
        /** Base démo (cible). */
        private EntityManagerInterface $demoEm,
        private DemoContext $context,
        private DemoFaker $faker,
        #[AutowireIterator('app.demo.transformer')]
        iterable $transformers
    ) {
        $list = [...$transformers];
        usort($list, static fn (DemoTransformerInterface $a, DemoTransformerInterface $b) => $a->getOrder() <=> $b->getOrder());
        $this->transformers = $list;
    }

    /** @return list<DemoTransformerInterface> */
    public function getTransformers(): array {
        return $this->transformers;
    }

    public function getContext(): DemoContext {
        return $this->context;
    }

    /**
     * @param (callable(string):void)|null $log appelée avec un message par classe traitée
     */
    public function build(?callable $log = null): void {
        $this->context->reset();
        $this->faker->reset();

        foreach ($this->transformers as $transformer) {
            $this->em->clear();
            $this->demoEm->clear();

            $transformer->prepare($this->context);
            $class = $transformer->getSourceClass();

            $transformer->isHierarchical()
                ? $this->processHierarchy($transformer)
                : $this->processStream($transformer);

            if ($log) {
                $log(sprintf('%s : %s', $transformer->getLabel(), $this->summary($class)));
            }
        }

        $this->em->clear();
        $this->demoEm->clear();
    }

    // ------------------------------------------------------------------

    /** Flux : lecture ligne à ligne + écriture par lots. */
    private function processStream(DemoTransformerInterface $transformer): void {
        $batch = [];

        foreach ($this->query($transformer)->toIterable() as $source) {
            $item = $this->handle($transformer, $source);
            if ($item !== null) {
                $batch[] = $item;
            }
            if (count($batch) >= self::BATCH_SIZE) {
                $this->commit($transformer, $batch);
                $batch = [];
                $this->em->clear();
            }
        }

        $this->commit($transformer, $batch);
    }

    /**
     * Arbre : tout est chargé, puis traité par « vagues » (les racines, puis leurs enfants, etc.)
     * pour que l'id du parent soit connu quand on traite l'enfant.
     */
    private function processHierarchy(DemoTransformerInterface $transformer): void {
        $class = $transformer->getSourceClass();
        $pending = $this->query($transformer)->getResult();

        while ($pending !== []) {
            $deferred = [];
            $batch = [];

            foreach ($pending as $source) {
                $parentId = $transformer->getParentId($source);
                if ($parentId !== null && !$this->context->isResolved($class, $parentId)) {
                    $deferred[] = $source;
                    continue;
                }
                $item = $this->handle($transformer, $source);
                if ($item !== null) {
                    $batch[] = $item;
                }
            }

            if (count($deferred) === count($pending)) {
                throw new \LogicException(sprintf(
                    '%s : %d élément(s) dont le parent est introuvable ou cyclique (ex. #%s).',
                    $transformer->getLabel(), count($deferred), $transformer->getSourceId($deferred[0])
                ));
            }

            $this->commit($transformer, $batch);
            $pending = $deferred;
        }
    }

    /** @return array{0: string|int, 1: object, 2: DemoStrategy}|null */
    private function handle(DemoTransformerInterface $transformer, object $source): ?array {
        $class = $transformer->getSourceClass();
        $id = $transformer->getSourceId($source);
        $strategy = $transformer->strategyOf($source, $this->context);

        if ($strategy === DemoStrategy::EXCLUDE) {
            $this->context->exclude($class, $id, $transformer->isHierarchical() ? $transformer->getParentId($source) : null);

            return null;
        }

        $target = $transformer->transform($source, $strategy, $this->context);
        if ($target === null) { // dépendance absente (ex. tiers exclu) => ligne ignorée
            $this->context->exclude($class, $id, $transformer->isHierarchical() ? $transformer->getParentId($source) : null, 'skipped');

            return null;
        }

        $this->demoEm->persist($target);

        return [$id, $target, $strategy];
    }

    /** Écrit le lot et enregistre les correspondances source → cible. */
    private function commit(DemoTransformerInterface $transformer, array $batch): void {
        if ($batch === []) {
            return;
        }

        $class = $transformer->getSourceClass();
        try {
            $this->demoEm->flush();
        } catch (Throwable $e) {
            throw new \RuntimeException(sprintf('%s : échec de l’écriture dans la base démo (%s)', $transformer->getLabel(), $e->getMessage()), 0, $e);
        }

        $uow = $this->demoEm->getUnitOfWork();
        foreach ($batch as [$sourceId, $target, $strategy]) {
            $this->context->map($class, $sourceId, $uow->getSingleIdentifierValue($target), $strategy);
        }

        $this->demoEm->clear();
    }

    private function query(DemoTransformerInterface $transformer): \Doctrine\ORM\Query {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from($transformer->getSourceClass(), 'e')
            ->orderBy('e.id', 'ASC');
        $transformer->configureQuery($qb);

        return $qb->getQuery();
    }

    private function summary(string $class): string {
        $stats = $this->context->stats()[$class] ?? [];
        $parts = [];
        foreach (['copy' => 'copiés', 'anonymize' => 'anonymisés', 'exclude' => 'exclus', 'skipped' => 'ignorés (dépendance absente)'] as $key => $label) {
            if (!empty($stats[$key])) {
                $parts[] = sprintf('%d %s', $stats[$key], $label);
            }
        }

        return $parts ? implode(', ', $parts) : 'aucune ligne';
    }
}
