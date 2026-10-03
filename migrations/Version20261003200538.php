<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 2 — référentiels : paramètres, taux AMO, catégories, étagères, fournisseurs, produits, clients, référentiels communs.
 */
final class Version20261003200538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Référentiels de l\'officine et référentiels communs (+ valeurs initiales SA-07).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE categorie (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, actif TINYINT NOT NULL, parent_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_categorie_nom (pharmacie_id, parent_id, nom), INDEX IDX_497DD634727ACA70 (parent_id), INDEX IDX_497DD634BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE categorie_depense_modele (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, actif TINYINT NOT NULL, UNIQUE INDEX UNIQ_2E8314F06C6E55B5 (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, telephone VARCHAR(20) DEFAULT NULL, privilegie TINYINT NOT NULL, numero_assure VARCHAR(40) DEFAULT NULL, entreprise VARCHAR(120) DEFAULT NULL, cree_le DATETIME NOT NULL, actif TINYINT NOT NULL, organisme_amo_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX idx_client_nom (pharmacie_id, nom), INDEX idx_client_telephone (pharmacie_id, telephone), INDEX IDX_C744045580CE00D1 (organisme_amo_id), INDEX IDX_C7440455BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE etagere (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, libelle VARCHAR(100) NOT NULL, zone VARCHAR(20) NOT NULL, actif TINYINT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_etagere_code (pharmacie_id, code), INDEX IDX_B83FE5C4BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE forme_galenique (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, actif TINYINT NOT NULL, UNIQUE INDEX UNIQ_27475296C6E55B5 (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fournisseur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, contact VARCHAR(120) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, delai_livraison INT DEFAULT NULL, conditions_paiement VARCHAR(255) DEFAULT NULL, actif TINYINT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_fournisseur_nom (pharmacie_id, nom), INDEX IDX_369ECA32BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE organisme_amo (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, actif TINYINT NOT NULL, code VARCHAR(20) NOT NULL, UNIQUE INDEX UNIQ_1D54A21E6C6E55B5 (nom), UNIQUE INDEX UNIQ_1D54A21E77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE parametre_pharmacie (id INT AUTO_INCREMENT NOT NULL, plafond_remise INT NOT NULL, delai_alerte_peremption INT NOT NULL, politique_sans_ordonnance VARCHAR(20) NOT NULL, mentions_ticket LONGTEXT DEFAULT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_parametre_pharmacie (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE produit (id INT AUTO_INCREMENT NOT NULL, nom_commercial VARCHAR(150) NOT NULL, dci VARCHAR(150) DEFAULT NULL, dosage VARCHAR(60) DEFAULT NULL, conditionnement VARCHAR(100) DEFAULT NULL, code_barres VARCHAR(50) DEFAULT NULL, prix_achat INT NOT NULL, prix_vente INT NOT NULL, seuil_alerte INT NOT NULL, stock_max INT DEFAULT NULL, ordonnance_obligatoire TINYINT NOT NULL, remboursable_amo TINYINT NOT NULL, taux_tva INT NOT NULL, actif TINYINT NOT NULL, forme_id INT DEFAULT NULL, categorie_id INT DEFAULT NULL, etagere_id INT DEFAULT NULL, fournisseur_habituel_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX idx_produit_nom (pharmacie_id, nom_commercial), INDEX idx_produit_dci (pharmacie_id, dci), UNIQUE INDEX uniq_produit_code_barres (pharmacie_id, code_barres), INDEX IDX_29A5EC27BCE84E7C (forme_id), INDEX IDX_29A5EC27BCF5E72D (categorie_id), INDEX IDX_29A5EC276588D180 (etagere_id), INDEX IDX_29A5EC279F8E1010 (fournisseur_habituel_id), INDEX IDX_29A5EC27BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE taux_amo (id INT AUTO_INCREMENT NOT NULL, taux INT NOT NULL, date_effet DATE NOT NULL, cree_le DATETIME NOT NULL, organisme_id INT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_taux_amo (pharmacie_id, organisme_id, date_effet), INDEX IDX_2591E7EE5DDD38F5 (organisme_id), INDEX IDX_2591E7EEBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE categorie ADD CONSTRAINT FK_497DD634727ACA70 FOREIGN KEY (parent_id) REFERENCES categorie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE categorie ADD CONSTRAINT FK_497DD634BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C744045580CE00D1 FOREIGN KEY (organisme_amo_id) REFERENCES organisme_amo (id)');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C7440455BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE etagere ADD CONSTRAINT FK_B83FE5C4BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA32BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE parametre_pharmacie ADD CONSTRAINT FK_59D8ECBDBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27BCE84E7C FOREIGN KEY (forme_id) REFERENCES forme_galenique (id)');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC276588D180 FOREIGN KEY (etagere_id) REFERENCES etagere (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC279F8E1010 FOREIGN KEY (fournisseur_habituel_id) REFERENCES fournisseur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE taux_amo ADD CONSTRAINT FK_2591E7EE5DDD38F5 FOREIGN KEY (organisme_id) REFERENCES organisme_amo (id)');
        $this->addSql('ALTER TABLE taux_amo ADD CONSTRAINT FK_2591E7EEBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE pharmacie ADD logo VARCHAR(100) DEFAULT NULL');
        // Référentiels communs (SA-07). Modifiables ensuite par le super admin.
        foreach ([['INPS', 'INPS — Institut national de prévoyance sociale'], ['CMSS', 'CMSS — Caisse malienne de sécurité sociale']] as [$code, $nom]) {
            $this->addSql('INSERT INTO organisme_amo (code, nom, actif) VALUES (?, ?, 1)', [$code, $nom]);
        }
        foreach (['Comprimé', 'Comprimé effervescent', 'Gélule', 'Sirop', 'Suspension buvable', 'Solution buvable', 'Gouttes', 'Solution injectable', 'Poudre pour suspension', 'Sachet', 'Pommade', 'Crème', 'Gel', 'Collyre', 'Suppositoire', 'Ovule', 'Spray', 'Inhalateur', 'Patch', 'Dispositif médical'] as $forme) {
            $this->addSql('INSERT INTO forme_galenique (nom, actif) VALUES (?, 1)', [$forme]);
        }
        foreach (['Loyer', 'Salaires', 'Électricité', 'Eau', 'Transport', 'Achats de marchandises', 'Impôts et taxes', 'Divers'] as $categorie) {
            $this->addSql('INSERT INTO categorie_depense_modele (nom, actif) VALUES (?, 1)', [$categorie]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie DROP FOREIGN KEY FK_497DD634727ACA70');
        $this->addSql('ALTER TABLE categorie DROP FOREIGN KEY FK_497DD634BC6D351B');
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C744045580CE00D1');
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C7440455BC6D351B');
        $this->addSql('ALTER TABLE etagere DROP FOREIGN KEY FK_B83FE5C4BC6D351B');
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA32BC6D351B');
        $this->addSql('ALTER TABLE parametre_pharmacie DROP FOREIGN KEY FK_59D8ECBDBC6D351B');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27BCE84E7C');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27BCF5E72D');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC276588D180');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC279F8E1010');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27BC6D351B');
        $this->addSql('ALTER TABLE taux_amo DROP FOREIGN KEY FK_2591E7EE5DDD38F5');
        $this->addSql('ALTER TABLE taux_amo DROP FOREIGN KEY FK_2591E7EEBC6D351B');
        $this->addSql('DROP TABLE categorie');
        $this->addSql('DROP TABLE categorie_depense_modele');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE etagere');
        $this->addSql('DROP TABLE forme_galenique');
        $this->addSql('DROP TABLE fournisseur');
        $this->addSql('DROP TABLE organisme_amo');
        $this->addSql('DROP TABLE parametre_pharmacie');
        $this->addSql('DROP TABLE produit');
        $this->addSql('DROP TABLE taux_amo');
        $this->addSql('ALTER TABLE pharmacie DROP logo');
    }
}
