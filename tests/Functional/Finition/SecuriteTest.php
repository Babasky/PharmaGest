<?php

namespace App\Tests\Functional\Finition;

use App\Entity\Notification;
use App\Enum\TypeNotification;
use App\EventSubscriber\InactiviteSessionListener;
use App\Tests\Functional\Caisse\CaisseTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Revue de sécurité (docs/securite.md) : en-têtes HTTP, cookie de session, délai d'inactivité propre à la caisse,
 * et non-régression des failles corrigées lors des tests de vulnérabilité du 11/10/2026.
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

    /**
     * Un en-tête Host forgé ne doit pas fabriquer de lien de réinitialisation vers un autre site
     * (le lien signé, rejoué sur le vrai serveur avec le même Host, donnerait le compte).
     */
    public function testUnHoteInconnuEstRefuseEtLesLiensEnvoyesRestentSurLApplication(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['mot_de_passe_oublie[email]' => $this->officine->vendeur->getEmail()]);
        self::assertStringStartsWith('http://localhost/compte/reinitialisation/', $this->lienDansDernierEmail());

        $this->client->request('GET', 'http://pirate.example/mot-de-passe-oublie');
        self::assertResponseStatusCodeSame(400);
    }

    public function testUneNotificationNOuvreJamaisUnAutreSite(): void
    {
        $vendeur = $this->officine->proprietaire;
        $ids = $this->sansFiltre(function (EntityManagerInterface $em) use ($vendeur): array {
            $pharmacie = $em->find(\App\Entity\Pharmacie::class, $this->officine->pharmacie->getId());
            $utilisateur = $em->find(\App\Entity\Utilisateur::class, $vendeur->getId());
            \assert(null !== $pharmacie && null !== $utilisateur);
            $ids = [];
            foreach (['/\\pirate.example', '//pirate.example', "/\t/pirate.example", 'https://pirate.example'] as $i => $lien) {
                $notification = new Notification($pharmacie, $utilisateur, TypeNotification::Rupture, 'Test', $lien, 'test-'.$i, new \DateTimeImmutable());
                $em->persist($notification);
                $em->flush();
                $ids[] = $notification->getId();
            }

            return $ids;
        });

        $this->connecter($vendeur);
        foreach ($ids as $id) {
            $this->client->request('GET', '/notifications/'.$id);
            self::assertResponseRedirects('/notifications');
        }
    }
}
