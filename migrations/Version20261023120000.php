<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entrepôts (magasins de stockage) de la pharmacie : lots rangés en entrepôt et transferts internes
 * entre l'officine et ses entrepôts.
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entrepôts de la pharmacie, emplacement des lots et transferts internes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE entrepot (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, adresse VARCHAR(255) DEFAULT NULL, actif TINYINT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_entrepot_nom (pharmacie_id, nom), INDEX IDX_D805175ABC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_transfert_interne (id INT AUTO_INCREMENT NOT NULL, quantite INT NOT NULL, transfert_id INT NOT NULL, produit_id INT NOT NULL, pharmacie_id INT NOT NULL, INDEX IDX_EEC25D483C9C4BAD (transfert_id), INDEX IDX_EEC25D48F347EFB (produit_id), INDEX IDX_EEC25D48BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transfert_interne (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(30) NOT NULL, cree_le DATETIME NOT NULL, note VARCHAR(255) DEFAULT NULL, origine_id INT DEFAULT NULL, destination_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX idx_transfert_interne_date (pharmacie_id, cree_le), UNIQUE INDEX uniq_transfert_interne_numero (pharmacie_id, numero), INDEX IDX_B59846F587998E (origine_id), INDEX IDX_B59846F5816C6140 (destination_id), INDEX IDX_B59846F5FC29C013 (cree_par_id), INDEX IDX_B59846F5BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entrepot ADD CONSTRAINT FK_D805175ABC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_transfert_interne ADD CONSTRAINT FK_EEC25D483C9C4BAD FOREIGN KEY (transfert_id) REFERENCES transfert_interne (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_transfert_interne ADD CONSTRAINT FK_EEC25D48F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_transfert_interne ADD CONSTRAINT FK_EEC25D48BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfert_interne ADD CONSTRAINT FK_B59846F587998E FOREIGN KEY (origine_id) REFERENCES entrepot (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfert_interne ADD CONSTRAINT FK_B59846F5816C6140 FOREIGN KEY (destination_id) REFERENCES entrepot (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfert_interne ADD CONSTRAINT FK_B59846F5FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transfert_interne ADD CONSTRAINT FK_B59846F5BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE lot ADD entrepot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE lot ADD CONSTRAINT FK_B81291B72831E97 FOREIGN KEY (entrepot_id) REFERENCES entrepot (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_lot_emplacement ON lot (produit_id, entrepot_id)');
        $this->addSql('CREATE INDEX IDX_B81291B72831E97 ON lot (entrepot_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entrepot DROP FOREIGN KEY FK_D805175ABC6D351B');
        $this->addSql('ALTER TABLE ligne_transfert_interne DROP FOREIGN KEY FK_EEC25D483C9C4BAD');
        $this->addSql('ALTER TABLE ligne_transfert_interne DROP FOREIGN KEY FK_EEC25D48F347EFB');
        $this->addSql('ALTER TABLE ligne_transfert_interne DROP FOREIGN KEY FK_EEC25D48BC6D351B');
        $this->addSql('ALTER TABLE transfert_interne DROP FOREIGN KEY FK_B59846F587998E');
        $this->addSql('ALTER TABLE transfert_interne DROP FOREIGN KEY FK_B59846F5816C6140');
        $this->addSql('ALTER TABLE transfert_interne DROP FOREIGN KEY FK_B59846F5FC29C013');
        $this->addSql('ALTER TABLE transfert_interne DROP FOREIGN KEY FK_B59846F5BC6D351B');
        $this->addSql('DROP TABLE entrepot');
        $this->addSql('DROP TABLE ligne_transfert_interne');
        $this->addSql('DROP TABLE transfert_interne');
        $this->addSql('ALTER TABLE lot DROP FOREIGN KEY FK_B81291B72831E97');
        $this->addSql('DROP INDEX idx_lot_emplacement ON lot');
        $this->addSql('DROP INDEX IDX_B81291B72831E97 ON lot');
        $this->addSql('ALTER TABLE lot DROP entrepot_id');
    }
}
