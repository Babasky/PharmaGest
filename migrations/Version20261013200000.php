<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commandes aux grossistes calculées sur les ventes : période d'analyse, couverture, envoi automatique
 * et horaire de commande par fournisseur.
 * Retour du code-barres des produits (facultatif, unique dans une pharmacie).
 */
final class Version20261013200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paramètres de commande : jours_analyse_ventes, jours_couverture, envoi_automatique_commandes ; horaire de commande par fournisseur.';
        return 'Ajoute produit.code_barres et son index d\'unicité par pharmacie.';
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
        $this->addSql('ALTER TABLE produit ADD code_barres VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_produit_code_barres ON produit (pharmacie_id, code_barres)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur DROP heure_commande, DROP jours_commande');
        $this->addSql('ALTER TABLE parametre_pharmacie DROP jours_analyse_ventes, DROP jours_couverture, DROP envoi_automatique_commandes');
        $this->addSql('DROP INDEX uniq_produit_code_barres ON produit');
        $this->addSql('ALTER TABLE produit DROP code_barres');
    }
}
