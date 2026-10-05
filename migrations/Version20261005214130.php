<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 5 — AMO : créances, bordereaux, règlements et leurs affectations, copie de l'ordonnance.
 */
final class Version20261005214130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AMO : créances, bordereaux, règlements et affectations, copie de l\'ordonnance ; créances des ventes AMO déjà encaissées.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE affectation_reglement_amo (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, reglement_id INT NOT NULL, creance_id INT NOT NULL, pharmacie_id INT NOT NULL, INDEX IDX_E5217C8B6A477111 (reglement_id), INDEX IDX_E5217C8BCB51AD7C (creance_id), INDEX IDX_E5217C8BBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bordereau_amo (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(30) DEFAULT NULL, statut VARCHAR(25) NOT NULL, montant_transmis INT DEFAULT NULL, transmis_le DATETIME DEFAULT NULL, debut DATE NOT NULL, fin DATE NOT NULL, cree_le DATETIME NOT NULL, transmis_par_id INT DEFAULT NULL, organisme_id INT NOT NULL, cree_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_bordereau_amo_numero (pharmacie_id, numero), INDEX IDX_41EFC012F1E9D3E4 (transmis_par_id), INDEX IDX_41EFC0125DDD38F5 (organisme_id), INDEX IDX_41EFC012FC29C013 (cree_par_id), INDEX IDX_41EFC012BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE creance_amo (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, date_vente DATETIME NOT NULL, statut VARCHAR(25) NOT NULL, montant_regle INT NOT NULL, motif_rejet VARCHAR(255) DEFAULT NULL, rejetee_le DATE DEFAULT NULL, vente_id INT NOT NULL, organisme_id INT NOT NULL, bordereau_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX UNIQ_89A6150F7DC7170A (vente_id), INDEX idx_creance_amo_statut (pharmacie_id, statut, organisme_id), INDEX IDX_89A6150F5DDD38F5 (organisme_id), INDEX IDX_89A6150F55D5304E (bordereau_id), INDEX IDX_89A6150FBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reglement_amo (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, montant INT NOT NULL, reference VARCHAR(80) DEFAULT NULL, enregistre_le DATETIME NOT NULL, bordereau_id INT NOT NULL, enregistre_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX IDX_BA041E3655D5304E (bordereau_id), INDEX IDX_BA041E36CB5FDB3E (enregistre_par_id), INDEX IDX_BA041E36BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE affectation_reglement_amo ADD CONSTRAINT FK_E5217C8B6A477111 FOREIGN KEY (reglement_id) REFERENCES reglement_amo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE affectation_reglement_amo ADD CONSTRAINT FK_E5217C8BCB51AD7C FOREIGN KEY (creance_id) REFERENCES creance_amo (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE affectation_reglement_amo ADD CONSTRAINT FK_E5217C8BBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE bordereau_amo ADD CONSTRAINT FK_41EFC012F1E9D3E4 FOREIGN KEY (transmis_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bordereau_amo ADD CONSTRAINT FK_41EFC0125DDD38F5 FOREIGN KEY (organisme_id) REFERENCES organisme_amo (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE bordereau_amo ADD CONSTRAINT FK_41EFC012FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bordereau_amo ADD CONSTRAINT FK_41EFC012BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE creance_amo ADD CONSTRAINT FK_89A6150F7DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE creance_amo ADD CONSTRAINT FK_89A6150F5DDD38F5 FOREIGN KEY (organisme_id) REFERENCES organisme_amo (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE creance_amo ADD CONSTRAINT FK_89A6150F55D5304E FOREIGN KEY (bordereau_id) REFERENCES bordereau_amo (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE creance_amo ADD CONSTRAINT FK_89A6150FBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE reglement_amo ADD CONSTRAINT FK_BA041E3655D5304E FOREIGN KEY (bordereau_id) REFERENCES bordereau_amo (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE reglement_amo ADD CONSTRAINT FK_BA041E36CB5FDB3E FOREIGN KEY (enregistre_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reglement_amo ADD CONSTRAINT FK_BA041E36BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ordonnance ADD copie VARCHAR(40) DEFAULT NULL');

        // AM-05 : les ventes AMO encaissées avant ce lot reçoivent leur créance « en attente » (annulées exclues).
        $this->addSql("INSERT INTO creance_amo (montant, date_vente, statut, montant_regle, vente_id, organisme_id, pharmacie_id)
            SELECT part_amo, validee_le, 'en_attente', 0, id, organisme_amo_id, pharmacie_id FROM vente
            WHERE type = 'amo' AND statut = 'validee' AND part_amo > 0 AND organisme_amo_id IS NOT NULL AND validee_le IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE affectation_reglement_amo DROP FOREIGN KEY FK_E5217C8B6A477111');
        $this->addSql('ALTER TABLE affectation_reglement_amo DROP FOREIGN KEY FK_E5217C8BCB51AD7C');
        $this->addSql('ALTER TABLE affectation_reglement_amo DROP FOREIGN KEY FK_E5217C8BBC6D351B');
        $this->addSql('ALTER TABLE bordereau_amo DROP FOREIGN KEY FK_41EFC012F1E9D3E4');
        $this->addSql('ALTER TABLE bordereau_amo DROP FOREIGN KEY FK_41EFC0125DDD38F5');
        $this->addSql('ALTER TABLE bordereau_amo DROP FOREIGN KEY FK_41EFC012FC29C013');
        $this->addSql('ALTER TABLE bordereau_amo DROP FOREIGN KEY FK_41EFC012BC6D351B');
        $this->addSql('ALTER TABLE creance_amo DROP FOREIGN KEY FK_89A6150F7DC7170A');
        $this->addSql('ALTER TABLE creance_amo DROP FOREIGN KEY FK_89A6150F5DDD38F5');
        $this->addSql('ALTER TABLE creance_amo DROP FOREIGN KEY FK_89A6150F55D5304E');
        $this->addSql('ALTER TABLE creance_amo DROP FOREIGN KEY FK_89A6150FBC6D351B');
        $this->addSql('ALTER TABLE reglement_amo DROP FOREIGN KEY FK_BA041E3655D5304E');
        $this->addSql('ALTER TABLE reglement_amo DROP FOREIGN KEY FK_BA041E36CB5FDB3E');
        $this->addSql('ALTER TABLE reglement_amo DROP FOREIGN KEY FK_BA041E36BC6D351B');
        $this->addSql('DROP TABLE affectation_reglement_amo');
        $this->addSql('DROP TABLE bordereau_amo');
        $this->addSql('DROP TABLE creance_amo');
        $this->addSql('DROP TABLE reglement_amo');
        $this->addSql('ALTER TABLE ordonnance DROP copie');
    }
}
