<?php

namespace App\Tests\Functional\Finition;

use App\EventSubscriber\InactiviteSessionListener;
use App\Tests\Functional\Caisse\CaisseTestCase;

/**
 * Revue de sécurité (docs/securite.md) : en-têtes HTTP, cookie de session, délai d'inactivité propre à la caisse.
 */
final class SecuriteTest extends CaisseTestCase
{
    public function testEnTetesDeSecurite(): void
    {
        $this->client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
        self::assertResponseHeaderSame('Referrer-Policy', 'same-origin');
        self::assertStringContainsString("frame-ancestors 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertResponseNotHasHeader('Strict-Transport-Security');

        $this->client->request('GET', '/connexion', server: ['HTTPS' => 'on']);
        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function testDelaiDInactiviteDeLaCaisseRegleParLeProprietaire(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=regles');
        $this->client->submitForm('Enregistrer les règles', ['parametres[inactiviteCaisse]' => '120']);
        self::assertResponseRedirects();

        // Sans caisse ouverte : délai général (30 min).
        $this->connecter($this->officine->vendeur)->request('GET', '/');
        self::assertFalse($this->client->getRequest()->getSession()->has(InactiviteSessionListener::CLE_DELAI));

        // Caisse ouverte : le délai de la caisse s'applique aux requêtes suivantes.
        $this->ouvrirCaisse($this->officine->vendeur);
        self::assertSame(7200, $this->client->getRequest()->getSession()->get(InactiviteSessionListener::CLE_DELAI));

        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=regles');
        $this->client->submitForm('Enregistrer les règles', ['parametres[inactiviteCaisse]' => '1']);
        self::assertResponseStatusCodeSame(422);
    }
}
