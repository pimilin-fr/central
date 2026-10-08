<?php

namespace App\Demo;

use App\Demo\Transformer\DemoTransformerInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Vide les tables de la base DÉMO (jamais la prod : voir les garde-fous de la commande).
 * Seules les tables des entités gérées par un transformer sont vidées (pas la table des migrations,
 * ni les vues SQL).
 */
class DemoPurger {

    /**
     * @param iterable<DemoTransformerInterface> $transformers
     */
    public function __construct(
        private EntityManagerInterface $demoEm,
        #[AutowireIterator('app.demo.transformer')]
        private iterable $transformers
    ) {
    }

    /** @return list<string> tables concernées */
    public function tables(): array {
        $tables = [];
        foreach ($this->transformers as $transformer) {
            $tables[] = $this->demoEm->getClassMetadata($transformer->getSourceClass())->getTableName();
        }

        return $tables;
    }

    public function purge(): void {
        $connection = $this->demoEm->getConnection();
        $platform = $connection->getDatabasePlatform();
        $tables = array_map(static fn (string $t) => $platform->quoteIdentifier($t), $this->tables());

        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $connection->executeStatement('TRUNCATE TABLE ' . $table);
                }
            } finally {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }

            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement('TRUNCATE TABLE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');

            return;
        }

        throw new \RuntimeException(sprintf(
            'Purge automatique non gérée pour %s : videz la base démo (ou recréez-la) avant de relancer.',
            $platform::class
        ));
    }
}
