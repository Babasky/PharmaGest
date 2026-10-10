<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Transferts de stock entre officines du même propriétaire (ST-11).
 */
final class Version20261010123039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables transfert_stock, ligne_transfert et lot_transfere.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ligne_transfert (
              id INT AUTO_INCREMENT NOT NULL,
              quantite INT NOT NULL,
              transfert_id INT NOT NULL,
              produit_id INT NOT NULL,
              pharmacie_id INT NOT NULL,
              UNIQUE INDEX uniq_ligne_transfert_produit (transfert_id, produit_id),
              INDEX IDX_D1ABA3333C9C4BAD (transfert_id),
              INDEX IDX_D1ABA333F347EFB (produit_id),
              INDEX IDX_D1ABA333BC6D351B (pharmacie_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE lot_transfere (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(50) NOT NULL,
              date_peremption DATE DEFAULT NULL,
              prix_achat INT NOT NULL,
              quantite INT NOT NULL,
              ligne_id INT NOT NULL,
              lot_id INT NOT NULL,
              pharmacie_id INT NOT NULL,
              INDEX IDX_55E8B20D5A438E76 (ligne_id),
              INDEX IDX_55E8B20DA8CBA5F7 (lot_id),
              INDEX IDX_55E8B20DBC6D351B (pharmacie_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE transfert_stock (
              id INT AUTO_INCREMENT NOT NULL,
              statut VARCHAR(25) NOT NULL,
              expedie_le DATETIME DEFAULT NULL,
              recu_le DATETIME DEFAULT NULL,
              annule_le DATETIME DEFAULT NULL,
              motif_annulation VARCHAR(255) DEFAULT NULL,
              numero VARCHAR(30) NOT NULL,
              cree_le DATETIME NOT NULL,
              note VARCHAR(255) DEFAULT NULL,
              expedie_par_id INT DEFAULT NULL,
              recu_par_id INT DEFAULT NULL,
              annule_par_id INT DEFAULT NULL,
              pharmacie_destination_id INT NOT NULL,
              cree_par_id INT DEFAULT NULL,
              pharmacie_id INT NOT NULL,
              INDEX idx_transfert_destination (
                pharmacie_destination_id, statut
              ),
              UNIQUE INDEX uniq_transfert_numero (pharmacie_id, numero),
              INDEX IDX_69A78AA9AEE70F99 (expedie_par_id),
              INDEX IDX_69A78AA959820928 (recu_par_id),
              INDEX IDX_69A78AA9F376B95 (annule_par_id),
              INDEX IDX_69A78AA9693D0CAC (pharmacie_destination_id),
              INDEX IDX_69A78AA9FC29C013 (cree_par_id),
              INDEX IDX_69A78AA9BC6D351B (pharmacie_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ligne_transfert
            ADD
              CONSTRAINT FK_D1ABA3333C9C4BAD FOREIGN KEY (transfert_id) REFERENCES transfert_stock (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ligne_transfert
            ADD
              CONSTRAINT FK_D1ABA333F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ligne_transfert
            ADD
              CONSTRAINT FK_D1ABA333BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              lot_transfere
            ADD
              CONSTRAINT FK_55E8B20D5A438E76 FOREIGN KEY (ligne_id) REFERENCES ligne_transfert (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              lot_transfere
            ADD
              CONSTRAINT FK_55E8B20DA8CBA5F7 FOREIGN KEY (lot_id) REFERENCES lot (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              lot_transfere
            ADD
              CONSTRAINT FK_55E8B20DBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              transfert_stock
            ADD
              CONSTRAINT FK_69A78AA9AEE70F99 FOREIGN KEY (expedie_par_id) REFERENCES utilisateur (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              transfert_stock
            ADD
              CONSTRAINT FK_69A78AA959820928 FOREIGN KEY (recu_par_id) REFERENCES utilisateur (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              transfert_stock
            ADD
              CONSTRAINT FK_69A78AA9F376B95 FOREIGN KEY (annule_par_id) REFERENCES utilisateur (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              transfert_stock
            ADD
              CONSTRAINT FK_69A78AA9693D0CAC FOREIGN KEY (pharmacie_destination_id) REFERENCES pharmacie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              transfert_stock
            ADD
              CONSTRAINT FK_69A78AA9FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              transfert_stock
            ADD
              CONSTRAINT FK_69A78AA9BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_transfert DROP FOREIGN KEY FK_D1ABA3333C9C4BAD');
        $this->addSql('ALTER TABLE ligne_transfert DROP FOREIGN KEY FK_D1ABA333F347EFB');
        $this->addSql('ALTER TABLE ligne_transfert DROP FOREIGN KEY FK_D1ABA333BC6D351B');
        $this->addSql('ALTER TABLE lot_transfere DROP FOREIGN KEY FK_55E8B20D5A438E76');
        $this->addSql('ALTER TABLE lot_transfere DROP FOREIGN KEY FK_55E8B20DA8CBA5F7');
        $this->addSql('ALTER TABLE lot_transfere DROP FOREIGN KEY FK_55E8B20DBC6D351B');
        $this->addSql('ALTER TABLE transfert_stock DROP FOREIGN KEY FK_69A78AA9AEE70F99');
        $this->addSql('ALTER TABLE transfert_stock DROP FOREIGN KEY FK_69A78AA959820928');
        $this->addSql('ALTER TABLE transfert_stock DROP FOREIGN KEY FK_69A78AA9F376B95');
        $this->addSql('ALTER TABLE transfert_stock DROP FOREIGN KEY FK_69A78AA9693D0CAC');
        $this->addSql('ALTER TABLE transfert_stock DROP FOREIGN KEY FK_69A78AA9FC29C013');
        $this->addSql('ALTER TABLE transfert_stock DROP FOREIGN KEY FK_69A78AA9BC6D351B');
        $this->addSql('DROP TABLE ligne_transfert');
        $this->addSql('DROP TABLE lot_transfere');
        $this->addSql('DROP TABLE transfert_stock');
    }
}
