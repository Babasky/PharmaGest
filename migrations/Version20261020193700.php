<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Accord du propriétaire donné à distance, depuis son compte, pour une vente qui demandait son code PIN.
 */
final class Version20261020193700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accord du propriétaire à distance sur une vente (demande, réponse, empreinte du panier).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente ADD accord_demande_le DATETIME DEFAULT NULL, ADD accord_repondu_le DATETIME DEFAULT NULL, ADD accord_donne TINYINT DEFAULT NULL, ADD accord_empreinte VARCHAR(64) DEFAULT NULL, ADD accord_demande_par_id INT DEFAULT NULL, ADD accord_repondu_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4CB6A6E2DF FOREIGN KEY (accord_demande_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C94AC633A FOREIGN KEY (accord_repondu_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_888A2A4CB6A6E2DF ON vente (accord_demande_par_id)');
        $this->addSql('CREATE INDEX IDX_888A2A4C94AC633A ON vente (accord_repondu_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4CB6A6E2DF');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C94AC633A');
        $this->addSql('DROP INDEX IDX_888A2A4CB6A6E2DF ON vente');
        $this->addSql('DROP INDEX IDX_888A2A4C94AC633A ON vente');
        $this->addSql('ALTER TABLE vente DROP accord_demande_le, DROP accord_repondu_le, DROP accord_donne, DROP accord_empreinte, DROP accord_demande_par_id, DROP accord_repondu_par_id');
    }
}
