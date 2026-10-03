<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class LayoutTest extends WebTestCase
{
    public function testLaPageEstEnFrancaisAvecMontantsEnFcfa(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorTextContains('main', "0\u{00A0}FCFA");
        self::assertSelectorExists('[data-controller="chart"]');
    }

    public function testLeFuseauHoraireEstBamako(): void
    {
        self::bootKernel();

        self::assertSame('Africa/Bamako', date_default_timezone_get());
    }

    public function testSidebarDuVendeur(): void
    {
        $client = $this->connecter('vendeur@test.ml');
        $sidebar = $client->request('GET', '/')->filter('#sidebar')->text();

        self::assertStringContainsString('Nouvelle vente', $sidebar);
        self::assertStringContainsString('Produits', $sidebar);
        self::assertStringContainsString('Clients', $sidebar);
        self::assertStringNotContainsString('Rapports', $sidebar);
        self::assertStringNotContainsString('Dépenses', $sidebar);
        self::assertStringNotContainsString('Vendeurs', $sidebar);
        self::assertStringNotContainsString('Pharmacies', $sidebar);
    }

    public function testSidebarDuProprietaire(): void
    {
        $client = $this->connecter('proprietaire@test.ml');
        $sidebar = $client->request('GET', '/')->filter('#sidebar')->text();

        self::assertStringContainsString('Nouvelle vente', $sidebar, 'Le propriétaire hérite des droits du vendeur.');
        self::assertStringContainsString('Rapports', $sidebar);
        self::assertStringContainsString('Dépenses', $sidebar);
        self::assertStringContainsString('Vendeurs', $sidebar);
        self::assertStringContainsString('Paramètres', $sidebar);
        self::assertStringNotContainsString('Abonnements', $sidebar);
    }

    public function testSidebarDuSuperAdminNeMontrePasLesDonneesDesPharmacies(): void
    {
        $client = $this->connecter('admin@test.ml');
        $sidebar = $client->request('GET', '/')->filter('#sidebar')->text();

        self::assertStringContainsString('Pharmacies', $sidebar);
        self::assertStringContainsString('Abonnements', $sidebar);
        self::assertStringNotContainsString('Nouvelle vente', $sidebar);
        self::assertStringNotContainsString('Rapports', $sidebar);
    }

    public function testLesModulesNonLivresSontMarquesBientot(): void
    {
        $client = $this->connecter('vendeur@test.ml');
        $crawler = $client->request('GET', '/');

        self::assertSelectorExists('#sidebar a[href="/"].active');
        self::assertGreaterThan(0, $crawler->filter('#sidebar .nav-link.disabled')->count());
    }

    private function connecter(string $email): KernelBrowser
    {
        $client = self::createClient();
        /** @var UserProviderInterface<\Symfony\Component\Security\Core\User\UserInterface> $fournisseur */
        $fournisseur = self::getContainer()->get('security.user_providers');
        $client->loginUser($fournisseur->loadUserByIdentifier($email));

        return $client;
    }
}
