<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Délai avant le badge « Rupture » : réglé par la pharmacie (5 jours par défaut), modifiable par produit.
 */
final class Version20261017210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Délai avant le badge « Rupture » : paramètre de la pharmacie et délai propre à un produit.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parametre_pharmacie ADD jours_avant_rupture INT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE produit ADD jours_avant_rupture INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parametre_pharmacie DROP jours_avant_rupture');
        $this->addSql('ALTER TABLE produit DROP jours_avant_rupture');
    }
}
