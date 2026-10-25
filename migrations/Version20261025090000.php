<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inventaire d'un entrepôt : l'inventaire porte sur l'officine (entrepot_id nul) ou sur un entrepôt.
 */
final class Version20261025090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Emplacement (officine ou entrepôt) des inventaires.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventaire ADD entrepot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E072831E97 FOREIGN KEY (entrepot_id) REFERENCES entrepot (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_338920E072831E97 ON inventaire (entrepot_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E072831E97');
        $this->addSql('DROP INDEX IDX_338920E072831E97 ON inventaire');
        $this->addSql('ALTER TABLE inventaire DROP entrepot_id');
    }
}
