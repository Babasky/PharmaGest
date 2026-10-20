<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photo ou PDF de l'accord préalable de l'AMO, joint à la ligne de vente.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Copie de l\'accord préalable AMO sur la ligne de vente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_vente ADD copie_accord VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_vente DROP copie_accord');
    }
}
