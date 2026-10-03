<?php

namespace App\Tests\Functional\Admin;

use App\Tests\Support\AppWebTestCase;

/**
 * SA-07 : référentiels communs gérés par le super admin.
 */
final class ReferentielAdminTest extends AppWebTestCase
{
    public function testLesValeursInitialesSontEnPlace(): void
    {
        $this->connecter($this->creerSuperAdmin())->request('GET', '/admin/referentiels');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'INPS');
        self::assertSelectorTextContains('main', 'CMSS');
        $this->client->request('GET', '/admin/referentiels/formes-galeniques');
        self::assertSelectorTextContains('main', 'Comprimé');
        $this->client->request('GET', '/admin/referentiels/categories-depenses');
        self::assertSelectorTextContains('main', 'Électricité');
    }

    public function testAjoutPuisDesactivationDUneForme(): void
    {
        $officine = $this->creerOfficine();
        $this->connecter($this->creerSuperAdmin())->request('GET', '/admin/referentiels/formes-galeniques');
        $this->client->submitForm('Ajouter', ['referentiel_commun[nom]' => 'Lyophilisat']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Lyophilisat');

        $this->client->submitForm('Ajouter', ['referentiel_commun[nom]' => 'Lyophilisat']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Cette forme existe déjà');

        $crawler = $this->connecter($officine->adjoint)->request('GET', '/produits/nouveau');
        self::assertCount(1, $crawler->filter('#produit_forme option:contains("Lyophilisat")'));

        $crawler = $this->connecter($this->creerSuperAdmin())->request('GET', '/admin/referentiels/formes-galeniques');
        $ligne = $crawler->filter('li:contains("Lyophilisat")');
        $this->client->submit($ligne->filter('form')->form());

        $crawler = $this->connecter($officine->adjoint)->request('GET', '/produits/nouveau');
        self::assertCount(0, $crawler->filter('#produit_forme option:contains("Lyophilisat")'), 'Une forme désactivée n\'est plus proposée.');
    }

    public function testUnOrganismeAUnCode(): void
    {
        $this->connecter($this->creerSuperAdmin())->request('GET', '/admin/referentiels/organismes-amo');
        $this->client->submitForm('Ajouter', ['referentiel_commun[nom]' => 'Mutuelle test', 'referentiel_commun[code]' => 'mut']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'MUT');
    }

    public function testReserveAuSuperAdmin(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/admin/referentiels');
        self::assertResponseStatusCodeSame(403);
    }
}
