<?php

namespace App\Tests\Functional;

use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Demande du 24/10/2026 : chaque champ mot de passe (ou code PIN) a une icône « œil » pour afficher ou masquer
 * la saisie. Le bouton est rendu par les gabarits ; assets/mot_de_passe.js fait la bascule.
 */
final class AfficherMotDePasseTest extends AppWebTestCase
{
    public function testConnexion(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pg-mdp #mot_de_passe + button[type="button"][data-pg-mdp][aria-label="Afficher le mot de passe"]');
        self::assertSelectorExists('.pg-mdp [data-pg-mdp] i.bi-eye');
        // Le script de bascule est chargé avec l'application (espace pharmacie et plateforme).
        self::assertStringContainsString('mot_de_passe', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('input[type="password"]'));
    }

    public function testNouveauMotDePasseDepuisLeLien(): void
    {
        $officine = $this->creerOfficine();
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['mot_de_passe_oublie[email]' => $officine->vendeur->getEmail()]);

        $crawler = $this->client->request('GET', $this->lienDansDernierEmail());

        self::assertResponseIsSuccessful();
        $this->assertChaqueChampEstEquipe($crawler, 2);

        // Le bouton n'empêche pas l'envoi du formulaire.
        $this->client->submitForm('Enregistrer le mot de passe', [
            'nouveau_mot_de_passe[motDePasse][first]' => 'nouveau-secret-42',
            'nouveau_mot_de_passe[motDePasse][second]' => 'nouveau-secret-42',
        ]);
        self::assertResponseRedirects('/connexion');
    }

    public function testCodePinEtNouveauMembre(): void
    {
        $officine = $this->creerOfficine();

        $crawler = $this->connecter($officine->vendeur)->request('GET', '/mon-code-pin');
        self::assertResponseIsSuccessful();
        $this->assertChaqueChampEstEquipe($crawler, 3);

        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/equipe/nouveau');
        self::assertResponseIsSuccessful();
        $this->assertChaqueChampEstEquipe($crawler, 1);
    }

    private function assertChaqueChampEstEquipe(Crawler $crawler, int $attendus): void
    {
        $champs = $crawler->filter('input[type="password"]');
        self::assertCount($attendus, $champs);
        self::assertCount($attendus, $crawler->filter('.pg-mdp > input[type="password"] + button[data-pg-mdp]'));
    }
}
