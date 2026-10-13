<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service support client de la plateforme (WhatsApp, appel, email), réglé par le super admin.
 */
final class Version20261013194239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table support_client.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE support_client (
              id INT AUTO_INCREMENT NOT NULL,
              whatsapp_actif TINYINT NOT NULL,
              whatsapp VARCHAR(20) DEFAULT NULL,
              telephone_actif TINYINT NOT NULL,
              telephone VARCHAR(20) DEFAULT NULL,
              email_actif TINYINT NOT NULL,
              email VARCHAR(180) DEFAULT NULL,
              horaires VARCHAR(255) DEFAULT NULL,
              modifie_le DATETIME DEFAULT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE support_client');
    }
}
