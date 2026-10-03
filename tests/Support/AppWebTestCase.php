<?php

namespace App\Tests\Support;

use App\Entity\Utilisateur;
use App\Tenant\TenantContext;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\UtilisateurFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Base des tests fonctionnels. Chaque test tourne dans une transaction annulée (DAMA).
 *
 * Attention : créer les données AVANT la première requête. Après une requête, le conteneur garde
 * le filtre tenant de l'utilisateur connecté ; utiliser {@see self::sansFiltre()} pour relire la base.
 */
abstract class AppWebTestCase extends WebTestCase
{
    use Factories;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Les compteurs de tentatives de connexion sont en cache disque : chaque test repart de zéro.
        /** @var \Psr\Cache\CacheItemPoolInterface $limiteurs */
        $limiteurs = self::getContainer()->get('cache.rate_limiter');
        $limiteurs->clear();
    }

    /**
     * @param callable(PharmacieFactory): PharmacieFactory|null $personnaliser
     */
    protected function creerOfficine(?callable $personnaliser = null): Officine
    {
        $factory = PharmacieFactory::new();
        if (null !== $personnaliser) {
            $factory = $personnaliser($factory);
        }
        $pharmacie = $factory->create();

        $proprietaire = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_PROPRIETAIRE, 'nom' => 'Titulaire '.$pharmacie->getNom()]);
        $adjoint = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_ADJOINT]);
        $vendeur = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_VENDEUR]);

        AffectationFactory::createOne(['utilisateur' => $proprietaire, 'pharmacie' => $pharmacie]);
        $affAdjoint = AffectationFactory::createOne(['utilisateur' => $adjoint, 'pharmacie' => $pharmacie]);
        $affVendeur = AffectationFactory::createOne(['utilisateur' => $vendeur, 'pharmacie' => $pharmacie]);

        return new Officine($pharmacie, $proprietaire, $adjoint, $vendeur, $affAdjoint, $affVendeur);
    }

    protected function creerSuperAdmin(): Utilisateur
    {
        return UtilisateurFactory::new()->superAdmin()->create();
    }

    protected function connecter(Utilisateur $utilisateur): KernelBrowser
    {
        $this->viderGestionnaire();
        $this->client->loginUser($utilisateur);

        return $this->client;
    }

    /**
     * Connexion par le vrai formulaire (pare-feu, vérification du compte, limitation des tentatives).
     */
    protected function seConnecter(string $email, string $motDePasse = UtilisateurFactory::MOT_DE_PASSE): void
    {
        $this->viderGestionnaire();
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => $email, 'mot_de_passe' => $motDePasse]);
    }

    /**
     * Comme au début d'une vraie requête : aucune entité déjà chargée en mémoire
     * (sinon find() renverrait l'entité sans passer par le filtre SQL).
     */
    protected function viderGestionnaire(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
    }

    /**
     * @template T
     *
     * @param callable(EntityManagerInterface): T $lecture
     *
     * @return T
     */
    protected function sansFiltre(callable $lecture): mixed
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var TenantContext $tenant */
        $tenant = self::getContainer()->get(TenantContext::class);

        return $tenant->sansFiltre(static function () use ($em, $lecture) {
            $em->clear();

            return $lecture($em);
        });
    }

    /**
     * Lien (href) du premier bouton de l'email HTML envoyé.
     */
    protected function lienDansDernierEmail(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $email, 'Aucun email envoyé.');
        $html = (string) $email->getHtmlBody();
        self::assertSame(1, preg_match('#href="(http[^"]+)"#', $html, $m), 'Aucun lien dans l\'email.');

        return html_entity_decode($m[1]);
    }
}
