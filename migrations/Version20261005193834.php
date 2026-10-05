<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 3 — stock : lots, mouvements, inventaires.
 */
final class Version20261005193834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock : lots, mouvements de stock, inventaires et lignes d\'inventaire.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inventaire (id INT AUTO_INCREMENT NOT NULL, statut VARCHAR(20) NOT NULL, cloture_le DATETIME DEFAULT NULL, numero VARCHAR(30) NOT NULL, perimetre VARCHAR(20) NOT NULL, ouvert_le DATETIME NOT NULL, cloture_par_id INT DEFAULT NULL, ouvert_par_id INT DEFAULT NULL, etagere_id INT DEFAULT NULL, categorie_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_inventaire_numero (pharmacie_id, numero), INDEX IDX_338920E0D596D79F (cloture_par_id), INDEX IDX_338920E06FD322E7 (ouvert_par_id), INDEX IDX_338920E06588D180 (etagere_id), INDEX IDX_338920E0BCF5E72D (categorie_id), INDEX IDX_338920E0BC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_inventaire (id INT AUTO_INCREMENT NOT NULL, quantite_theorique INT NOT NULL, quantite_comptee INT DEFAULT NULL, ordre INT NOT NULL, inventaire_id INT NOT NULL, lot_id INT NOT NULL, pharmacie_id INT NOT NULL, UNIQUE INDEX uniq_ligne_inventaire_lot (inventaire_id, lot_id), INDEX IDX_D025CEFDCE430A85 (inventaire_id), INDEX IDX_D025CEFDA8CBA5F7 (lot_id), INDEX IDX_D025CEFDBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lot (id INT AUTO_INCREMENT NOT NULL, quantite_restante INT NOT NULL, cree_le DATETIME NOT NULL, numero VARCHAR(50) NOT NULL, date_peremption DATE NOT NULL, quantite_initiale INT NOT NULL, prix_achat INT NOT NULL, date_reception DATE NOT NULL, produit_id INT NOT NULL, fournisseur_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX idx_lot_peremption (pharmacie_id, date_peremption), INDEX IDX_B81291BF347EFB (produit_id), INDEX IDX_B81291B670C757F (fournisseur_id), INDEX IDX_B81291BBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_stock (id INT AUTO_INCREMENT NOT NULL, quantite_apres INT NOT NULL, type VARCHAR(30) NOT NULL, quantite INT NOT NULL, date DATETIME NOT NULL, motif VARCHAR(255) DEFAULT NULL, document VARCHAR(30) DEFAULT NULL, lot_id INT NOT NULL, utilisateur_id INT DEFAULT NULL, pharmacie_id INT NOT NULL, INDEX idx_mouvement_date (pharmacie_id, date), INDEX idx_mouvement_type (pharmacie_id, type, date), INDEX IDX_61E2C8EBA8CBA5F7 (lot_id), INDEX IDX_61E2C8EBFB88E14F (utilisateur_id), INDEX IDX_61E2C8EBBC6D351B (pharmacie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E0D596D79F FOREIGN KEY (cloture_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E06FD322E7 FOREIGN KEY (ouvert_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E06588D180 FOREIGN KEY (etagere_id) REFERENCES etagere (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E0BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E0BC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFDCE430A85 FOREIGN KEY (inventaire_id) REFERENCES inventaire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFDA8CBA5F7 FOREIGN KEY (lot_id) REFERENCES lot (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFDBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE lot ADD CONSTRAINT FK_B81291BF347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE lot ADD CONSTRAINT FK_B81291B670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE lot ADD CONSTRAINT FK_B81291BBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE mouvement_stock ADD CONSTRAINT FK_61E2C8EBA8CBA5F7 FOREIGN KEY (lot_id) REFERENCES lot (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE mouvement_stock ADD CONSTRAINT FK_61E2C8EBFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_stock ADD CONSTRAINT FK_61E2C8EBBC6D351B FOREIGN KEY (pharmacie_id) REFERENCES pharmacie (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E0D596D79F');
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E06FD322E7');
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E06588D180');
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E0BCF5E72D');
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E0BC6D351B');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFDCE430A85');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFDA8CBA5F7');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFDBC6D351B');
        $this->addSql('ALTER TABLE lot DROP FOREIGN KEY FK_B81291BF347EFB');
        $this->addSql('ALTER TABLE lot DROP FOREIGN KEY FK_B81291B670C757F');
        $this->addSql('ALTER TABLE lot DROP FOREIGN KEY FK_B81291BBC6D351B');
        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EBA8CBA5F7');
        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EBFB88E14F');
        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EBBC6D351B');
        $this->addSql('DROP TABLE inventaire');
        $this->addSql('DROP TABLE ligne_inventaire');
        $this->addSql('DROP TABLE lot');
        $this->addSql('DROP TABLE mouvement_stock');
    }
}
