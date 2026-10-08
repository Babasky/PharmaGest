<?php

namespace App\Tests\Functional\Commande;

use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Tests\Factory\FournisseurFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Outils des tests des commandes : deux fournisseurs et un petit catalogue, dont des produits sous le seuil.
 *
 * - Doliprane (PPM) : stock 4, seuil 10, stock max 60 → 56 suggérés ;
 * - Coartem (PPM) : rupture, seuil 5, sans stock max → 10 suggérés (double du seuil) ;
 * - Ibuprofène (PPM) : stock 40, au-dessus du seuil → pas suggéré ;
 * - Smecta (Laborex) : stock 2, seuil 10, stock max 30 → 28 suggérés chez Laborex.
 */
abstract class CommandeTestCase extends AppWebTestCase
{
    protected Officine $officine;
    protected Fournisseur $ppm;
    protected Fournisseur $laborex;
    protected Produit $doliprane;
    protected Produit $coartem;
    protected Produit $ibuprofene;
    protected Produit $smecta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine(static fn ($f) => $f->with(['nom' => 'Pharmacie du Fleuve', 'email' => 'contact@fleuve.example']));
        $p = $this->officine->pharmacie;
        $this->ppm = FournisseurFactory::createOne(['pharmacie' => $p, 'nom' => 'PPM', 'contact' => 'Service commandes', 'email' => 'commandes@ppm.example']);
        $this->laborex = FournisseurFactory::createOne(['pharmacie' => $p, 'nom' => 'Laborex Mali', 'email' => null]);

        $this->doliprane = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Doliprane', 'dci' => 'Paracétamol', 'dosage' => '500 mg', 'conditionnement' => 'Boîte de 16', 'prixAchat' => 1150, 'seuilAlerte' => 10, 'stockMax' => 60, 'fournisseurHabituel' => $this->ppm]);
        $this->coartem = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Coartem', 'prixAchat' => 2900, 'seuilAlerte' => 5, 'stockMax' => null, 'fournisseurHabituel' => $this->ppm]);
        $this->ibuprofene = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Ibuprofène', 'prixAchat' => 1400, 'seuilAlerte' => 10, 'stockMax' => 60, 'fournisseurHabituel' => $this->ppm]);
        $this->smecta = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Smecta', 'prixAchat' => 2300, 'seuilAlerte' => 10, 'stockMax' => 30, 'fournisseurHabituel' => $this->laborex]);

        LotFactory::createOne(['produit' => $this->doliprane, 'quantiteInitiale' => 4]);
        LotFactory::createOne(['produit' => $this->ibuprofene, 'quantiteInitiale' => 40]);
        LotFactory::createOne(['produit' => $this->smecta, 'quantiteInitiale' => 2]);
    }

    protected function commande(?int $id = null): Commande
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($id): Commande {
            $commande = null === $id ? $em->getRepository(Commande::class)->findOneBy([], ['id' => 'DESC']) : $em->find(Commande::class, $id);
            self::assertInstanceOf(Commande::class, $commande);
            $commande->getLignes()->toArray();
            $commande->getEnvois()->toArray();
            $commande->getReceptions()->toArray();

            return $commande;
        });
    }

    /**
     * Brouillon créé depuis la liste des commandes, puis produits ajoutés par la recherche.
     *
     * @param list<array{0: Produit, 1: int}> $produits produit et quantité
     */
    protected function creerBrouillon(Fournisseur $fournisseur, array $produits): int
    {
        $this->client->request('GET', '/commandes');
        $this->client->submitForm('Créer le brouillon', ['fournisseur' => (string) $fournisseur->getId()]);
        self::assertResponseRedirects();
        $id = $this->commande()->getId();
        foreach ($produits as [$produit, $quantite]) {
            $this->client->request('GET', '/commandes/'.$id, ['q' => $produit->getNomCommercial()]);
            $this->client->submit($this->client->getCrawler()->filter('#resultats form')->first()->form(['quantite' => (string) $quantite]));
            self::assertResponseRedirects('/commandes/'.$id);
        }

        return (int) $id;
    }

    /**
     * @param array<string, string> $valeurs
     */
    protected function cliquer(string $bouton, array $valeurs = []): void
    {
        $this->client->submitForm($bouton, $valeurs);
        self::assertResponseRedirects();
        $this->client->followRedirect();
    }
}
