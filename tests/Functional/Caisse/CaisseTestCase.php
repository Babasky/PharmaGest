<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Outils communs aux tests de la caisse : ouverture de session et actions de l'écran de caisse.
 */
abstract class CaisseTestCase extends AppWebTestCase
{
    protected Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    protected function ouvrirCaisse(Utilisateur $utilisateur, int $fond = 0): void
    {
        $this->connecter($utilisateur)->request('GET', '/caisse');
        $this->client->submitForm('Ouvrir la caisse', ['fond' => (string) $fond]);
        self::assertResponseRedirects('/caisse');
        $this->client->followRedirect();
    }

    /** Jeton CSRF des formulaires de la caisse. */
    protected function jeton(): string
    {
        $crawler = $this->client->request('GET', '/caisse');

        return (string) $crawler->filter('form[action="/caisse/scanner"] input[name="_token"]')->attr('value');
    }

    /**
     * @param array<string, mixed> $donnees
     */
    protected function poster(string $url, array $donnees = []): void
    {
        $this->client->request('POST', $url, ['_token' => $this->jeton(), ...$donnees]);
    }

    protected function ajouter(Produit $produit, int $quantite = 1): void
    {
        $this->poster('/caisse/ajouter/'.$produit->getId(), ['quantite' => $quantite]);
        self::assertResponseRedirects('/caisse');
    }

    /**
     * @param array<string, mixed> $paiement
     */
    protected function encaisser(array $paiement = [], ?string $codePin = null): void
    {
        $this->poster('/caisse/encaisser', ['paiement' => $paiement, 'code_pin' => $codePin]);
    }

    /** Message d'erreur affiché après la dernière action. */
    protected function erreur(): string
    {
        $crawler = $this->client->followRedirect();

        return $crawler->filter('.alert-danger')->count() > 0 ? $crawler->filter('.alert-danger')->text() : '';
    }

    protected function derniereVente(): Vente
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em): Vente {
            $vente = $em->getRepository(Vente::class)->findOneBy([], ['id' => 'DESC']);
            self::assertInstanceOf(Vente::class, $vente);

            return $vente;
        });
    }
}
