<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retour du code-barres des produits (facultatif, unique dans une pharmacie).
 */
final class Version20261013200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute produit.code_barres et son index d\'unicité par pharmacie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit ADD code_barres VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_produit_code_barres ON produit (pharmacie_id, code_barres)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_produit_code_barres ON produit');
        $this->addSql('ALTER TABLE produit DROP code_barres');
    }
}
