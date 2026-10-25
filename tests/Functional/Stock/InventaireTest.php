<?php

namespace App\Tests\Functional\Stock;

use App\Entity\Entrepot;
use App\Entity\Inventaire;
use App\Entity\JournalAudit;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Enum\StatutInventaire;
use App\Enum\TypeMouvement;
use App\Tests\Factory\EntrepotFactory;
use App\Tests\Factory\EtagereFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inventaire complet ou tournant, écarts validés par le propriétaire ou l'adjoint (ST-06).
 */
final class InventaireTest extends AppWebTestCase
{
    private Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    public function testInventaireTournantParEtagere(): void
    {
        $p = $this->officine->pharmacie;
        $e1 = EtagereFactory::createOne(['pharmacie' => $p, 'code' => 'E1']);
        $e2 = EtagereFactory::createOne(['pharmacie' => $p, 'code' => 'E2']);
        $doliprane = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Doliprane', 'etagere' => $e1]);
        $a = LotFactory::createOne(['produit' => $doliprane, 'numero' => 'A', 'quantiteInitiale' => 10, 'prixAchat' => 1000]);
        $b = LotFactory::createOne(['produit' => $doliprane, 'numero' => 'B', 'quantiteInitiale' => 5, 'prixAchat' => 1000]);
        $c = LotFactory::createOne(['produit' => ProduitFactory::createOne(['pharmacie' => $p, 'etagere' => $e1]), 'numero' => 'C', 'quantiteInitiale' => 8]);
        LotFactory::createOne(['produit' => ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'AutreRayon', 'etagere' => $e2]), 'numero' => 'HORS']);

        $this->connecter($this->officine->adjoint)->request('GET', '/inventaires');
        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[perimetre]' => 'etagere', 'nouvel_inventaire[etagere]' => (string) $e1->getId()]);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $url = $crawler->getUri();
        self::assertSelectorTextContains('.alert-success', '3 lot(s) à compter');
        self::assertSelectorTextContains('main', 'Étagère E1');
        self::assertSelectorTextNotContains('#comptage', 'HORS');
        $numero = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Inventaire::class)->findOneBy([])?->getNumero());
        self::assertMatchesRegularExpression('/^INV-\d{4}-000001$/', (string) $numero);

        // Un seul inventaire en cours à la fois.
        $this->client->request('GET', '/inventaires');
        self::assertSelectorNotExists('button:contains("Ouvrir l\'inventaire")');

        $champs = $this->champsComptage($crawler);
        $this->client->request('GET', $url);
        $this->client->submitForm('Enregistrer le comptage', [$champs['A'] => '9', $champs['B'] => '5']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '2 / 3 lots comptés');

        // Validation refusée tant qu'un lot n'est pas compté.
        $this->client->submitForm('Valider et appliquer les écarts');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', '1 lot(s) ne sont pas encore comptés');

        // Une vente pendant le comptage : l'écart s'applique à la quantité actuelle.
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($c): void {
            $lot = $em->find(Lot::class, $c->getId());
            $lot?->modifierQuantite(-2);
            $em->flush();
        });

        $this->client->submitForm('Valider et appliquer les écarts', [$champs['C'] => '7']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '2 écart(s) appliqué(s) au stock');
        self::assertSelectorTextContains('main', 'Validé');
        self::assertSelectorNotExists('input[name^="comptage"]', 'Un inventaire validé ne se modifie plus.');

        $this->sansFiltre(function (EntityManagerInterface $em) use ($a, $b, $c, $numero): void {
            self::assertSame(9, $em->find(Lot::class, $a->getId())?->getQuantiteRestante());
            self::assertSame(5, $em->find(Lot::class, $b->getId())?->getQuantiteRestante());
            self::assertSame(5, $em->find(Lot::class, $c->getId())?->getQuantiteRestante(), '8 − 2 vendus − 1 manquant.');

            $mouvements = $em->getRepository(MouvementStock::class)->findBy(['document' => $numero], ['id' => 'ASC']);
            self::assertSame([[TypeMouvement::Ajustement, -1], [TypeMouvement::Ajustement, -1]], array_map(static fn (MouvementStock $m) => [$m->getType(), $m->getQuantite()], $mouvements));

            $inventaire = $em->getRepository(Inventaire::class)->findOneBy(['numero' => $numero]);
            self::assertSame(StatutInventaire::Valide, $inventaire?->getStatut());
            self::assertSame($this->officine->adjoint->getId(), $inventaire->getCloturePar()?->getId());

            $audit = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'inventaire.valide']);
            self::assertEquals(['numero' => $numero, 'emplacement' => 'Officine', 'perimetre' => 'Étagère E1', 'lots' => 3, 'ecarts' => 2, 'valeur_ecarts' => -1800], $audit?->getApres());
        });
    }

    public function testInventaireDUnEntrepotSansToucherAuStockDeLOfficine(): void
    {
        $p = $this->officine->pharmacie;
        $kalaban = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Kalaban']);
        $sogoniko = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Sogoniko']);
        $coartem = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Coartem', 'etagere' => EtagereFactory::createOne(['pharmacie' => $p, 'code' => 'E1'])]);
        $officine = LotFactory::createOne(['produit' => $coartem, 'numero' => 'OFF', 'quantiteInitiale' => 10]);
        $k1 = LotFactory::createOne(['produit' => $coartem, 'numero' => 'K1', 'quantiteInitiale' => 30, 'prixAchat' => 2000, 'entrepot' => $kalaban]);
        $k2 = LotFactory::createOne(['produit' => ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Spasfon']), 'numero' => 'K2', 'quantiteInitiale' => 12, 'entrepot' => $kalaban]);
        LotFactory::createOne(['produit' => $coartem, 'numero' => 'SOG', 'quantiteInitiale' => 4, 'entrepot' => $sogoniko]);

        // Depuis la fiche de l'entrepôt, l'emplacement est proposé d'avance.
        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/entrepots/'.$kalaban->getId());
        $crawler = $this->client->click($crawler->selectLink('Inventaire')->link());
        self::assertSame((string) $kalaban->getId(), $crawler->filter('select[name="nouvel_inventaire[entrepot]"] option[selected]')->attr('value'));

        // Les étagères sont celles de l'officine.
        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[perimetre]' => 'etagere', 'nouvel_inventaire[etagere]' => (string) $coartem->getEtagere()?->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'pour un entrepôt, choisissez un inventaire complet ou par catégorie');

        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[entrepot]' => (string) $kalaban->getId(), 'nouvel_inventaire[perimetre]' => 'complet']);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $url = $crawler->getUri();
        self::assertSelectorTextContains('.alert-success', '2 lot(s) à compter');
        self::assertSelectorTextContains('main', 'Entrepôt Kalaban');
        self::assertSame(['K1', 'K2'], array_keys($this->champsComptage($crawler)), 'Ni le lot de l\'officine ni celui de Sogoniko.');

        // L'inventaire de Kalaban ne bloque ni l'officine ni un autre entrepôt, mais Kalaban une seconde fois si.
        $this->client->request('GET', '/inventaires');
        self::assertSelectorTextContains('main', '(entrepôt kalaban) est en cours');
        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[entrepot]' => (string) $kalaban->getId(), 'nouvel_inventaire[perimetre]' => 'complet']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'est en cours : validez-le ou annulez-le avant d\'en ouvrir un autre au même endroit');
        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[entrepot]' => '', 'nouvel_inventaire[perimetre]' => 'complet']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '1 lot(s) à compter');

        // Un entrepôt en cours d'inventaire ne s'archive pas.
        $crawler = $this->client->request('GET', '/entrepots/'.$kalaban->getId());
        $this->sansFiltre(static function (EntityManagerInterface $em): void {
            foreach ($em->getRepository(Lot::class)->findAll() as $lot) {
                if (null !== $lot->getEntrepot() && 'Kalaban' === $lot->getEntrepot()->getNom()) {
                    $lot->modifierQuantite(-$lot->getQuantiteRestante());
                }
            }
            $em->flush();
        });
        $this->client->submit($crawler->selectButton('Archiver')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'de l\'entrepôt Kalaban est en cours');
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($k1, $k2): void {
            $em->find(Lot::class, $k1->getId())?->modifierQuantite(30);
            $em->find(Lot::class, $k2->getId())?->modifierQuantite(12);
            $em->flush();
        });

        $crawler = $this->client->request('GET', $url);
        $champs = $this->champsComptage($crawler);
        self::assertSelectorNotExists('#comptage th:contains("Étagère")');
        $this->client->submitForm('Valider et appliquer les écarts', [$champs['K1'] => '28', $champs['K2'] => '12']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '1 écart(s) appliqué(s) au stock');

        $this->sansFiltre(static function (EntityManagerInterface $em) use ($officine, $k1, $kalaban): void {
            self::assertSame(28, $em->find(Lot::class, $k1->getId())?->getQuantiteRestante());
            self::assertSame(10, $em->find(Lot::class, $officine->getId())?->getQuantiteRestante());
            $inventaire = $em->getRepository(Inventaire::class)->findOneBy(['entrepot' => $kalaban->getId()]);
            self::assertSame(StatutInventaire::Valide, $inventaire?->getStatut());
            self::assertSame('Kalaban', $inventaire->getEntrepot()?->getNom());
            $audit = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'inventaire.valide']);
            self::assertEquals(['numero' => $inventaire->getNumero(), 'emplacement' => 'Entrepôt Kalaban', 'perimetre' => 'Complet', 'lots' => 2, 'ecarts' => 1, 'valeur_ecarts' => -4000], $audit?->getApres());
            self::assertTrue($em->find(Entrepot::class, $kalaban->getId())?->isActif());
        });
    }

    public function testAnnulationSansToucherAuStock(): void
    {
        $lot = LotFactory::createOne(['produit' => ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie]), 'quantiteInitiale' => 6]);

        $this->connecter($this->officine->proprietaire)->request('GET', '/inventaires');
        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[perimetre]' => 'complet']);
        $crawler = $this->client->followRedirect();
        $this->client->submitForm('Enregistrer le comptage', [array_values($this->champsComptage($crawler))[0] => '2']);
        $this->client->followRedirect();
        $this->client->submitForm('Annuler l\'inventaire');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'annulé');
        self::assertSelectorTextContains('tbody', 'Annulé');

        $this->sansFiltre(static fn (EntityManagerInterface $em) => self::assertSame(6, $em->find(Lot::class, $lot->getId())?->getQuantiteRestante()));
    }

    public function testRefusDUnPerimetreVideOuIncomplet(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/inventaires');
        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[perimetre]' => 'complet']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Aucun lot en stock dans ce périmètre');

        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[perimetre]' => 'categorie']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choisissez la catégorie');
    }

    public function testR01InventaireDUneAutrePharmacie(): void
    {
        $autre = $this->creerOfficine();
        LotFactory::createOne(['produit' => ProduitFactory::createOne(['pharmacie' => $autre->pharmacie])]);
        $this->connecter($autre->adjoint)->request('GET', '/inventaires');
        $this->client->submitForm('Ouvrir l\'inventaire', ['nouvel_inventaire[perimetre]' => 'complet']);
        $id = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Inventaire::class)->findOneBy([])?->getId());

        $this->connecter($this->officine->adjoint)->request('GET', '/inventaires/'.$id);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/inventaires/'.$id.'/annuler');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/inventaires');
        self::assertSelectorTextContains('tbody', 'Aucun inventaire', 'Les inventaires de B n\'apparaissent pas chez A.');
        self::assertSelectorExists('button:contains("Ouvrir l\'inventaire")', 'L\'inventaire en cours de B ne bloque pas A.');
    }

    /**
     * @return array<string, string> nom du champ de comptage par numéro de lot
     */
    private function champsComptage(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        $champs = [];
        $crawler->filter('#comptage tbody tr')->each(static function (\Symfony\Component\DomCrawler\Crawler $ligne) use (&$champs): void {
            $champs[$ligne->filter('td.font-monospace')->text()] = (string) $ligne->filter('input')->attr('name');
        });

        return $champs;
    }
}
