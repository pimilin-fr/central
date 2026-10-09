<?php

namespace App\Demo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use RuntimeException;

/**
 * Recrée la STRUCTURE de la production dans la base démo (tables, index, clés étrangères ET vues SQL),
 * à l'identique, via SHOW CREATE. La base démo est jetable : elle est d'abord vidée de ses tables/vues.
 *
 * Sécurité : la production n'est utilisée qu'en lecture (SHOW / SELECT). Toutes les écritures (DROP, CREATE)
 * passent par la connexion « demo », et seulement après avoir vérifié que cette connexion ne pointe PAS
 * sur la même base que la source.
 * MySQL / MariaDB uniquement.
 */
class DemoSchemaCloner {

    public function __construct(
        /** Production (lecture seule). */
        private EntityManagerInterface $em,
        /** Démo (jetable). */
        #[Autowire(service: 'doctrine.orm.demo_entity_manager')] private EntityManagerInterface $demoEm
    ) {
    }

    /** Nom de la base source, nom de la base cible. @return array{0: string, 1: string} */
    public function databases(): array {
        return [$this->databaseName($this->em->getConnection()), $this->databaseName($this->demoEm->getConnection())];
    }

    /**
     * @return array{tables: int, views: int}
     */
    public function recreate(): array {
        $source = $this->em->getConnection();
        $target = $this->demoEm->getConnection();

        foreach ([$source, $target] as $connection) {
            if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                throw new RuntimeException('Le clonage de structure ne gère que MySQL / MariaDB.');
            }
        }

        [$sourceDb, $targetDb] = $this->databases();
        if ($sourceDb === '' || $targetDb === '' || $sourceDb === $targetDb) {
            throw new RuntimeException(sprintf('Refus : base source « %s » et base démo « %s » identiques ou indéterminées.', $sourceDb, $targetDb));
        }

        // 1. lecture de la structure source (aucune écriture sur la prod)
        $tables = $views = [];
        foreach ($source->fetchAllAssociative('SHOW FULL TABLES') as $row) {
            $row = array_values($row);
            if (strtoupper((string) $row[1]) === 'VIEW') {
                $views[] = (string) $row[0];
            } else {
                $tables[] = (string) $row[0];
            }
        }

        $tableSql = [];
        foreach ($tables as $name) {
            $tableSql[$name] = self::cleanTableSql($source->fetchAssociative('SHOW CREATE TABLE ' . $this->q($name))['Create Table']);
        }
        $viewSql = [];
        foreach ($views as $name) {
            $viewSql[$name] = self::cleanViewSql($source->fetchAssociative('SHOW CREATE VIEW ' . $this->q($name))['Create View'], $sourceDb, $targetDb);
        }

        // 2. la base démo est jetable : on la vide, puis on recrée
        $this->wipe($target);

        $target->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tableSql as $sql) {
                $target->executeStatement($sql);
            }
            $this->createViews($target, $viewSql);
        } finally {
            $target->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return ['tables' => count($tableSql), 'views' => count($viewSql)];
    }

    /** Supprime toutes les vues puis toutes les tables de la base démo. */
    private function wipe(Connection $target): void {
        $views = $tables = [];
        foreach ($target->fetchAllAssociative('SHOW FULL TABLES') as $row) {
            $row = array_values($row);
            strtoupper((string) $row[1]) === 'VIEW' ? $views[] = (string) $row[0] : $tables[] = (string) $row[0];
        }

        $target->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($views as $view) {
                $target->executeStatement('DROP VIEW IF EXISTS ' . $this->q($view));
            }
            foreach ($tables as $table) {
                $target->executeStatement('DROP TABLE IF EXISTS ' . $this->q($table));
            }
        } finally {
            $target->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** Une vue peut dépendre d'une autre vue : on réessaie tant qu'il y a des progrès. */
    private function createViews(Connection $target, array $viewSql): void {
        $pending = $viewSql;
        while ($pending !== []) {
            $failed = [];
            $lastError = null;
            foreach ($pending as $name => $sql) {
                try {
                    $target->executeStatement($sql);
                } catch (\Throwable $e) {
                    $failed[$name] = $sql;
                    $lastError = $e->getMessage();
                }
            }
            if (count($failed) === count($pending)) {
                throw new RuntimeException(sprintf('Impossible de recréer la vue « %s » : %s', array_key_first($failed), $lastError));
            }
            $pending = $failed;
        }
    }

    // ------------------------------------------------------------------
    // Nettoyage des instructions (public static : testable sans base)
    // ------------------------------------------------------------------

    public static function cleanTableSql(string $sql): string {
        // les identifiants repartent de 1 dans la démo
        return preg_replace('/\sAUTO_INCREMENT=\d+/i', '', $sql);
    }

    public static function cleanViewSql(string $sql, string $sourceDb, string $targetDb): string {
        // DEFINER propre à la prod => utilisateur courant
        $sql = preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+?)@(`[^`]*`|\'[^\']*\'|\S+?)(\s)/i', ' ', $sql);
        // les vues citent la base d'origine (`prod`.`table`) => base démo
        return str_replace('`' . $sourceDb . '`.', '`' . $targetDb . '`.', $sql);
    }

    private function databaseName(Connection $connection): string {
        return (string) $connection->fetchOne('SELECT DATABASE()');
    }

    private function q(string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
