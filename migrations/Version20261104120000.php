<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Délai d'annulation d'une vente réglé par le propriétaire (nul : le jour même, comme avant).
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Délai d\'annulation des ventes dans les règles de gestion.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parametre_pharmacie ADD delai_annulation_vente INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parametre_pharmacie DROP delai_annulation_vente');
    }
}
