<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assurances créées par les pharmacies : un organisme peut appartenir à une pharmacie (null : organisme commun).
 */
final class Version20261017200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organismes d\'assurance propres à une pharmacie ; nom et code uniques par pharmacie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organisme_amo ADD pharmacie_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organisme_amo ADD CONSTRAINT FK_1D54A21EBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_1D54A21EBC6D351B ON organisme_amo (pharmacie_id)');
        $this->addSql('DROP INDEX UNIQ_1D54A21E6C6E55B5 ON organisme_amo');
        $this->addSql('DROP INDEX UNIQ_1D54A21E77153098 ON organisme_amo');
        $this->addSql('CREATE UNIQUE INDEX uniq_organisme_amo_nom ON organisme_amo (pharmacie_id, nom)');
        $this->addSql('CREATE UNIQUE INDEX uniq_organisme_amo_code ON organisme_amo (pharmacie_id, code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_organisme_amo_nom ON organisme_amo');
        $this->addSql('DROP INDEX uniq_organisme_amo_code ON organisme_amo');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1D54A21E6C6E55B5 ON organisme_amo (nom)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1D54A21E77153098 ON organisme_amo (code)');
        $this->addSql('ALTER TABLE organisme_amo DROP FOREIGN KEY FK_1D54A21EBC6D351B');
        $this->addSql('DROP INDEX IDX_1D54A21EBC6D351B ON organisme_amo');
        $this->addSql('ALTER TABLE organisme_amo DROP pharmacie_id');
    }
}
