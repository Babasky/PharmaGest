<?php

namespace App\Tests\Functional\Stock;

use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\Produit;
use App\Enum\TypeMouvement;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stock à zéro et rupture : au Mali, « rupture » veut dire que même le fournisseur n'en a plus.
 * Un stock à zéro a un fond rouge ; le badge « Rupture » n'arrive qu'après cinq jours à zéro (délai réglable).
 */
final class RuptureTest extends AppWebTestCase
{
    private Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    public function testLeBadgeRuptureNArriveQuApresCinqJoursAZero(): void
    {
        $p = $this->officine->pharmacie;
        $recent = $this->produitVendu('ZeroDepuisQuatreJours', 'today -4 days 09:00');
        $ancien = $this->produitVendu('ZeroDepuisCinqJours', 'today -5 days 18:00');
        $perime = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'PerimeDepuisSixJours', 'seuilAlerte' => 5]);
        LotFactory::createOne(['produit' => $perime, 'quantiteInitiale' => 3, 'datePeremption' => new \DateTimeImmutable('today -6 days'), 'dateReception' => new \DateTimeImmutable('today -60 days')]);
        // Lot périmé il y a huit jours puis détruit hier : la destruction ne remet pas le compteur à zéro.
        $detruit = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'PerimePuisDetruit', 'seuilAlerte' => 5]);
        $lot = LotFactory::createOne(['produit' => $detruit, 'quantiteInitiale' => 2, 'datePeremption' => new \DateTimeImmutable('today -8 days'), 'dateReception' => new \DateTimeImmutable('today -60 days')]);
        $this->vider($lot, TypeMouvement::Destruction, 'yesterday 10:00');
        ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'JamaisRecu', 'seuilAlerte' => 5]);
        $enStock = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'EnStock', 'seuilAlerte' => 5]);
        LotFactory::createOne(['produit' => $enStock, 'quantiteInitiale' => 30]);

        $crawler = $this->connecter($this->officine->vendeur)->request('GET', '/stock');
        $ligne = static fn (string $nom) => $crawler->filter('tbody tr:contains("'.$nom.'")');

        foreach (['ZeroDepuisQuatreJours', 'JamaisRecu'] as $nom) {
            self::assertStringContainsString('pg-stock-nul', (string) $ligne($nom)->attr('class'), $nom.' : stock à zéro, fond rouge.');
            self::assertStringNotContainsString('Rupture', $ligne($nom)->text(), $nom.' : pas encore de rupture.');
        }
        foreach (['ZeroDepuisCinqJours', 'PerimeDepuisSixJours', 'PerimePuisDetruit'] as $nom) {
            self::assertStringContainsString('pg-stock-nul', (string) $ligne($nom)->attr('class'), $nom);
            self::assertStringContainsString('Rupture', $ligne($nom)->text(), $nom.' : cinq jours ou plus à zéro.');
        }
        self::assertStringNotContainsString('Seuil', $ligne('ZeroDepuisQuatreJours')->text(), 'Le fond rouge remplace le badge « Seuil ».');
        self::assertNull($ligne('EnStock')->attr('class'));

        $this->client->request('GET', '/produits/'.$recent->getId());
        self::assertSelectorExists('#stock-disponible.pg-stock-nul-bloc');
        self::assertSelectorTextNotContains('#stock-disponible', 'Rupture');
        self::assertSelectorTextContains('#stock-disponible', 'à zéro depuis le '.(new \DateTimeImmutable('today -4 days'))->format('d/m/Y'));

        $this->client->request('GET', '/produits/'.$ancien->getId());
        self::assertSelectorTextContains('#stock-disponible', 'Rupture');

        $this->client->request('GET', '/stock/alertes');
        self::assertSelectorTextContains('tbody tr.pg-stock-nul:contains("ZeroDepuisCinqJours")', 'Rupture');
        self::assertSelectorTextNotContains('tbody tr.pg-stock-nul:contains("ZeroDepuisQuatreJours")', 'Rupture');
    }

    public function testDelaiReglableParLaPharmacieEtParProduit(): void
    {
        $this->produitVendu('ZeroDepuisTroisJours', 'today -3 days 10:00');
        $long = $this->produitVendu('DelaiPropreDixJours', 'today -6 days 10:00');

        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=regles');
        self::assertInputValueSame('parametres[joursAvantRupture]', '5', 'Cinq jours par défaut.');
        $this->client->submitForm('Enregistrer les règles', ['parametres[joursAvantRupture]' => '3']);
        self::assertResponseRedirects('/parametres?onglet=regles');

        $this->client->request('GET', '/produits/'.$long->getId().'/modifier');
        self::assertInputValueSame('produit[joursAvantRupture]', '', 'Vide : délai de la pharmacie.');
        $this->client->submitForm('Enregistrer', ['produit[joursAvantRupture]' => '10']);
        self::assertResponseRedirects('/produits/'.$long->getId());

        $crawler = $this->client->request('GET', '/stock');
        self::assertStringContainsString('Rupture', $crawler->filter('tbody tr:contains("ZeroDepuisTroisJours")')->text(), 'Délai de la pharmacie : 3 jours.');
        $ligne = $crawler->filter('tbody tr:contains("DelaiPropreDixJours")');
        self::assertStringContainsString('pg-stock-nul', (string) $ligne->attr('class'));
        self::assertStringNotContainsString('Rupture', $ligne->text(), 'Délai propre au produit : 10 jours.');

        $this->client->request('GET', '/parametres?onglet=regles');
        $this->client->submitForm('Enregistrer les règles', ['parametres[joursAvantRupture]' => '0']);
        self::assertResponseStatusCodeSame(422, 'Au moins un jour.');
    }

    public function testEnCaisse(): void
    {
        $this->produitVendu('CaisseRecent', 'today -1 day 12:00');
        $this->produitVendu('CaisseAncien', 'today -10 days 12:00');

        $this->connecter($this->officine->vendeur)->request('GET', '/caisse');
        $crawler = $this->client->request('GET', '/caisse?q=Caisse');
        self::assertResponseIsSuccessful();

        $recent = $crawler->filter('#resultats tr:contains("CaisseRecent")');
        self::assertStringContainsString('pg-stock-nul', (string) $recent->attr('class'));
        self::assertStringContainsString('0 en stock', $recent->text());
        self::assertStringNotContainsString('Rupture', $recent->text());
        self::assertStringContainsString('Rupture', $crawler->filter('#resultats tr:contains("CaisseAncien")')->text());
    }

    private function produitVendu(string $nom, string $quand): Produit
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => $nom, 'seuilAlerte' => 5]);
        $lot = LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 4, 'dateReception' => new \DateTimeImmutable('today -30 days')]);
        $this->vider($lot, TypeMouvement::Vente, $quand);

        return $produit;
    }

    /** Sortie de tout le lot, datée. */
    private function vider(Lot $lot, TypeMouvement $type, string $quand): void
    {
        $id = $lot->getId();
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($id, $type, $quand): void {
            $lot = $em->find(Lot::class, $id);
            self::assertInstanceOf(Lot::class, $lot);
            $quantite = $lot->getQuantiteRestante();
            $lot->modifierQuantite(-$quantite);
            $em->persist(new MouvementStock($lot, $type, -$quantite, new \DateTimeImmutable($quand)));
            $em->flush();
        });
    }
}
