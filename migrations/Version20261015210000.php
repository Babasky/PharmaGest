<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plusieurs assurances par client ; prix de la pharmacie gardé sur la ligne de vente (prix le plus bas sur une vente AMO).
 */
final class Version20261015210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assurances des clients (client_assurance, reprise de l\'assurance existante) ; prix de la pharmacie sur la ligne de vente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client_assurance (id INT AUTO_INCREMENT NOT NULL, numero_assure VARCHAR(40) NOT NULL, client_id INT NOT NULL, organisme_id INT NOT NULL, pharmacie_id INT NOT NULL, INDEX IDX_D348F9A119EB6921 (client_id), INDEX IDX_D348F9A15DDD38F5 (organisme_id), INDEX IDX_D348F9A1BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE client_assurance ADD CONSTRAINT FK_D348F9A119EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE client_assurance ADD CONSTRAINT FK_D348F9A15DDD38F5 FOREIGN KEY (organisme_id) REFERENCES organisme_amo (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE client_assurance ADD CONSTRAINT FK_D348F9A1BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        // L'assurance unique de chaque client devient sa première assurance.
        $this->addSql('INSERT INTO client_assurance (numero_assure, client_id, organisme_id, pharmacie_id) SELECT numero_assure, id, organisme_amo_id, pharmacie_id FROM client WHERE organisme_amo_id IS NOT NULL AND numero_assure IS NOT NULL ORDER BY id');
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C744045580CE00D1');
        $this->addSql('DROP INDEX IDX_C744045580CE00D1 ON client');
        $this->addSql('ALTER TABLE client DROP numero_assure, DROP organisme_amo_id');

        // Lignes déjà validées : prix de la pharmacie inconnu (null), elles gardent leur calcul d'origine.
        // Paniers en cours : la nouvelle règle s'applique dès leur prochaine modification.
        $this->addSql('ALTER TABLE ligne_vente ADD prix_pharmacie INT DEFAULT NULL');
        $this->addSql('UPDATE ligne_vente l INNER JOIN vente v ON v.id = l.vente_id SET l.prix_pharmacie = l.prix_unitaire WHERE v.numero IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_vente DROP prix_pharmacie');
        $this->addSql('ALTER TABLE client ADD numero_assure VARCHAR(40) DEFAULT NULL, ADD organisme_amo_id INT DEFAULT NULL');
        $this->addSql('UPDATE client c INNER JOIN (SELECT client_id, MIN(id) AS premiere FROM client_assurance GROUP BY client_id) p ON p.client_id = c.id INNER JOIN client_assurance a ON a.id = p.premiere SET c.numero_assure = a.numero_assure, c.organisme_amo_id = a.organisme_id');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C744045580CE00D1 FOREIGN KEY (organisme_amo_id) REFERENCES organisme_amo (id)');
        $this->addSql('CREATE INDEX IDX_C744045580CE00D1 ON client (organisme_amo_id)');
        $this->addSql('DROP TABLE client_assurance');
    }
}
