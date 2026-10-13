<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commandes aux grossistes calculées sur les ventes : période d'analyse, couverture, envoi automatique
 * et horaire de commande par fournisseur.
 */
final class Version20261013210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paramètres de commande : jours_analyse_ventes, jours_couverture, envoi_automatique_commandes ; horaire de commande par fournisseur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE parametre_pharmacie
              ADD jours_analyse_ventes INT DEFAULT 30 NOT NULL,
              ADD jours_couverture INT DEFAULT 15 NOT NULL,
              ADD envoi_automatique_commandes TINYINT DEFAULT 0 NOT NULL
        SQL);
        $this->addSql('ALTER TABLE fournisseur ADD heure_commande INT DEFAULT NULL, ADD jours_commande VARCHAR(13) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur DROP heure_commande, DROP jours_commande');
        $this->addSql('ALTER TABLE parametre_pharmacie DROP jours_analyse_ventes, DROP jours_couverture, DROP envoi_automatique_commandes');
    }
}
