<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisses de la pharmacie : une « Caisse principale » par pharmacie, à laquelle sont rattachées les sessions
 * existantes. Une session de caisse appartient désormais à une caisse, partagée par l'équipe : la vente retient
 * donc qui l'a encaissée.
 */
final class Version20261018210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisses de la pharmacie (une caisse principale par défaut) et rattachement des sessions de caisse.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE caisse (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(60) NOT NULL, actif TINYINT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_caisse_libelle (pharmacie_id, libelle), INDEX IDX_B2A353C8BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE caisse ADD CONSTRAINT FK_B2A353C8BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql("INSERT INTO caisse (libelle, actif, pharmacie_id) SELECT 'Caisse principale', 1, id FROM pharmacie");
        $this->addSql('ALTER TABLE session_caisse ADD caisse_id INT DEFAULT NULL');
        $this->addSql('UPDATE session_caisse s INNER JOIN caisse c ON c.pharmacie_id = s.pharmacie_id SET s.caisse_id = c.id');
        $this->addSql('ALTER TABLE session_caisse MODIFY caisse_id INT NOT NULL');
        $this->addSql('ALTER TABLE session_caisse ADD CONSTRAINT FK_DDC8599127B4FEBF FOREIGN KEY (caisse_id) REFERENCES caisse (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_DDC8599127B4FEBF ON session_caisse (caisse_id)');
        // Jusqu'ici, chacun encaissait dans sa propre session : celui qui a encaissé est celui qui l'a ouverte.
        $this->addSql('ALTER TABLE vente ADD encaissee_par_id INT DEFAULT NULL');
        $this->addSql('UPDATE vente v INNER JOIN session_caisse s ON s.id = v.session_id SET v.encaissee_par_id = s.utilisateur_id');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C405667E8 FOREIGN KEY (encaissee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_888A2A4C405667E8 ON vente (encaissee_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C405667E8');
        $this->addSql('DROP INDEX IDX_888A2A4C405667E8 ON vente');
        $this->addSql('ALTER TABLE vente DROP encaissee_par_id');
        $this->addSql('ALTER TABLE session_caisse DROP FOREIGN KEY FK_DDC8599127B4FEBF');
        $this->addSql('DROP INDEX IDX_DDC8599127B4FEBF ON session_caisse');
        $this->addSql('ALTER TABLE session_caisse DROP caisse_id');
        $this->addSql('ALTER TABLE caisse DROP FOREIGN KEY FK_B2A353C8BC6D351B');
        $this->addSql('DROP TABLE caisse');
    }
}
