<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 7 — Finances : catégories de dépenses de chaque pharmacie, dépenses, recettes (ventes, AMO, manuelles,
 * contre-passations). Les ventes et règlements AMO déjà enregistrés reçoivent leurs recettes.
 */
final class Version20261005224548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finances : catégories de dépenses, dépenses, recettes ; recettes des ventes et règlements AMO existants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE categorie_depense (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, actif TINYINT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_categorie_depense_nom (pharmacie_id, nom), INDEX IDX_6B8639F5BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE depense (id INT AUTO_INCREMENT NOT NULL, justificatif VARCHAR(40) DEFAULT NULL, annulee_le DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, numero VARCHAR(30) NOT NULL, date DATE NOT NULL, libelle VARCHAR(150) NOT NULL, montant INT NOT NULL, mode VARCHAR(20) NOT NULL, beneficiaire VARCHAR(120) DEFAULT NULL, cree_le DATETIME NOT NULL, annulee_par_id INT DEFAULT NULL, categorie_id INT NOT NULL, cree_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX idx_depense_date (pharmacie_id, date), UNIQUE INDEX uniq_depense_numero (pharmacie_id, numero), INDEX IDX_340597571D95B04C (annulee_par_id), INDEX IDX_34059757BCF5E72D (categorie_id), INDEX IDX_34059757FC29C013 (cree_par_id), INDEX IDX_34059757BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recette (id INT AUTO_INCREMENT NOT NULL, annulee_le DATETIME DEFAULT NULL, date DATE NOT NULL, origine VARCHAR(20) NOT NULL, libelle VARCHAR(150) NOT NULL, montant INT NOT NULL, mode VARCHAR(20) NOT NULL, cree_le DATETIME NOT NULL, vente_id INT DEFAULT NULL, reglement_amo_id INT DEFAULT NULL, annule_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX idx_recette_date (pharmacie_id, date), INDEX IDX_49BB63907DC7170A (vente_id), INDEX IDX_49BB6390958BA76E (reglement_amo_id), INDEX IDX_49BB6390212C1955 (annule_id), INDEX IDX_49BB6390FC29C013 (cree_par_id), INDEX IDX_49BB6390BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE categorie_depense ADD CONSTRAINT FK_6B8639F5BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_340597571D95B04C FOREIGN KEY (annulee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie_depense (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE recette ADD CONSTRAINT FK_49BB63907DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE recette ADD CONSTRAINT FK_49BB6390958BA76E FOREIGN KEY (reglement_amo_id) REFERENCES reglement_amo (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE recette ADD CONSTRAINT FK_49BB6390212C1955 FOREIGN KEY (annule_id) REFERENCES recette (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE recette ADD CONSTRAINT FK_49BB6390FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE recette ADD CONSTRAINT FK_49BB6390BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');

        // FI-04 : recettes des ventes déjà encaissées (une par paiement), puis contre-passation des ventes annulées.
        $this->addSql("INSERT INTO recette (date, origine, libelle, montant, mode, cree_le, vente_id, cree_par_id, pharmacie_id)
            SELECT DATE(v.validee_le), 'vente', CONCAT('Vente ', v.numero), p.montant, p.mode, v.validee_le, v.id, v.vendeur_id, v.pharmacie_id
            FROM paiement p JOIN vente v ON v.id = p.vente_id
            WHERE v.statut IN ('validee', 'annulee') AND p.montant > 0
            ORDER BY v.validee_le, p.id");
        $this->addSql("INSERT INTO recette (date, origine, libelle, montant, mode, cree_le, vente_id, annule_id, cree_par_id, pharmacie_id)
            SELECT DATE(v.annulee_le), 'contre_passation', CONCAT('Annulation de la vente ', v.numero), -r.montant, r.mode, v.annulee_le, v.id, r.id, v.annulee_par_id, r.pharmacie_id
            FROM recette r JOIN vente v ON v.id = r.vente_id
            WHERE v.statut = 'annulee' AND r.origine = 'vente'");
        $this->addSql("UPDATE recette r JOIN vente v ON v.id = r.vente_id SET r.annulee_le = v.annulee_le WHERE v.statut = 'annulee' AND r.origine = 'vente'");
        // RG-10 : les règlements AMO déjà reçus deviennent des recettes.
        $this->addSql("INSERT INTO recette (date, origine, libelle, montant, mode, cree_le, reglement_amo_id, cree_par_id, pharmacie_id)
            SELECT ra.date, 'amo', CONCAT('Règlement AMO ', b.numero, ' (', o.code, ')'), ra.montant, 'virement', ra.enregistre_le, ra.id, ra.enregistre_par_id, ra.pharmacie_id
            FROM reglement_amo ra JOIN bordereau_amo b ON b.id = ra.bordereau_id JOIN organisme_amo o ON o.id = b.organisme_id
            ORDER BY ra.date, ra.id");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie_depense DROP FOREIGN KEY FK_6B8639F5BC6D351B');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_340597571D95B04C');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757BCF5E72D');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757FC29C013');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757BC6D351B');
        $this->addSql('ALTER TABLE recette DROP FOREIGN KEY FK_49BB63907DC7170A');
        $this->addSql('ALTER TABLE recette DROP FOREIGN KEY FK_49BB6390958BA76E');
        $this->addSql('ALTER TABLE recette DROP FOREIGN KEY FK_49BB6390212C1955');
        $this->addSql('ALTER TABLE recette DROP FOREIGN KEY FK_49BB6390FC29C013');
        $this->addSql('ALTER TABLE recette DROP FOREIGN KEY FK_49BB6390BC6D351B');
        $this->addSql('DROP TABLE categorie_depense');
        $this->addSql('DROP TABLE depense');
        $this->addSql('DROP TABLE recette');
    }
}
