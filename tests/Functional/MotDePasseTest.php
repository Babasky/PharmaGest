<?php

namespace App\Tests\Functional;

use App\Entity\Utilisateur;
use App\Tests\Support\AppWebTestCase;

/**
 * Mot de passe oublié (PH-04) : lien signé, valable 1 h, à usage unique.
 */
final class MotDePasseTest extends AppWebTestCase
{
    public function testReinitialisationComplete(): void
    {
        $officine = $this->creerOfficine();

        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['mot_de_passe_oublie[email]' => strtoupper($officine->vendeur->getEmail())]);
        self::assertResponseRedirects('/connexion');
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', $officine->vendeur->getEmail());

        $lien = $this->lienDansDernierEmail();
        $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Enregistrer le mot de passe', [
            'nouveau_mot_de_passe[motDePasse][first]' => 'nouveau-secret-42',
            'nouveau_mot_de_passe[motDePasse][second]' => 'nouveau-secret-42',
        ]);
        self::assertResponseRedirects('/connexion');

        // Le lien ne sert qu'une fois.
        $this->client->request('GET', $lien);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('main', 'déjà été utilisé');

        // Ancien mot de passe refusé, nouveau accepté.
        $this->seConnecter($officine->vendeur->getEmail());
        self::assertResponseRedirects('/connexion');
        $this->seConnecter($officine->vendeur->getEmail(), 'nouveau-secret-42');
        self::assertResponseRedirects('/caisse');
    }

    public function testEmailInconnuMemeReponseSansEnvoi(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['mot_de_passe_oublie[email]' => 'inconnu@test.ml']);

        self::assertResponseRedirects('/connexion');
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Si un compte actif correspond');
    }

    public function testLienFalsifie(): void
    {
        $officine = $this->creerOfficine();

        $this->client->request('GET', \sprintf('/compte/reinitialisation/%d/%s', $officine->vendeur->getId(), $officine->vendeur->getEmpreinteMotDePasse()));

        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('main', 'invalide');
    }

    public function testMotDePasseTropCourt(): void
    {
        $officine = $this->creerOfficine();
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['mot_de_passe_oublie[email]' => $officine->vendeur->getEmail()]);

        $this->client->request('GET', $this->lienDansDernierEmail());
        $this->client->submitForm('Enregistrer le mot de passe', [
            'nouveau_mot_de_passe[motDePasse][first]' => 'court',
            'nouveau_mot_de_passe[motDePasse][second]' => 'court',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'au moins 8 caractères');
    }

    public function testUnSuperAdminPeutAussiReinitialiser(): void
    {
        $admin = $this->creerSuperAdmin();
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['mot_de_passe_oublie[email]' => $admin->getEmail()]);

        self::assertQueuedEmailCount(1);
        self::assertSame(Utilisateur::ROLE_SUPER_ADMIN, $admin->getRole());
    }
}
