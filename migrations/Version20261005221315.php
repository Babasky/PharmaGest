<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 6 — Commandes : commandes fournisseurs et leurs lignes, historique des envois, réceptions.
 */
final class Version20261005221315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commandes fournisseurs : commandes, lignes, envois par email, réceptions et lignes reçues (lots créés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commande (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(30) DEFAULT NULL, statut VARCHAR(25) NOT NULL, envoyee_le DATETIME DEFAULT NULL, cloturee_le DATETIME DEFAULT NULL, motif_cloture VARCHAR(255) DEFAULT NULL, cree_le DATETIME NOT NULL, envoyee_par_id INT DEFAULT NULL, fournisseur_id INT NOT NULL, cree_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX idx_commande_statut (pharmacie_id, statut), UNIQUE INDEX uniq_commande_numero (pharmacie_id, numero), INDEX IDX_6EEAA67D83F372B6 (envoyee_par_id), INDEX IDX_6EEAA67D670C757F (fournisseur_id), INDEX IDX_6EEAA67DFC29C013 (cree_par_id), INDEX IDX_6EEAA67DBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE envoi_commande (id INT AUTO_INCREMENT NOT NULL, date DATETIME NOT NULL, destinataire VARCHAR(180) NOT NULL, statut VARCHAR(10) NOT NULL, erreur VARCHAR(255) DEFAULT NULL, commande_id INT NOT NULL, envoye_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX IDX_AE5AD08E82EA2E54 (commande_id), INDEX IDX_AE5AD08ED603292 (envoye_par_id), INDEX IDX_AE5AD08EBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_commande (id INT AUTO_INCREMENT NOT NULL, quantite_recue INT NOT NULL, quantite INT NOT NULL, prix_estime INT NOT NULL, commande_id INT NOT NULL, produit_id INT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_ligne_commande_produit (commande_id, produit_id), INDEX IDX_3170B74B82EA2E54 (commande_id), INDEX IDX_3170B74BF347EFB (produit_id), INDEX IDX_3170B74BBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_reception (id INT AUTO_INCREMENT NOT NULL, quantite INT NOT NULL, prix_achat INT NOT NULL, reception_id INT NOT NULL, ligne_commande_id INT NOT NULL, lot_id INT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX UNIQ_9F338AA7A8CBA5F7 (lot_id), INDEX IDX_9F338AA77C14DF52 (reception_id), INDEX IDX_9F338AA7E10FEE63 (ligne_commande_id), INDEX IDX_9F338AA7BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reception (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, numero_bon_livraison VARCHAR(50) DEFAULT NULL, cree_le DATETIME NOT NULL, commande_id INT NOT NULL, cree_par_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX IDX_50D6852F82EA2E54 (commande_id), INDEX IDX_50D6852FFC29C013 (cree_par_id), INDEX IDX_50D6852FBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D83F372B6 FOREIGN KEY (envoyee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67DFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67DBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE envoi_commande ADD CONSTRAINT FK_AE5AD08E82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE envoi_commande ADD CONSTRAINT FK_AE5AD08ED603292 FOREIGN KEY (envoye_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE envoi_commande ADD CONSTRAINT FK_AE5AD08EBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74B82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74BF347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74BBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_reception ADD CONSTRAINT FK_9F338AA77C14DF52 FOREIGN KEY (reception_id) REFERENCES reception (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_reception ADD CONSTRAINT FK_9F338AA7E10FEE63 FOREIGN KEY (ligne_commande_id) REFERENCES ligne_commande (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_reception ADD CONSTRAINT FK_9F338AA7A8CBA5F7 FOREIGN KEY (lot_id) REFERENCES lot (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_reception ADD CONSTRAINT FK_9F338AA7BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE reception ADD CONSTRAINT FK_50D6852F82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE reception ADD CONSTRAINT FK_50D6852FFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reception ADD CONSTRAINT FK_50D6852FBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67D83F372B6');
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67D670C757F');
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67DFC29C013');
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67DBC6D351B');
        $this->addSql('ALTER TABLE envoi_commande DROP FOREIGN KEY FK_AE5AD08E82EA2E54');
        $this->addSql('ALTER TABLE envoi_commande DROP FOREIGN KEY FK_AE5AD08ED603292');
        $this->addSql('ALTER TABLE envoi_commande DROP FOREIGN KEY FK_AE5AD08EBC6D351B');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74B82EA2E54');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74BF347EFB');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74BBC6D351B');
        $this->addSql('ALTER TABLE ligne_reception DROP FOREIGN KEY FK_9F338AA77C14DF52');
        $this->addSql('ALTER TABLE ligne_reception DROP FOREIGN KEY FK_9F338AA7E10FEE63');
        $this->addSql('ALTER TABLE ligne_reception DROP FOREIGN KEY FK_9F338AA7A8CBA5F7');
        $this->addSql('ALTER TABLE ligne_reception DROP FOREIGN KEY FK_9F338AA7BC6D351B');
        $this->addSql('ALTER TABLE reception DROP FOREIGN KEY FK_50D6852F82EA2E54');
        $this->addSql('ALTER TABLE reception DROP FOREIGN KEY FK_50D6852FFC29C013');
        $this->addSql('ALTER TABLE reception DROP FOREIGN KEY FK_50D6852FBC6D351B');
        $this->addSql('DROP TABLE commande');
        $this->addSql('DROP TABLE envoi_commande');
        $this->addSql('DROP TABLE ligne_commande');
        $this->addSql('DROP TABLE ligne_reception');
        $this->addSql('DROP TABLE reception');
    }
}
