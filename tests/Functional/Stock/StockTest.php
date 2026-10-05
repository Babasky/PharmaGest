<?php

namespace App\Tests\Functional\Stock;

use App\Entity\JournalAudit;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Enum\TypeMouvement;
use App\Tests\Factory\FournisseurFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lots, mouvements, fiche produit, alertes et valorisation (ST-01, ST-04, ST-05, ST-07, ST-08).
 */
final class StockTest extends AppWebTestCase
{
    private Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    public function testEntreeDeStockDepuisLaFicheProduit(): void
    {
        $fournisseur = FournisseurFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'PPM']);
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Doliprane', 'prixAchat' => 1150, 'fournisseurHabituel' => $fournisseur]);
        $peremption = new \DateTimeImmutable('today +18 months');

        $this->connecter($this->officine->adjoint)->request('GET', '/produits/'.$produit->getId());
        $this->client->clickLink('Entrée de stock');
        self::assertInputValueSame('entree_stock[prixAchat]', '1150', 'Le prix de référence est proposé.');
        $this->client->submitForm('Entrer en stock', [
            'entree_stock[numero]' => 'DP2401',
            'entree_stock[datePeremption]' => $peremption->format('Y-m-d'),
            'entree_stock[quantite]' => '24',
            'entree_stock[prixAchat]' => '1100',
            'entree_stock[motif]' => 'Stock initial',
        ]);

        self::assertResponseRedirects('/produits/'.$produit->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '24 unité(s) de Doliprane entrée(s) en stock (lot DP2401)');
        self::assertSelectorTextContains('#stock-disponible', '24');
        self::assertSelectorTextContains('#lots', 'DP2401');
        self::assertSelectorTextContains('#lots', $peremption->format('d/m/Y'));
        self::assertSelectorTextContains('#lots', 'PPM');
        self::assertSelectorTextContains('#mouvements', 'Entrée de stock');
        self::assertSelectorTextContains('#mouvements', 'Stock initial');

        $this->sansFiltre(function (EntityManagerInterface $em): void {
            $lot = $em->getRepository(Lot::class)->findOneBy(['numero' => 'DP2401']);
            self::assertInstanceOf(Lot::class, $lot);
            self::assertSame([24, 24, 1100], [$lot->getQuantiteInitiale(), $lot->getQuantiteRestante(), $lot->getPrixAchat()]);
            self::assertSame($this->officine->pharmacie->getId(), $lot->getPharmacie()?->getId());
            self::assertSame('stock.entree', $em->getRepository(JournalAudit::class)->findOneBy([], ['id' => 'DESC'])?->getAction());
        });
    }

    public function testUnLotDejaPerimeEstRefuse(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie]);

        $this->connecter($this->officine->adjoint)->request('GET', '/stock/produit/'.$produit->getId().'/entree');
        $this->client->submitForm('Entrer en stock', [
            'entree_stock[numero]' => 'X1',
            'entree_stock[datePeremption]' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'entree_stock[quantite]' => '5',
            'entree_stock[motif]' => 'Don',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Ce lot est déjà périmé');
    }

    public function testAjustementMotiveEtDestructionDUnLotPerime(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie]);
        $lot = LotFactory::createOne(['produit' => $produit, 'numero' => 'AJ1', 'quantiteInitiale' => 10]);
        $perime = LotFactory::createOne(['produit' => $produit, 'numero' => 'PER1', 'quantiteInitiale' => 4, 'datePeremption' => new \DateTimeImmutable('yesterday')]);

        $this->connecter($this->officine->proprietaire)->request('GET', '/stock/lot/'.$lot->getId().'/ajuster');
        $this->client->submitForm('Ajuster', ['operation_lot[quantite]' => '7', 'operation_lot[motif]' => '']);
        self::assertResponseStatusCodeSame(422, 'Le motif est obligatoire.');

        $this->client->submitForm('Ajuster', ['operation_lot[quantite]' => '7', 'operation_lot[motif]' => 'Trois boîtes cassées']);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Lot AJ1 ajusté de 10 à 7 unité(s)');
        self::assertSelectorTextContains('#stock-disponible', '7', 'Le lot périmé ne compte pas dans le stock (RG-03).');
        self::assertSelectorTextContains('#lots tr.table-danger', 'PER1');

        $this->client->click($crawler->filter('#lots tr.table-danger')->selectLink('Détruire')->link());
        $this->client->submitForm('Détruire', ['operation_lot[quantite]' => '4', 'operation_lot[motif]' => 'Périmé']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('#lots', 'PER1', 'Un lot vide n\'est plus listé.');
        self::assertSelectorTextContains('#mouvements', 'Destruction');

        $this->sansFiltre(function (EntityManagerInterface $em): void {
            $types = array_map(static fn (MouvementStock $m) => [$m->getType(), $m->getQuantite(), $m->getUtilisateur()?->getId()], $em->getRepository(MouvementStock::class)->findBy([], ['id' => 'ASC']));
            self::assertSame([
                [TypeMouvement::Ajustement, -3, $this->officine->proprietaire->getId()],
                [TypeMouvement::Destruction, -4, $this->officine->proprietaire->getId()],
            ], $types);
            $actions = $em->createQuery('SELECT j.action FROM App\Entity\JournalAudit j WHERE j.pharmacie = :p ORDER BY j.id')
                ->setParameter('p', $this->officine->pharmacie->getId())->getSingleColumnResult();
            self::assertSame(['stock.ajustement', 'stock.destruction'], $actions);
        });
    }

    public function testDroitsDuVendeur(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixAchat' => 4321]);
        $lot = LotFactory::createOne(['produit' => $produit, 'prixAchat' => 4321]);

        $this->connecter($this->officine->vendeur);
        foreach (['/stock', '/stock/alertes', '/produits/'.$produit->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        self::assertSelectorTextContains('#lots', $lot->getNumero());
        self::assertSelectorTextNotContains('main', '4 321', 'Le vendeur ne voit pas les prix d\'achat.');
        self::assertSelectorNotExists('a[href$="/entree"]');

        foreach (['/stock/produit/'.$produit->getId().'/entree', '/stock/lot/'.$lot->getId().'/ajuster', '/stock/lot/'.$lot->getId().'/detruire', '/stock/valorisation', '/inventaires'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testR01UnLotDUneAutrePharmacieEstIntrouvable(): void
    {
        $autre = $this->creerOfficine();
        $lotB = LotFactory::createOne(['produit' => ProduitFactory::createOne(['pharmacie' => $autre->pharmacie, 'nomCommercial' => 'ProduitDeB'])]);

        $this->connecter($this->officine->adjoint);
        foreach (['/stock/lot/'.$lotB->getId().'/ajuster', '/stock/lot/'.$lotB->getId().'/detruire', '/stock/produit/'.$lotB->getProduit()->getId().'/entree'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('POST', '/stock/lot/'.$lotB->getId().'/ajuster', ['operation_lot' => ['quantite' => 0, 'motif' => 'x']]);
        self::assertResponseStatusCodeSame(404);

        self::assertSelectorNotExists('tbody a');
        self::assertSelectorTextNotContains('tbody', 'ProduitDeB');
        $this->client->request('GET', '/stock/valorisation');
        self::assertSelectorTextContains('#valeur-stock', "0\u{00A0}FCFA");
    }

    public function testAlertesEtTableauDeBord(): void
    {
        $p = $this->officine->pharmacie;
        $sousSeuil = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'SousLeSeuil', 'seuilAlerte' => 5]);
        LotFactory::createOne(['produit' => $sousSeuil, 'quantiteInitiale' => 3]);
        ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'SansStock']);
        $bientot = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'BientotPerime']);
        LotFactory::createOne(['produit' => $bientot, 'numero' => 'BP1', 'datePeremption' => new \DateTimeImmutable('today +30 days')]);
        $perime = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'AvecLotPerime']);
        LotFactory::createOne(['produit' => $perime, 'numero' => 'LP1', 'datePeremption' => new \DateTimeImmutable('today -2 days')]);
        LotFactory::createOne(['produit' => $perime, 'numero' => 'LP2']);
        $dormant = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Dormant']);
        LotFactory::createOne(['produit' => $dormant, 'dateReception' => new \DateTimeImmutable('today -120 days')]);
        ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Archive', 'actif' => false]);

        $this->connecter($this->officine->vendeur)->request('GET', '/stock/alertes');
        $rupture = $this->client->getCrawler()->filter('tbody')->text();
        self::assertStringContainsString('SousLeSeuil', $rupture);
        self::assertStringContainsString('SansStock', $rupture);
        self::assertStringNotContainsString('Archive', $rupture, 'Un produit archivé n\'est plus suivi.');
        self::assertStringNotContainsString('AvecLotPerime', $rupture);

        $this->client->request('GET', '/stock/alertes?type=peremption');
        self::assertSelectorTextContains('tbody', 'BP1');
        self::assertSelectorTextNotContains('tbody', 'LP2', 'Un lot à un an n\'est pas « proche » (délai par défaut : 90 jours).');

        $this->client->request('GET', '/stock/alertes?type=perimes');
        self::assertSelectorTextContains('tbody', 'LP1');
        self::assertSelectorTextNotContains('tbody', 'BP1');

        $this->client->request('GET', '/stock/alertes?type=dormants');
        self::assertSelectorTextContains('tbody', 'Dormant');
        self::assertSelectorTextNotContains('tbody', 'BientotPerime', 'Reçu il y a 10 jours : pas encore dormant.');

        $this->client->request('GET', '/stock/alertes?type=inconnu');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/');
        self::assertSame('2', $crawler->filter('.card:contains("Produits sous le seuil") .fs-5')->text());
        self::assertSame('1', $crawler->filter('.card:contains("Lots bientôt périmés") .fs-5')->text());
    }

    public function testValorisationAuPrixDAchatDesLots(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixAchat' => 999]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 10, 'prixAchat' => 800]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 5, 'prixAchat' => 900]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 2, 'prixAchat' => 1000, 'datePeremption' => new \DateTimeImmutable('yesterday')]);

        $this->connecter($this->officine->adjoint)->request('GET', '/stock/valorisation');
        self::assertSelectorTextContains('#valeur-stock', "12\u{00A0}500\u{00A0}FCFA");
        self::assertSelectorTextContains('main', "2\u{00A0}000\u{00A0}FCFA");

        $this->client->request('GET', '/stock?q='.$produit->getNomCommercial());
        self::assertSelectorTextContains('tbody', "12\u{00A0}500\u{00A0}FCFA");
    }
}
