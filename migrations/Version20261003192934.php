<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 1 — plateforme : pharmacies, utilisateurs, affectations, offres, abonnements, numérotation, audit.
 */
final class Version20261003192934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plateforme multi-tenant : pharmacies, utilisateurs, offres et abonnements (+ les 3 offres du § 3.1).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE abonnement (id INT AUTO_INCREMENT NOT NULL, numero_facture VARCHAR(20) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, date_paiement DATE NOT NULL, montant INT NOT NULL, moyen VARCHAR(20) NOT NULL, reference VARCHAR(80) DEFAULT NULL, cree_le DATETIME NOT NULL, offre_id INT NOT NULL, enregistre_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX UNIQ_351268BB38D27AB1 (numero_facture), INDEX idx_abonnement_date_paiement (date_paiement), INDEX IDX_351268BB4CC8505A (offre_id), INDEX IDX_351268BBCB5FDB3E (enregistre_par_id), INDEX IDX_351268BBBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE affectation (id INT AUTO_INCREMENT NOT NULL, actif TINYINT NOT NULL, cree_le DATETIME NOT NULL, utilisateur_id INT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_affectation (utilisateur_id, pharmacie_id), INDEX IDX_F4DD61D3FB88E14F (utilisateur_id), INDEX IDX_F4DD61D3BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compteur (id INT AUTO_INCREMENT NOT NULL, valeur INT NOT NULL, portee VARCHAR(30) NOT NULL, prefixe VARCHAR(10) NOT NULL, annee INT NOT NULL, UNIQUE INDEX uniq_compteur (portee, prefixe, annee), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE journal_audit (id INT AUTO_INCREMENT NOT NULL, date DATETIME NOT NULL, action VARCHAR(60) NOT NULL, entite VARCHAR(60) DEFAULT NULL, entite_id INT DEFAULT NULL, avant JSON DEFAULT NULL, apres JSON DEFAULT NULL, adresse_ip VARCHAR(45) DEFAULT NULL, pharmacie_id INT DEFAULT NULL, utilisateur_id INT DEFAULT NULL, INDEX idx_audit_date (date), INDEX IDX_71C3CC53BC6D351B (pharmacie_id), INDEX IDX_71C3CC53FB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE offre (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(30) NOT NULL, nom VARCHAR(60) NOT NULL, max_utilisateurs INT DEFAULT NULL, max_pharmacies INT NOT NULL, fonctions JSON NOT NULL, tarif_annuel INT DEFAULT NULL, ordre INT NOT NULL, UNIQUE INDEX UNIQ_AF86866F77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pharmacie (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, ville VARCHAR(80) NOT NULL, adresse VARCHAR(255) NOT NULL, telephone VARCHAR(20) NOT NULL, email VARCHAR(180) DEFAULT NULL, numero_autorisation VARCHAR(60) NOT NULL, fin_essai DATE DEFAULT NULL, fin_abonnement DATE DEFAULT NULL, suspendue TINYINT NOT NULL, motif_suspension VARCHAR(255) DEFAULT NULL, archivee_le DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, offre_id INT NOT NULL, INDEX idx_pharmacie_fin_abonnement (fin_abonnement), INDEX IDX_5FC194344CC8505A (offre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, nom VARCHAR(120) NOT NULL, password VARCHAR(255) DEFAULT NULL, roles JSON NOT NULL, actif TINYINT NOT NULL, derniere_connexion DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, UNIQUE INDEX UNIQ_1D1C63B3E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE abonnement ADD CONSTRAINT FK_351268BB4CC8505A FOREIGN KEY (offre_id) REFERENCES offre (id)');
        $this->addSql('ALTER TABLE abonnement ADD CONSTRAINT FK_351268BBCB5FDB3E FOREIGN KEY (enregistre_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE abonnement ADD CONSTRAINT FK_351268BBBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D3FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D3BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE journal_audit ADD CONSTRAINT FK_71C3CC53BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE journal_audit ADD CONSTRAINT FK_71C3CC53FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pharmacie ADD CONSTRAINT FK_5FC194344CC8505A FOREIGN KEY (offre_id) REFERENCES offre (id)');
        // Offres du § 3.1. Tarifs à fixer (point ouvert 11.2) : null tant qu'ils ne sont pas décidés.
        $offres = [
            ['essentiel', 'Essentiel', 3, 1, 1, ['Caisse, stock par lots, AMO, remises', 'Commandes fournisseurs (email, Excel)', 'Dépenses, recettes, clôture de caisse', 'Rapports graphiques de base']],
            ['standard', 'Standard', 8, 1, 2, ['Caisse, stock par lots, AMO, remises', 'Commandes fournisseurs (email, Excel)', 'Dépenses, recettes, clôture de caisse', 'Rapports graphiques complets', 'Export comptable SYSCOHADA', 'SMS aux patients (option)']],
            ['premium', 'Premium', null, 5, 3, ['Caisse, stock par lots, AMO, remises', 'Commandes fournisseurs (email, Excel)', 'Dépenses, recettes, clôture de caisse', 'Rapports complets et consolidés', 'Export comptable SYSCOHADA', 'SMS aux patients (quota inclus)', 'Transferts de stock entre officines']],
        ];
        foreach ($offres as [$code, $nom, $maxUtilisateurs, $maxPharmacies, $ordre, $fonctions]) {
            $this->addSql(
                'INSERT INTO offre (code, nom, max_utilisateurs, max_pharmacies, fonctions, tarif_annuel, ordre) VALUES (?, ?, ?, ?, ?, NULL, ?)',
                [$code, $nom, $maxUtilisateurs, $maxPharmacies, json_encode($fonctions, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), $ordre],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE abonnement DROP FOREIGN KEY FK_351268BB4CC8505A');
        $this->addSql('ALTER TABLE abonnement DROP FOREIGN KEY FK_351268BBCB5FDB3E');
        $this->addSql('ALTER TABLE abonnement DROP FOREIGN KEY FK_351268BBBC6D351B');
        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D3FB88E14F');
        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D3BC6D351B');
        $this->addSql('ALTER TABLE journal_audit DROP FOREIGN KEY FK_71C3CC53BC6D351B');
        $this->addSql('ALTER TABLE journal_audit DROP FOREIGN KEY FK_71C3CC53FB88E14F');
        $this->addSql('ALTER TABLE pharmacie DROP FOREIGN KEY FK_5FC194344CC8505A');
        $this->addSql('DROP TABLE abonnement');
        $this->addSql('DROP TABLE affectation');
        $this->addSql('DROP TABLE compteur');
        $this->addSql('DROP TABLE journal_audit');
        $this->addSql('DROP TABLE offre');
        $this->addSql('DROP TABLE pharmacie');
        $this->addSql('DROP TABLE utilisateur');
    }
}
