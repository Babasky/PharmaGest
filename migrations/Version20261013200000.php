<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commandes aux grossistes calculées sur les ventes : période d'analyse, couverture et envoi automatique.
 */
final class Version20261013200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paramètres de commande : jours_analyse_ventes, jours_couverture, envoi_automatique_commandes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE parametre_pharmacie
              ADD jours_analyse_ventes INT DEFAULT 30 NOT NULL,
              ADD jours_couverture INT DEFAULT 15 NOT NULL,
              ADD envoi_automatique_commandes TINYINT DEFAULT 0 NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parametre_pharmacie DROP jours_analyse_ventes, DROP jours_couverture, DROP envoi_automatique_commandes');
    }
}
