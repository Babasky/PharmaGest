<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrait du code-barres des produits ; date de péremption d'un lot facultative (réception de commande).
 */
final class Version20261008233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime produit.code_barres ; lot.date_peremption devient facultative.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_produit_code_barres ON produit');
        $this->addSql('ALTER TABLE produit DROP code_barres');
        $this->addSql('ALTER TABLE lot CHANGE date_peremption date_peremption DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Les lots sans date ne peuvent pas redevenir obligatoires sans valeur : on leur donne une date lointaine.
        $this->addSql('UPDATE lot SET date_peremption = \'9999-12-31\' WHERE date_peremption IS NULL');
        $this->addSql('ALTER TABLE lot CHANGE date_peremption date_peremption DATE NOT NULL');
        $this->addSql('ALTER TABLE produit ADD code_barres VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_produit_code_barres ON produit (pharmacie_id, code_barres)');
    }
}
