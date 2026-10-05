<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 4 — caisse : sessions de caisse, ventes, lignes et lots consommés, paiements, ordonnances, code PIN.
 */
final class Version20261005203027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse : sessions de caisse, ventes, lignes de vente et lots consommés, paiements, ordonnances, code PIN des utilisateurs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ligne_vente (id INT AUTO_INCREMENT NOT NULL, remise_type VARCHAR(20) DEFAULT NULL, remise_valeur INT NOT NULL, remise INT NOT NULL, quantite INT NOT NULL, prix_unitaire INT NOT NULL, remboursable TINYINT NOT NULL, vente_id INT NOT NULL, produit_id INT NOT NULL, pharmacie_id INT NOT NULL, INDEX IDX_8B26C07C7DC7170A (vente_id), INDEX IDX_8B26C07CF347EFB (produit_id), INDEX IDX_8B26C07CBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_vente_lot (id INT AUTO_INCREMENT NOT NULL, quantite INT NOT NULL, prix_achat INT NOT NULL, ligne_id INT NOT NULL, lot_id INT NOT NULL, pharmacie_id INT NOT NULL, INDEX IDX_4F1860095A438E76 (ligne_id), INDEX IDX_4F186009A8CBA5F7 (lot_id), INDEX IDX_4F186009BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ordonnance (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(50) DEFAULT NULL, date DATE DEFAULT NULL, prescripteur VARCHAR(120) DEFAULT NULL, structure VARCHAR(120) DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX IDX_924B326CBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE paiement (id INT AUTO_INCREMENT NOT NULL, mode VARCHAR(20) NOT NULL, montant INT NOT NULL, reference VARCHAR(60) DEFAULT NULL, montant_remis INT DEFAULT NULL, vente_id INT NOT NULL, pharmacie_id INT NOT NULL, INDEX IDX_B1DC7A1E7DC7170A (vente_id), INDEX IDX_B1DC7A1EBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE session_caisse (id INT AUTO_INCREMENT NOT NULL, cloturee_le DATETIME DEFAULT NULL, especes_comptees INT DEFAULT NULL, comptage JSON DEFAULT NULL, especes_attendues INT DEFAULT NULL, ecart INT DEFAULT NULL, justification LONGTEXT DEFAULT NULL, numero VARCHAR(30) NOT NULL, ouverte_le DATETIME NOT NULL, fond_caisse INT NOT NULL, cloturee_par_id INT DEFAULT NULL, utilisateur_id INT NOT NULL, pharmacie_id INT NOT NULL, INDEX idx_session_caisse_ouverture (pharmacie_id, ouverte_le), UNIQUE INDEX uniq_session_caisse_numero (pharmacie_id, numero), INDEX IDX_DDC85991FD9AF8EE (cloturee_par_id), INDEX IDX_DDC85991FB88E14F (utilisateur_id), INDEX IDX_DDC85991BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vente (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(30) DEFAULT NULL, statut VARCHAR(20) NOT NULL, type VARCHAR(20) NOT NULL, repere VARCHAR(60) DEFAULT NULL, remise_type VARCHAR(20) DEFAULT NULL, remise_valeur INT NOT NULL, numero_assure VARCHAR(40) DEFAULT NULL, taux_amo INT DEFAULT NULL, total_brut INT NOT NULL, remise INT NOT NULL, total_net INT NOT NULL, part_amo INT NOT NULL, montant_encaisse INT NOT NULL, validee_le DATETIME DEFAULT NULL, annulee_le DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, cree_le DATETIME NOT NULL, client_id INT DEFAULT NULL, ordonnance_id INT DEFAULT NULL, session_id INT DEFAULT NULL, organisme_amo_id INT DEFAULT NULL, autorise_par_id INT DEFAULT NULL, annulee_par_id INT DEFAULT NULL, vendeur_id INT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX UNIQ_888A2A4C2BF23B8F (ordonnance_id), INDEX idx_vente_statut (pharmacie_id, statut, validee_le), UNIQUE INDEX uniq_vente_numero (pharmacie_id, numero), INDEX IDX_888A2A4C19EB6921 (client_id), INDEX IDX_888A2A4C613FECDF (session_id), INDEX IDX_888A2A4C80CE00D1 (organisme_amo_id), INDEX IDX_888A2A4C9A138EBF (autorise_par_id), INDEX IDX_888A2A4C1D95B04C (annulee_par_id), INDEX IDX_888A2A4C858C065E (vendeur_id), INDEX IDX_888A2A4CBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ligne_vente ADD CONSTRAINT FK_8B26C07C7DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_vente ADD CONSTRAINT FK_8B26C07CF347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_vente ADD CONSTRAINT FK_8B26C07CBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_vente_lot ADD CONSTRAINT FK_4F1860095A438E76 FOREIGN KEY (ligne_id) REFERENCES ligne_vente (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_vente_lot ADD CONSTRAINT FK_4F186009A8CBA5F7 FOREIGN KEY (lot_id) REFERENCES lot (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_vente_lot ADD CONSTRAINT FK_4F186009BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ordonnance ADD CONSTRAINT FK_924B326CBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E7DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE session_caisse ADD CONSTRAINT FK_DDC85991FD9AF8EE FOREIGN KEY (cloturee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE session_caisse ADD CONSTRAINT FK_DDC85991FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE session_caisse ADD CONSTRAINT FK_DDC85991BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C19EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C2BF23B8F FOREIGN KEY (ordonnance_id) REFERENCES ordonnance (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C613FECDF FOREIGN KEY (session_id) REFERENCES session_caisse (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C80CE00D1 FOREIGN KEY (organisme_amo_id) REFERENCES organisme_amo (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C9A138EBF FOREIGN KEY (autorise_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C1D95B04C FOREIGN KEY (annulee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C858C065E FOREIGN KEY (vendeur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4CBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE utilisateur ADD code_pin VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_vente DROP FOREIGN KEY FK_8B26C07C7DC7170A');
        $this->addSql('ALTER TABLE ligne_vente DROP FOREIGN KEY FK_8B26C07CF347EFB');
        $this->addSql('ALTER TABLE ligne_vente DROP FOREIGN KEY FK_8B26C07CBC6D351B');
        $this->addSql('ALTER TABLE ligne_vente_lot DROP FOREIGN KEY FK_4F1860095A438E76');
        $this->addSql('ALTER TABLE ligne_vente_lot DROP FOREIGN KEY FK_4F186009A8CBA5F7');
        $this->addSql('ALTER TABLE ligne_vente_lot DROP FOREIGN KEY FK_4F186009BC6D351B');
        $this->addSql('ALTER TABLE ordonnance DROP FOREIGN KEY FK_924B326CBC6D351B');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E7DC7170A');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EBC6D351B');
        $this->addSql('ALTER TABLE session_caisse DROP FOREIGN KEY FK_DDC85991FD9AF8EE');
        $this->addSql('ALTER TABLE session_caisse DROP FOREIGN KEY FK_DDC85991FB88E14F');
        $this->addSql('ALTER TABLE session_caisse DROP FOREIGN KEY FK_DDC85991BC6D351B');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C19EB6921');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C2BF23B8F');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C613FECDF');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C80CE00D1');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C9A138EBF');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C1D95B04C');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C858C065E');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4CBC6D351B');
        $this->addSql('DROP TABLE ligne_vente');
        $this->addSql('DROP TABLE ligne_vente_lot');
        $this->addSql('DROP TABLE ordonnance');
        $this->addSql('DROP TABLE paiement');
        $this->addSql('DROP TABLE session_caisse');
        $this->addSql('DROP TABLE vente');
        $this->addSql('ALTER TABLE utilisateur DROP code_pin');
    }
}
