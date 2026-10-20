<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commandes automatiques : heure d'envoi de la pharmacie (pour les fournisseurs sans heure propre), suivi du dernier
 * passage, fournisseurs exclus de l'envoi automatique, et paramètres Pharma-ML par fournisseur.
 */
final class Version20261020200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Heure des commandes automatiques, suivi du dernier passage et transmission Pharma-ML par fournisseur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur ADD commande_automatique TINYINT DEFAULT 1 NOT NULL, ADD canal_transmission VARCHAR(20) DEFAULT \'email\' NOT NULL, ADD pharma_ml_url VARCHAR(255) DEFAULT NULL, ADD pharma_ml_identifiant VARCHAR(60) DEFAULT NULL, ADD pharma_ml_code_repartiteur VARCHAR(60) DEFAULT NULL, ADD pharma_ml_cle VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE parametre_pharmacie ADD heure_commandes_automatiques INT DEFAULT 21 NOT NULL, ADD dernier_passage_commandes DATETIME DEFAULT NULL, ADD bilan_commandes_automatiques VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur DROP commande_automatique, DROP canal_transmission, DROP pharma_ml_url, DROP pharma_ml_identifiant, DROP pharma_ml_code_repartiteur, DROP pharma_ml_cle');
        $this->addSql('ALTER TABLE parametre_pharmacie DROP heure_commandes_automatiques, DROP dernier_passage_commandes, DROP bilan_commandes_automatiques');
    }
}
