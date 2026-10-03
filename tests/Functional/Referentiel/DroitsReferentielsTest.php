<?php

namespace App\Tests\Functional\Referentiel;

use App\Entity\Client;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matrice des droits (§ 2) appliquée aux référentiels.
 */
final class DroitsReferentielsTest extends AppWebTestCase
{
    private Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    public function testVendeurLectureSeuleDuCatalogue(): void
    {
        $this->connecter($this->officine->vendeur);

        foreach (['/produits', '/fournisseurs', '/clients', '/clients/nouveau'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        foreach (['/produits/nouveau', '/fournisseurs/nouveau', '/categories', '/etageres', '/parametres', '/imports/produits', '/imports/clients'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('GET', '/produits');
        self::assertSelectorNotExists('a[href="/produits/nouveau"]');
    }

    public function testAdjointGereLeCatalogueMaisPasLesParametres(): void
    {
        $this->connecter($this->officine->adjoint);

        foreach (['/produits/nouveau', '/fournisseurs/nouveau', '/categories', '/etageres', '/imports/produits'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        $this->client->request('GET', '/parametres');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeVendeurCreeUnClientMaisNePeutPasLeMarquerPrivilegie(): void
    {
        $crawler = $this->connecter($this->officine->vendeur)->request('GET', '/clients/nouveau');
        self::assertCount(0, $crawler->filter('#client_privilegie'));

        // Même en ajoutant le champ à la main, il est refusé.
        $this->client->request('POST', '/clients/nouveau', ['client' => [
            'nom' => 'Awa Diakité',
            'telephone' => '76 00 11 22',
            'privilegie' => '1',
            '_token' => $crawler->filter('#client__token')->attr('value'),
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'champs supplémentaires');

        $this->client->submitForm('Enregistrer', ['client[nom]' => 'Awa Diakité', 'client[telephone]' => '76 00 11 22']);
        $client = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Client::class)->findOneBy(['nom' => 'Awa Diakité']));
        self::assertInstanceOf(Client::class, $client);
        self::assertFalse($client->isPrivilegie());
        self::assertSame('+22376001122', $client->getTelephone());
    }

    public function testLAdjointMarqueUnClientPrivilegie(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/clients/nouveau');
        $this->client->submitForm('Enregistrer', ['client[nom]' => 'Client fidèle', 'client[privilegie]' => true]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Privilégié');
    }

    public function testLeVendeurNArchivePasDeClient(): void
    {
        $client = \App\Tests\Factory\ClientFactory::createOne(['pharmacie' => $this->officine->pharmacie]);
        $crawler = $this->connecter($this->officine->vendeur)->request('GET', \sprintf('/clients/%d/modifier', $client->getId()));

        self::assertCount(0, $crawler->filter('button:contains("Archiver")'));
        $this->client->request('POST', \sprintf('/clients/%d/archiver', $client->getId()), ['_token' => 'x']);
        self::assertFalse($this->client->getResponse()->isSuccessful());
        $actif = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Client::class, $client->getId())?->isActif());
        self::assertTrue($actif);
    }

    public function testLeVendeurNeVoitPasLePrixDAchat(): void
    {
        $produit = \App\Tests\Factory\ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixAchat' => 777, 'prixVente' => 1000]);

        $this->connecter($this->officine->vendeur)->request('GET', '/produits/'.$produit->getId());
        self::assertSelectorTextNotContains('main', "Prix d'achat");

        $this->connecter($this->officine->adjoint)->request('GET', '/produits/'.$produit->getId());
        self::assertSelectorTextContains('main', "777\u{00A0}FCFA");
    }

    public function testLectureSeuleQuandLAbonnementEstExpire(): void
    {
        $expiree = $this->creerOfficine(static fn ($f) => $f->echue(10));

        $this->connecter($expiree->proprietaire)->request('GET', '/produits');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/produits/nouveau"]');

        $this->client->request('GET', '/produits/nouveau');
        $this->client->submitForm('Enregistrer', ['produit[nomCommercial]' => 'Interdit', 'produit[prixVente]' => '100']);
        self::assertResponseRedirects();
    }
}
