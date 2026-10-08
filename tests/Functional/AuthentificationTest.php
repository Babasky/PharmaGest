<?php

namespace App\Tests\Functional;

use App\Entity\Utilisateur;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\UtilisateurFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class AuthentificationTest extends AppWebTestCase
{
    public function testUnVisiteurEstRedirigeVersLaConnexion(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/connexion');
    }

    public function testConnexionReussie(): void
    {
        $officine = $this->creerOfficine();

        $this->seConnecter($officine->vendeur->getEmail());

        // Le vendeur arrive sur l'écran de vente, pas sur le tableau de bord.
        self::assertResponseRedirects('/caisse');
        $this->client->followRedirect();
        self::assertSelectorTextContains('header', $officine->vendeur->getNom());
        self::assertSelectorTextContains('header', $officine->pharmacie->getNom());

        $derniereConnexion = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Utilisateur::class, $officine->vendeur->getId())?->getDerniereConnexion());
        self::assertNotNull($derniereConnexion);
    }

    public function testMauvaisMotDePasse(): void
    {
        $officine = $this->creerOfficine();

        $this->seConnecter($officine->vendeur->getEmail(), 'mauvais');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Identifiants invalides');
    }

    public function testUnCompteDesactiveNePeutPlusSeConnecter(): void
    {
        $vendeur = UtilisateurFactory::createOne(['actif' => false]);
        AffectationFactory::createOne(['utilisateur' => $vendeur]);

        $this->seConnecter($vendeur->getEmail());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Votre compte est désactivé');
    }

    public function testUnAccesRetireALaPharmacieEmpecheLaConnexion(): void
    {
        $vendeur = UtilisateurFactory::createOne();
        AffectationFactory::createOne(['utilisateur' => $vendeur, 'actif' => false]);

        $this->seConnecter($vendeur->getEmail());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'accès à la pharmacie a été désactivé');
    }

    public function testUnCompteNonActiveEstInviteAUtiliserSonLien(): void
    {
        $proprietaire = UtilisateurFactory::new()->nonActive()->create(['role' => Utilisateur::ROLE_PROPRIETAIRE]);
        AffectationFactory::createOne(['utilisateur' => $proprietaire, 'pharmacie' => PharmacieFactory::new()]);

        $this->seConnecter($proprietaire->getEmail(), 'nimportequoi');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'pas encore activé');
    }

    public function testLimitationDesTentatives(): void
    {
        $officine = $this->creerOfficine();

        for ($i = 0; $i < 5; ++$i) {
            $this->seConnecter($officine->vendeur->getEmail(), 'mauvais-'.$i);
        }
        // 6e tentative, même avec le bon mot de passe : bloquée.
        $this->seConnecter($officine->vendeur->getEmail());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Trop de tentatives de connexion');
    }

    public function testDeconnexion(): void
    {
        $officine = $this->creerOfficine();
        $crawler = $this->connecter($officine->vendeur)->request('GET', '/');

        $lien = $crawler->selectLink('Se déconnecter')->link();
        self::assertStringNotContainsString('csrf-token', $lien->getUri(), 'Le lien doit porter un vrai jeton de session.');
        $this->client->click($lien);
        self::assertResponseRedirects('/connexion');

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion');
    }

    public function testLeSuperAdminArriveSurLaVueGlobale(): void
    {
        $admin = $this->creerSuperAdmin();

        $this->seConnecter($admin->getEmail());
        $this->client->followRedirect();

        self::assertResponseRedirects('/admin');
    }
}
