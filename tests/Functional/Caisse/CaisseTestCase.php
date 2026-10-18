<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Security\CodePin;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Outils communs aux tests de la caisse : ouverture de session et actions de l'écran de caisse.
 * Chaque vente se valide avec le code PIN du vendeur : les helpers le saisissent pour l'utilisateur connecté.
 */
abstract class CaisseTestCase extends AppWebTestCase
{
    /** Code PIN donné d'office aux utilisateurs qui vendent dans les tests. */
    protected const PIN = '2468';

    protected Officine $officine;

    private ?Utilisateur $connecte = null;

    /** @var array<int, string> code PIN connu, par utilisateur */
    private array $pins = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    protected function connecter(Utilisateur $utilisateur): KernelBrowser
    {
        $this->connecte = $utilisateur;

        return parent::connecter($utilisateur);
    }

    protected function definirPin(Utilisateur $utilisateur, string $code): void
    {
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($utilisateur, $code): void {
            $membre = $em->find(Utilisateur::class, $utilisateur->getId());
            self::assertInstanceOf(Utilisateur::class, $membre);
            self::getContainer()->get(CodePin::class)->definir($membre, $code);
        });
        $this->pins[(int) $utilisateur->getId()] = $code;
    }

    /**
     * Vendeur et code PIN qui valident la vente : l'utilisateur connecté, avec son code PIN (défini au besoin).
     *
     * @return array{vendeur: ?int, pin_vendeur: string}
     */
    protected function pinVendeur(?Utilisateur $vendeur = null): array
    {
        $vendeur ??= $this->connecte;
        self::assertNotNull($vendeur, 'Connectez un utilisateur avant de vendre.');
        if (!isset($this->pins[(int) $vendeur->getId()])) {
            $this->definirPin($vendeur, self::PIN);
        }

        return ['vendeur' => $vendeur->getId(), 'pin_vendeur' => $this->pins[(int) $vendeur->getId()]];
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

        return (string) $crawler->filter('form#type-vente input[name="_token"]')->attr('value');
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
    protected function encaisser(array $paiement = [], ?string $codePin = null, ?Utilisateur $vendeur = null): void
    {
        $this->poster('/caisse/encaisser', ['paiement' => $paiement, 'code_pin' => $codePin, ...$this->pinVendeur($vendeur)]);
    }

    /** « Envoyer à la caisse », validé avec le code PIN de l'utilisateur connecté. */
    protected function envoyerALaCaisse(?string $codePin = null): void
    {
        $this->poster('/caisse/envoyer', ['code_pin' => $codePin, ...$this->pinVendeur()]);
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
