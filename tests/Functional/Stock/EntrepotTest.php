<?php

namespace App\Tests\Functional\Stock;

use App\Entity\Entrepot;
use App\Entity\JournalAudit;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\TransfertInterne;
use App\Enum\TypeMouvement;
use App\Stock\StockException;
use App\Stock\StockService;
use App\Tests\Factory\EntrepotFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Entrepôts de la pharmacie (Modibo, 2026-10-22) : stock séparé de l'officine, transferts officine ↔ entrepôts et
 * entre entrepôts, stock des entrepôts signalé quand l'officine manque d'un produit.
 */
final class EntrepotTest extends AppWebTestCase
{
    private Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    public function testCreerUnEntrepotEtYRangerUnLotParUneEntreeDeStock(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Doliprane', 'prixAchat' => 1150]);

        $this->connecter($this->officine->adjoint)->request('GET', '/entrepots');
        self::assertSelectorExists('#aucun-entrepot');
        $this->client->submitForm('Créer', ['entrepot[nom]' => 'Magasin de Kalaban', 'entrepot[adresse]' => 'Kalaban Coura, près du marché']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Entrepôt Magasin de Kalaban créé');
        self::assertSelectorTextContains('#contenu-entrepot', 'Cet entrepôt est vide');

        $entrepot = $this->entrepot('Magasin de Kalaban');
        $this->client->request('GET', '/stock/produit/'.$produit->getId().'/entree?entrepot='.$entrepot->getId());
        self::assertSelectorTextContains('#entree_stock_entrepot option[selected]', 'Magasin de Kalaban', 'L\'entrepôt passé dans l\'adresse est proposé.');
        $this->client->submitForm('Entrer en stock', [
            'entree_stock[numero]' => 'DP77',
            'entree_stock[datePeremption]' => (new \DateTimeImmutable('today +1 year'))->format('Y-m-d'),
            'entree_stock[quantite]' => '120',
            'entree_stock[prixAchat]' => '1100',
            'entree_stock[motif]' => 'Stock initial du magasin',
        ]);
        self::assertResponseRedirects('/entrepots/'.$entrepot->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '120 unité(s) de Doliprane rangée(s) dans l\'entrepôt Magasin de Kalaban');
        self::assertSelectorTextContains('#contenu-entrepot tr[data-produit="Doliprane"]', '120');

        // Le stock de l'officine n'a pas bougé : on ne vend pas ce qui est en entrepôt.
        $this->client->request('GET', '/produits/'.$produit->getId());
        self::assertSelectorTextContains('#stock-disponible', '0');
        self::assertSelectorTextContains('#stock-entrepots', '120');
        self::assertSelectorTextContains('#lots-entrepots', 'Magasin de Kalaban');
        self::assertSelectorTextContains('#lots-entrepots', 'DP77');
        self::assertSelectorTextNotContains('#lots', 'DP77');

        $this->sansFiltre(function (EntityManagerInterface $em): void {
            $actions = $em->createQuery('SELECT j.action FROM App\Entity\JournalAudit j WHERE j.pharmacie = :p ORDER BY j.id')
                ->setParameter('p', $this->officine->pharmacie->getId())->getSingleColumnResult();
            self::assertSame(['entrepot.cree', 'stock.entree'], $actions);
            $entree = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'stock.entree']);
            self::assertSame('Magasin de Kalaban', $entree?->getApres()['emplacement'] ?? null);
        });
    }

    public function testTransfertDeLEntrepotVersLOfficineEnFefoAvecLesMemesLots(): void
    {
        $p = $this->officine->pharmacie;
        $entrepot = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Dépôt principal']);
        $coartem = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Coartem', 'seuilAlerte' => 5]);
        LotFactory::createOne(['produit' => $coartem, 'numero' => 'TARD', 'quantiteInitiale' => 30, 'datePeremption' => new \DateTimeImmutable('today +2 years'), 'entrepot' => $entrepot]);
        LotFactory::createOne(['produit' => $coartem, 'numero' => 'TOT', 'quantiteInitiale' => 8, 'datePeremption' => new \DateTimeImmutable('today +3 months'), 'prixAchat' => 2900, 'entrepot' => $entrepot]);
        LotFactory::createOne(['produit' => $coartem, 'numero' => 'PERIME', 'quantiteInitiale' => 5, 'datePeremption' => new \DateTimeImmutable('yesterday'), 'entrepot' => $entrepot]);

        // Depuis la fiche produit : « Faire venir à l'officine », l'entrepôt qui a du stock est proposé comme origine.
        $this->connecter($this->officine->adjoint)->request('GET', '/produits/'.$coartem->getId());
        self::assertSelectorTextContains('#stock-entrepots', '38', 'Le lot périmé de l\'entrepôt ne compte pas.');
        $this->client->clickLink('Faire venir à l\'officine');
        self::assertSelectorTextContains('#origine option[selected]', 'Dépôt principal');
        self::assertSelectorTextContains('#destination option[selected]', 'Officine');
        self::assertSelectorTextContains('#produits-transfert tr[data-produit="Coartem"]', '38');

        $this->client->submitForm('Transférer', ['p['.$coartem->getId().']' => '50']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#erreur-transfert', 'Stock insuffisant pour Coartem dans l\'entrepôt Dépôt principal : 38 disponible(s) pour 50 demandé(s).');

        $this->client->submitForm('Transférer', ['p['.$coartem->getId().']' => '12', 'note' => 'Réassort du comptoir']);
        self::assertResponseRedirects('/entrepots/'.$entrepot->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Transfert TRI-'.date('Y').'-000001 enregistré : 12 unité(s) de Dépôt principal vers Officine.');
        self::assertSelectorTextContains('#contenu-entrepot tr[data-produit="Coartem"]', '26');
        self::assertSelectorTextContains('#transferts-internes', 'Coartem × 12');

        $this->client->request('GET', '/produits/'.$coartem->getId());
        self::assertSelectorTextContains('#stock-disponible', '12');
        self::assertSelectorTextContains('#lots', 'TOT');
        self::assertSelectorTextContains('#lots', 'TARD');

        $this->sansFiltre(static function (EntityManagerInterface $em) use ($entrepot): void {
            $lots = [];
            foreach ($em->getRepository(Lot::class)->findBy([], ['id' => 'ASC']) as $lot) {
                $lots[] = [$lot->getNumero(), $lot->getEntrepot()?->getId(), $lot->getQuantiteRestante()];
            }
            self::assertSame([
                ['TARD', $entrepot->getId(), 26],
                ['TOT', $entrepot->getId(), 0],
                ['PERIME', $entrepot->getId(), 5],
                ['TOT', null, 8],
                ['TARD', null, 4],
            ], $lots, 'FEFO : le lot qui périme le premier part d\'abord, le lot périmé reste ; les lots gardent leur numéro.');
            $officineTot = $em->getRepository(Lot::class)->findOneBy(['numero' => 'TOT', 'entrepot' => null]);
            self::assertSame(2900, $officineTot?->getPrixAchat(), 'Le prix d\'achat suit le lot.');

            $mouvements = array_map(static fn (MouvementStock $m) => [$m->getType(), $m->getQuantite(), $m->getDocument()], $em->getRepository(MouvementStock::class)->findBy([], ['id' => 'ASC']));
            $numero = 'TRI-'.date('Y').'-000001';
            self::assertSame([
                [TypeMouvement::Transfert, -8, $numero],
                [TypeMouvement::Transfert, -4, $numero],
                [TypeMouvement::Transfert, 8, $numero],
                [TypeMouvement::Transfert, 4, $numero],
            ], $mouvements);

            $transfert = $em->getRepository(TransfertInterne::class)->findOneBy([]);
            self::assertSame(['Dépôt principal', 'Officine', 'Réassort du comptoir', 12], [$transfert?->getLibelleOrigine(), $transfert?->getLibelleDestination(), $transfert?->getNote(), $transfert?->getQuantiteTotale()]);
            self::assertSame('transfert.interne', $em->getRepository(JournalAudit::class)->findOneBy([], ['id' => 'DESC'])?->getAction());
        });
    }

    public function testRangerDepuisLOfficineEtTransfererEntreEntrepotsSansDoublerLesLots(): void
    {
        $p = $this->officine->pharmacie;
        $kalaban = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Kalaban']);
        $sotuba = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Sotuba']);
        $smecta = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Smecta']);
        LotFactory::createOne(['produit' => $smecta, 'numero' => 'SM1', 'quantiteInitiale' => 50]);

        // Officine → Kalaban, deux fois : le même lot reçoit les unités au lieu d'en créer un second.
        $this->connecter($this->officine->proprietaire);
        foreach ([20, 10] as $quantite) {
            $this->client->request('GET', '/entrepots/transfert?origine=officine&destination='.$kalaban->getId().'&q=Smecta');
            $this->client->submitForm('Transférer', ['p['.$smecta->getId().']' => (string) $quantite]);
            self::assertResponseRedirects('/entrepots/'.$kalaban->getId());
        }
        // Kalaban → Sotuba.
        $this->client->request('GET', '/entrepots/transfert?origine='.$kalaban->getId().'&destination='.$sotuba->getId().'&q=Smecta');
        $this->client->submitForm('Transférer', ['p['.$smecta->getId().']' => '25']);
        self::assertResponseRedirects('/entrepots/'.$sotuba->getId());

        $this->sansFiltre(static function (EntityManagerInterface $em) use ($kalaban, $sotuba): void {
            $lots = [];
            foreach ($em->getRepository(Lot::class)->findBy(['numero' => 'SM1'], ['id' => 'ASC']) as $lot) {
                $lots[] = [$lot->getEntrepot()?->getId(), $lot->getQuantiteRestante()];
            }
            self::assertSame([[null, 20], [$kalaban->getId(), 5], [$sotuba->getId(), 25]], $lots);
        });

        $this->client->request('GET', '/entrepots');
        self::assertSelectorTextContains('#entrepots', 'Kalaban');
        $this->client->request('GET', '/entrepots/transferts');
        self::assertSelectorCount(3, '#transferts-internes tbody tr');
        $this->client->request('GET', '/stock/valorisation');
        self::assertSelectorTextContains('#valorisation-emplacements', 'Sotuba');
    }

    public function testMemeOrigineEtDestinationRefuseeEtEntrepotNonVideNonArchivable(): void
    {
        $p = $this->officine->pharmacie;
        $entrepot = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Kalaban']);
        $produit = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Amoxicilline']);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 10, 'entrepot' => $entrepot]);

        // L'origine n'est jamais proposée comme destination (retour de Modibo, 24/10/2026), même demandée dans l'adresse.
        $autre = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Sotuba']);
        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/entrepots/transfert?origine='.$entrepot->getId().'&destination='.$entrepot->getId().'&q=Amox');
        self::assertSame(['officine', (string) $autre->getId()], $crawler->filter('#destination option')->each(static fn ($o) => $o->attr('value')));
        self::assertSelectorTextContains('#destination option[selected]', 'Officine');
        $crawler = $this->client->request('GET', '/entrepots/transfert?origine=officine&q=Amox');
        self::assertSame([(string) $entrepot->getId(), (string) $autre->getId()], $crawler->filter('#destination option')->each(static fn ($o) => $o->attr('value')));

        // Un envoi forgé avec la même origine et la même destination reste refusé.
        $this->client->request('GET', '/entrepots/transfert?origine='.$entrepot->getId().'&q=Amox');
        $formulaire = $this->client->getCrawler()->filter('#formulaire-transfert')->form();
        $destination = $formulaire['destination'];
        self::assertInstanceOf(ChoiceFormField::class, $destination);
        $destination->disableValidation()->setValue((string) $entrepot->getId());
        $formulaire['p['.$produit->getId().']'] = '2';
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#erreur-transfert', 'Choisissez une destination différente de l\'origine.');

        $crawler = $this->client->request('GET', '/entrepots/'.$entrepot->getId());
        $this->client->submit($crawler->selectButton('Archiver')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'L\'entrepôt Kalaban contient encore du stock');
        self::assertTrue($this->entrepot('Kalaban')->isActif());
    }

    public function testLeStockDesEntrepotsEstSignaleQuandLOfficineManqueEtNeSeVendPas(): void
    {
        $p = $this->officine->pharmacie;
        $entrepot = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Kalaban']);
        $coartem = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Coartem', 'seuilAlerte' => 5]);
        LotFactory::createOne(['produit' => $coartem, 'quantiteInitiale' => 15, 'entrepot' => $entrepot, 'dateReception' => new \DateTimeImmutable('today -30 days')]);

        $this->connecter($this->officine->vendeur)->request('GET', '/stock?q=Coartem');
        self::assertSelectorTextContains('tr.pg-stock-nul', '15 en entrepôt');
        self::assertSelectorTextNotContains('tr.pg-stock-nul', 'Rupture', 'Un produit qui attend en entrepôt n\'est pas en rupture.');
        self::assertSelectorNotExists('a.pg-en-entrepot', 'Le vendeur ne fait pas de transfert.');

        $this->client->request('GET', '/caisse?q=Coartem');
        self::assertSelectorTextContains('main', '15 en entrepôt');
        self::assertSelectorNotExists('form[action$="/caisse/ajouter/'.$coartem->getId().'"]', 'Le stock d\'un entrepôt ne se vend pas.');

        $this->client->request('GET', '/stock/alertes');
        self::assertSelectorTextContains('main', 'Coartem');
        self::assertSelectorTextContains('main', '15 en entrepôt');

        $this->connecter($this->officine->adjoint)->request('GET', '/stock?q=Coartem');
        self::assertSelectorExists('a.pg-en-entrepot[href*="destination=officine"]');

        /** @var StockService $stock */
        $stock = self::getContainer()->get(StockService::class);
        try {
            $stock->verifierDisponible($this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(\App\Entity\Produit::class, $coartem->getId())), 1);
            self::fail('Le stock de l\'entrepôt ne doit pas être vendable.');
        } catch (StockException $e) {
            self::assertSame('Coartem est en rupture de stock. 15 disponible(s) en entrepôt : faites-les venir à l\'officine par un transfert.', $e->getMessage());
        }
    }

    public function testLeVendeurNAccedePasAuxEntrepotsEtUnEntrepotDUneAutrePharmacieEstIntrouvable(): void
    {
        $autre = $this->creerOfficine();
        $entrepotB = EntrepotFactory::createOne(['pharmacie' => $autre->pharmacie, 'nom' => 'Entrepôt de B']);
        $mien = EntrepotFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Le mien']);

        $this->connecter($this->officine->vendeur);
        foreach (['/entrepots', '/entrepots/'.$mien->getId(), '/entrepots/transfert'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->connecter($this->officine->adjoint)->request('GET', '/entrepots/'.$entrepotB->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/entrepots');
        self::assertSelectorTextNotContains('#entrepots', 'Entrepôt de B');

        // Un identifiant d'entrepôt d'une autre pharmacie passé à la main n'est pas proposé.
        $this->client->request('GET', '/entrepots/transfert?origine='.$entrepotB->getId());
        self::assertSelectorTextNotContains('#origine', 'Entrepôt de B');
    }

    private function entrepot(string $nom): Entrepot
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($nom): Entrepot {
            $entrepot = $em->getRepository(Entrepot::class)->findOneBy(['nom' => $nom]);
            self::assertInstanceOf(Entrepot::class, $entrepot);

            return $entrepot;
        });
    }
}
