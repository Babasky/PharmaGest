<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 8 — Finition : centre de notifications (NO-01) et délai d'inactivité propre à la caisse.
 */
final class Version20261006190838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications ; délai d\'inactivité de la caisse dans les paramètres de la pharmacie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, lue_le DATETIME DEFAULT NULL, type VARCHAR(20) NOT NULL, message VARCHAR(255) NOT NULL, lien VARCHAR(255) DEFAULT NULL, cle VARCHAR(100) NOT NULL, cree_le DATETIME NOT NULL, utilisateur_id INT NOT NULL, pharmacie_id INT NOT NULL, INDEX idx_notification_utilisateur (utilisateur_id, pharmacie_id, lue_le), UNIQUE INDEX uniq_notification_cle (utilisateur_id, pharmacie_id, cle), INDEX IDX_BF5476CAFB88E14F (utilisateur_id), INDEX IDX_BF5476CABC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CABC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE parametre_pharmacie ADD inactivite_caisse INT DEFAULT 30 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAFB88E14F');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CABC6D351B');
        $this->addSql('DROP TABLE notification');
        $this->addSql('ALTER TABLE parametre_pharmacie DROP inactivite_caisse');
    }
}
