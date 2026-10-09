<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Tiers : case « personne physique » (société par défaut), utilisée pour les noms factices de la démo. */
final class Version20261009150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute tiers.personne (0 = société par défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tiers ADD personne TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tiers DROP personne');
    }
}
