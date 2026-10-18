<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Part AMO sur le prix le plus bas, ordonnance au prix de la pharmacie ; accord préalable de l'AMO ; taux propre à
 * l'assuré et produits pris en charge par une assurance autre que l'AMO.
 */
final class Version20261018200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accord préalable AMO (produit, ligne de vente), taux de l\'assuré, produits pris en charge par l\'assurance ; paniers en cours au prix de la pharmacie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit ADD accord_prealable_amo TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE client_assurance ADD taux INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ligne_vente ADD accord_prealable TINYINT DEFAULT 0 NOT NULL, ADD numero_accord VARCHAR(40) DEFAULT NULL, ADD prise_en_charge TINYINT DEFAULT NULL');
        // Paniers en cours facturés au prix AMO (règle du 15/10/2026) : retour au prix de la pharmacie.
        $this->addSql('UPDATE ligne_vente l INNER JOIN vente v ON v.id = l.vente_id SET l.prix_unitaire = l.prix_pharmacie WHERE v.numero IS NULL AND l.prix_pharmacie IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_vente DROP accord_prealable, DROP numero_accord, DROP prise_en_charge');
        $this->addSql('ALTER TABLE client_assurance DROP taux');
        $this->addSql('ALTER TABLE produit DROP accord_prealable_amo');
    }
}
