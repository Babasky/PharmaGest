<?php

namespace App\Tests\Functional\Referentiel;

use App\Entity\Client;
use App\Entity\Produit;
use App\Tenant\TenantContext;
use App\Tests\Factory\CategorieFactory;
use App\Tests\Factory\ClientFactory;
use App\Tests\Factory\EtagereFactory;
use App\Tests\Factory\FournisseurFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * R-01 appliqué aux référentiels : aucune donnée de la pharmacie B n'est lisible, modifiable ou
 * archivable depuis la pharmacie A, même en changeant l'identifiant dans l'URL.
 */
final class IsolationReferentielsTest extends AppWebTestCase
{
    private Officine $a;
    private Officine $b;

    /** @var array<string, int> */
    private array $idsB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->creerOfficine();
        $this->b = $this->creerOfficine();

        $categorie = CategorieFactory::createOne(['pharmacie' => $this->b->pharmacie, 'nom' => 'Secret B']);
        $this->idsB = [
            'categorie' => $categorie->getId(),
            'etagere' => EtagereFactory::createOne(['pharmacie' => $this->b->pharmacie, 'code' => 'B-SECRET'])->getId(),
            'fournisseur' => FournisseurFactory::createOne(['pharmacie' => $this->b->pharmacie, 'nom' => 'Grossiste B'])->getId(),
            'produit' => ProduitFactory::createOne(['pharmacie' => $this->b->pharmacie, 'nomCommercial' => 'ProduitDeB', 'categorie' => $categorie])->getId(),
            'client' => ClientFactory::createOne(['pharmacie' => $this->b->pharmacie, 'nom' => 'Client de B'])->getId(),
        ];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function urls(): iterable
    {
        yield 'voir un produit' => ['GET', '/produits/%d', 'produit'];
        yield 'modifier un produit' => ['GET', '/produits/%d/modifier', 'produit'];
        yield 'enregistrer un produit' => ['POST', '/produits/%d/modifier', 'produit'];
        yield 'archiver un produit' => ['POST', '/produits/%d/archiver', 'produit'];
        yield 'modifier une catégorie' => ['GET', '/categories/%d/modifier', 'categorie'];
        yield 'archiver une catégorie' => ['POST', '/categories/%d/archiver', 'categorie'];
        yield 'modifier une étagère' => ['GET', '/etageres/%d/modifier', 'etagere'];
        yield 'archiver une étagère' => ['POST', '/etageres/%d/archiver', 'etagere'];
        yield 'modifier un fournisseur' => ['GET', '/fournisseurs/%d/modifier', 'fournisseur'];
        yield 'archiver un fournisseur' => ['POST', '/fournisseurs/%d/archiver', 'fournisseur'];
        yield 'modifier un client' => ['GET', '/clients/%d/modifier', 'client'];
        yield 'enregistrer un client' => ['POST', '/clients/%d/modifier', 'client'];
        yield 'archiver un client' => ['POST', '/clients/%d/archiver', 'client'];
    }

    #[DataProvider('urls')]
    public function testDonneeDUneAutrePharmacieIntrouvable(string $methode, string $url, string $ressource): void
    {
        $this->connecter($this->a->proprietaire)->request($methode, \sprintf($url, $this->idsB[$ressource]), ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLesListesNeMontrentQueLaPharmacieCourante(): void
    {
        ProduitFactory::createOne(['pharmacie' => $this->a->pharmacie, 'nomCommercial' => 'ProduitDeA']);
        $this->connecter($this->a->proprietaire);

        foreach (['/produits' => 'ProduitDeB', '/categories' => 'Secret B', '/etageres' => 'B-SECRET', '/fournisseurs' => 'Grossiste B', '/clients' => 'Client de B'] as $url => $intrus) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('main', $intrus, $url);
        }
        $this->client->request('GET', '/produits?q=Produit');
        self::assertSelectorTextContains('main', 'ProduitDeA');
    }

    public function testLesListesDeChoixNeProposentPasLesDonneesDUneAutrePharmacie(): void
    {
        $crawler = $this->connecter($this->a->proprietaire)->request('GET', '/produits/nouveau');

        self::assertCount(0, $crawler->filter(\sprintf('#produit_categorie option[value="%d"]', $this->idsB['categorie'])));
        self::assertCount(0, $crawler->filter(\sprintf('#produit_etagere option[value="%d"]', $this->idsB['etagere'])));
        self::assertCount(0, $crawler->filter(\sprintf('#produit_fournisseurHabituel option[value="%d"]', $this->idsB['fournisseur'])));
    }

    public function testUnFormulaireFalsifieNePeutPasRattacherUneDonneeDeB(): void
    {
        $this->connecter($this->a->proprietaire)->request('GET', '/produits/nouveau');
        $formulaire = $this->client->getCrawler()->selectButton('Enregistrer')->form();
        $valeurs = $formulaire->getPhpValues();
        $valeurs['produit']['nomCommercial'] = 'Falsifié';
        $valeurs['produit']['prixVente'] = '1000';
        $valeurs['produit']['categorie'] = (string) $this->idsB['categorie'];

        $this->client->request('POST', '/produits/nouveau', $valeurs);

        self::assertResponseStatusCodeSame(422);
        $cree = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Produit::class)->findOneBy(['nomCommercial' => 'Falsifié']));
        self::assertNull($cree);
    }

    public function testLaBaseRefuseUnLienEntreDeuxPharmacies(): void
    {
        /** @var TenantContext $tenant */
        $tenant = self::getContainer()->get(TenantContext::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tenant->forcer($this->a->pharmacie);

        $produit = (new Produit())->setNomCommercial('Lien interdit')->setPrixVente(500)
            ->setCategorie($em->getReference(\App\Entity\Categorie::class, $this->idsB['categorie']));
        $em->persist($produit);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('autre pharmacie');
        $em->flush();
    }

    public function testLeClientDeBResteIntact(): void
    {
        $this->connecter($this->a->proprietaire)->request('POST', \sprintf('/clients/%d/modifier', $this->idsB['client']), [
            'client' => ['nom' => 'Piraté'],
        ]);

        $nom = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->find(Client::class, $this->idsB['client'])?->getNom());
        self::assertSame('Client de B', $nom);
    }
}
