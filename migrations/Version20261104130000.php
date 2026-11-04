<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Zones de rangement propres à chaque pharmacie. Les trois zones d'origine (Comptoir, Réserve, Réfrigérateur) sont
 * créées pour chaque pharmacie et chaque étagère garde la sienne.
 */
final class Version20261104130000 extends AbstractMigration
{
    private const ZONES = ['comptoir' => 'Comptoir', 'reserve' => 'Réserve', 'refrigerateur' => 'Réfrigérateur'];

    public function getDescription(): string
    {
        return 'Zones de rangement créées par les pharmacies (remplacent la liste fixe des étagères).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE zone_rangement (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(60) NOT NULL, actif TINYINT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_zone_rangement_nom (pharmacie_id, nom), INDEX IDX_1E6729D3BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE zone_rangement ADD CONSTRAINT FK_1E6729D3BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');

        // Les zones d'origine pour chaque pharmacie, puis chaque étagère rattachée à la zone qu'elle avait.
        foreach (self::ZONES as $nom) {
            $this->addSql('INSERT INTO zone_rangement (pharmacie_id, nom, actif) SELECT id, ?, 1 FROM pharmacie', [$nom]);
        }
        $this->addSql('ALTER TABLE etagere ADD zone_id INT DEFAULT NULL');
        foreach (self::ZONES as $valeur => $nom) {
            $this->addSql('UPDATE etagere e JOIN zone_rangement z ON z.pharmacie_id = e.pharmacie_id AND z.nom = ? SET e.zone_id = z.id WHERE e.zone = ?', [$nom, $valeur]);
        }
        $this->addSql('ALTER TABLE etagere DROP zone');
        $this->addSql('ALTER TABLE etagere ADD CONSTRAINT FK_B83FE5C49F2C3FAB FOREIGN KEY (zone_id) REFERENCES zone_rangement (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_B83FE5C49F2C3FAB ON etagere (zone_id)');
    }

    public function down(Schema $schema): void
    {
        // Une zone créée par une pharmacie n'a pas d'équivalent dans l'ancienne liste : ses étagères reviennent au comptoir.
        $this->addSql('ALTER TABLE etagere DROP FOREIGN KEY FK_B83FE5C49F2C3FAB');
        $this->addSql('DROP INDEX IDX_B83FE5C49F2C3FAB ON etagere');
        $this->addSql("ALTER TABLE etagere ADD zone VARCHAR(20) NOT NULL DEFAULT 'comptoir'");
        foreach (self::ZONES as $valeur => $nom) {
            $this->addSql('UPDATE etagere e JOIN zone_rangement z ON z.id = e.zone_id AND z.nom = ? SET e.zone = ?', [$nom, $valeur]);
        }
        $this->addSql('ALTER TABLE etagere ALTER zone DROP DEFAULT, DROP zone_id');
        $this->addSql('ALTER TABLE zone_rangement DROP FOREIGN KEY FK_1E6729D3BC6D351B');
        $this->addSql('DROP TABLE zone_rangement');
    }
}
