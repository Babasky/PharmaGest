<?php

namespace App\Tests\Functional;

use App\Tests\Support\AppWebTestCase;

/**
 * Socle : layout en français, montants en FCFA, menu selon le rôle (matrice des droits § 2).
 */
final class LayoutTest extends AppWebTestCase
{
    public function testLaPageEstEnFrancaisAvecMontantsEnFcfa(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorTextContains('main', "0\u{00A0}FCFA");
        self::assertSelectorExists('[data-controller="chart"]');
    }

    public function testLeFuseauHoraireEstBamako(): void
    {
        self::assertSame('Africa/Bamako', date_default_timezone_get());
    }

    public function testMenuDuVendeur(): void
    {
        $officine = $this->creerOfficine();
        $menu = $this->connecter($officine->vendeur)->request('GET', '/')->filter('#sidebar')->text();

        foreach (['Caisse', 'Clients', 'Produits', 'Commandes', 'Fournisseurs'] as $present) {
            self::assertStringContainsString($present, $menu);
        }
        foreach (['Inventaires', 'AMO', 'Dépenses', 'Rapports', 'Équipe', 'Abonnement', 'Pharmacies'] as $absent) {
            self::assertStringNotContainsString($absent, $menu);
        }
        self::assertSelectorTextNotContains('main', "Chiffre d'affaires", 'Le CA est une donnée financière réservée au propriétaire.');
    }

    public function testMenuDeLAdjoint(): void
    {
        $officine = $this->creerOfficine();
        $menu = $this->connecter($officine->adjoint)->request('GET', '/')->filter('#sidebar')->text();

        foreach (['Caisse', 'Inventaires', 'AMO', 'Rapports'] as $present) {
            self::assertStringContainsString($present, $menu);
        }
        foreach (['Dépenses', 'Recettes', 'Équipe', 'Paramètres', 'Abonnement'] as $absent) {
            self::assertStringNotContainsString($absent, $menu);
        }
    }

    public function testMenuDuProprietaire(): void
    {
        $officine = $this->creerOfficine();
        $menu = $this->connecter($officine->proprietaire)->request('GET', '/')->filter('#sidebar')->text();

        foreach (['Caisse', 'Inventaires', 'Dépenses', 'Recettes', 'Rapports', 'Équipe', 'Paramètres', 'Abonnement', "Journal d'audit"] as $present) {
            self::assertStringContainsString($present, $menu);
        }
        self::assertStringNotContainsString('Pharmacies', $menu);
        self::assertSelectorTextContains('main', "Chiffre d'affaires");
    }

    public function testMenuDuSuperAdmin(): void
    {
        $menu = $this->connecter($this->creerSuperAdmin())->request('GET', '/admin')->filter('#sidebar')->text();

        foreach (['Vue globale', 'Pharmacies', 'Abonnements', 'Offres'] as $present) {
            self::assertStringContainsString($present, $menu);
        }
        foreach (['Caisse', 'Clients', 'Rapports'] as $absent) {
            self::assertStringNotContainsString($absent, $menu);
        }
    }

    public function testEntreeActiveEtTousLesModulesLivres(): void
    {
        $officine = $this->creerOfficine();
        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/equipe/nouveau');

        self::assertSelectorExists('#sidebar a[href="/equipe"].active');
        // Lot 8 : plus aucun module « Bientôt » dans le menu.
        self::assertSame(0, $crawler->filter('#sidebar .nav-link.disabled')->count());
    }
}
