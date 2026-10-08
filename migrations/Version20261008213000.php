<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Autres assurances que l'AMO (nature de l'organisme) et prix de vente AMO par médicament.
 */
final class Version20261008213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nature des organismes (AMO ou autre assurance) ; prix de vente AMO du produit, figé sur la ligne de vente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organisme_amo ADD type VARCHAR(20) DEFAULT \'amo\' NOT NULL');
        $this->addSql('ALTER TABLE produit ADD prix_vente_amo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ligne_vente ADD prix_unitaire_amo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vente ADD tarif_amo TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organisme_amo DROP type');
        $this->addSql('ALTER TABLE produit DROP prix_vente_amo');
        $this->addSql('ALTER TABLE ligne_vente DROP prix_unitaire_amo');
        $this->addSql('ALTER TABLE vente DROP tarif_amo');
    }
}
