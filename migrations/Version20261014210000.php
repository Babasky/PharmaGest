<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse hors ligne : ventes faites pendant une coupure d'internet, reçues au retour du réseau, et numéro
 * du ticket provisoire sur la vente importée.
 */
final class Version20261014210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table vente_hors_ligne ; vente.numero_hors_ligne.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vente_hors_ligne (id INT AUTO_INCREMENT NOT NULL, statut VARCHAR(20) NOT NULL, message VARCHAR(255) DEFAULT NULL, ecart INT NOT NULL, traitee_le DATETIME DEFAULT NULL, uuid VARCHAR(36) NOT NULL, numero_provisoire VARCHAR(30) NOT NULL, vendu_le DATETIME NOT NULL, recue_le DATETIME NOT NULL, montant INT NOT NULL, donnees JSON NOT NULL, vente_id INT DEFAULT NULL, rejetee_par_id INT DEFAULT NULL, vendeur_id INT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX UNIQ_695C54977DC7170A (vente_id), INDEX idx_vente_hors_ligne_statut (pharmacie_id, statut), UNIQUE INDEX uniq_vente_hors_ligne_uuid (pharmacie_id, uuid), INDEX IDX_695C5497790628F0 (rejetee_par_id), INDEX IDX_695C5497858C065E (vendeur_id), INDEX IDX_695C5497BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE vente_hors_ligne ADD CONSTRAINT FK_695C54977DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vente_hors_ligne ADD CONSTRAINT FK_695C5497790628F0 FOREIGN KEY (rejetee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vente_hors_ligne ADD CONSTRAINT FK_695C5497858C065E FOREIGN KEY (vendeur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vente_hors_ligne ADD CONSTRAINT FK_695C5497BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vente ADD numero_hors_ligne VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_hors_ligne DROP FOREIGN KEY FK_695C54977DC7170A');
        $this->addSql('ALTER TABLE vente_hors_ligne DROP FOREIGN KEY FK_695C5497790628F0');
        $this->addSql('ALTER TABLE vente_hors_ligne DROP FOREIGN KEY FK_695C5497858C065E');
        $this->addSql('ALTER TABLE vente_hors_ligne DROP FOREIGN KEY FK_695C5497BC6D351B');
        $this->addSql('DROP TABLE vente_hors_ligne');
        $this->addSql('ALTER TABLE vente DROP numero_hors_ligne');
    }
}
