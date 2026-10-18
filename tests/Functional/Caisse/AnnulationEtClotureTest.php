<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\JournalAudit;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\SessionCaisse;
use App\Enum\StatutVente;
use App\Enum\TypeMouvement;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Vente\GestionCaisse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Annulation le jour même (VE-09, RG-12), clôture de caisse et rapport Z (FI-06, FI-07, RG-13) ;
 * scénarios de recette R-08 et R-12.
 */
final class AnnulationEtClotureTest extends CaisseTestCase
{
    public function testR08AnnulationLeJourMemeRemetLeStockDansLesLotsDOrigine(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1000]);
        $lotA = LotFactory::createOne(['produit' => $produit, 'numero' => 'A', 'quantiteInitiale' => 3, 'datePeremption' => new \DateTimeImmutable('today +3 months')]);
        $lotB = LotFactory::createOne(['produit' => $produit, 'numero' => 'B', 'quantiteInitiale' => 10, 'datePeremption' => new \DateTimeImmutable('today +9 months')]);

        $this->ouvrirCaisse($this->officine->adjoint, 2000);
        $this->ajouter($produit, 5);
        $this->encaisser(['especes' => ['remis' => '5000']]);
        $vente = $this->derniereVente();

        // Le vendeur ne peut pas annuler.
        $this->connecter($this->officine->vendeur)->request('GET', '/ventes/'.$vente->getId());
        self::assertSelectorNotExists('#annulation');
        $this->client->request('POST', '/ventes/'.$vente->getId().'/annuler', ['motif' => 'x']);
        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertSame(StatutVente::Validee, $this->derniereVente()->getStatut());

        $this->connecter($this->officine->adjoint)->request('GET', '/ventes/'.$vente->getId());
        $this->client->submitForm('Annuler la vente', ['motif' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Le motif de l\'annulation est obligatoire');

        $this->client->submitForm('Annuler la vente', ['motif' => 'Erreur de produit']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'annulée');
        self::assertSelectorTextContains('#statut', 'Annulée');
        self::assertSelectorTextContains('main', 'Erreur de produit');
        self::assertSelectorNotExists('#annulation');

        $this->sansFiltre(static function (EntityManagerInterface $em) use ($lotA, $lotB, $vente): void {
            self::assertSame([3, 10], [$em->find(Lot::class, $lotA->getId())?->getQuantiteRestante(), $em->find(Lot::class, $lotB->getId())?->getQuantiteRestante()]);
            $annulations = $em->getRepository(MouvementStock::class)->findBy(['type' => TypeMouvement::Annulation], ['id' => 'ASC']);
            self::assertSame([3, 2], array_map(static fn (MouvementStock $m) => $m->getQuantite(), $annulations));
            self::assertSame($vente->getNumero(), $annulations[0]->getDocument());
            $audit = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'vente.annulee']);
            self::assertSame('Erreur de produit', $audit?->getApres()['motif'] ?? null);
        });
        self::assertSame(StatutVente::Annulee, $this->derniereVente()->getStatut());

        // La recette est contre-passée : les espèces remboursées sortent de la caisse (RG-13).
        // Seul le propriétaire voit les espèces attendues.
        $this->client->request('GET', '/caisse/sessions/'.$vente->getSession()?->getId());
        self::assertSelectorNotExists('#attendues');
        $this->connecter($this->officine->proprietaire)->request('GET', '/caisse/sessions/'.$vente->getSession()?->getId());
        self::assertSelectorTextContains('#attendues', "2\u{00A0}000\u{00A0}FCFA");
        self::assertSelectorTextContains('#synthese', '1 ('."5\u{00A0}000\u{00A0}FCFA".')');
    }

    public function testR12ClotureAvecUnEcartEtRapportZ(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1500]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 30]);

        $this->ouvrirCaisse($this->officine->vendeur, 5000);
        $this->ajouter($produit, 15);
        $this->encaisser(['especes' => ['remis' => '22500']]);
        $vente = $this->derniereVente();
        $session = $vente->getSession();
        self::assertInstanceOf(SessionCaisse::class, $session);
        self::assertMatchesRegularExpression('/^SC-\d{4}-000001$/', $session->getNumero());

        $this->client->request('GET', '/caisse/sessions/cloture');
        self::assertResponseRedirects('/caisse/sessions/'.$session->getId());
        $this->client->followRedirect();
        $this->assertEspecesMasquees();

        // 500 FCFA manquants : 2 billets de 10 000, 1 de 5 000, 4 pièces de 500.
        // Le vendeur compte à l'aveugle : le message ne donne pas le montant de l'écart.
        $comptage = ['comptage[b10000]' => '2', 'comptage[b5000]' => '1', 'comptage[p500]' => '4'];
        $this->client->submitForm('Clôturer la caisse', $comptage);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Le comptage ne correspond pas aux espèces attendues');
        self::assertSelectorTextNotContains('.alert-danger', '500');
        self::assertSelectorExists('#cloture', 'La session reste ouverte.');

        $this->client->submitForm('Clôturer la caisse', $comptage + ['justification' => 'Erreur de monnaie sur une vente']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Session '.$session->getNumero().' clôturée.');
        self::assertSelectorTextContains('#comptees', "27\u{00A0}000\u{00A0}FCFA");
        $this->assertEspecesMasquees();
        self::assertSelectorNotExists('#ecart');
        self::assertSelectorNotExists('#cloture');
        $coupures = $this->client->getCrawler()->filter('#comptage td:first-child')->each(static fn (Crawler $td): string => $td->text());
        self::assertSame(['Billet de 10 000', 'Billet de 5 000', 'Pièce de 500'], $coupures, 'Le comptage commence par la plus grosse coupure.');

        $this->client->clickLink('Rapport Z');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

        $this->client->request('GET', '/caisse/sessions');
        self::assertSelectorTextNotContains('thead', 'Écart');

        // Le propriétaire voit tout : espèces attendues, mode espèces et écart.
        $this->connecter($this->officine->proprietaire)->request('GET', '/caisse/sessions/'.$session->getId());
        self::assertSelectorTextContains('#attendues', "27\u{00A0}500\u{00A0}FCFA");
        self::assertSelectorTextContains('#ecart', "-500\u{00A0}FCFA");
        self::assertSelectorTextContains('#synthese', 'Espèces');
        self::assertSelectorTextContains('#especes', 'Encaissements espèces');
        $this->client->request('GET', '/caisse/sessions');
        self::assertSelectorTextContains('thead', 'Écart');
        self::assertSelectorTextContains('tbody', "-500\u{00A0}FCFA");

        // Rapport Z : mêmes règles que l'écran.
        $rapport = fn (bool $voitEspeces): string => $this->sansFiltre(static function (EntityManagerInterface $em) use ($session, $voitEspeces): string {
            /** @var GestionCaisse $caisse */
            $caisse = self::getContainer()->get(GestionCaisse::class);
            /** @var Environment $twig */
            $twig = self::getContainer()->get('twig');
            $session = $em->find(SessionCaisse::class, $session->getId());
            self::assertInstanceOf(SessionCaisse::class, $session);

            return $twig->render('pdf/rapport_z.html.twig', [
                'synthese' => $caisse->synthese($session), 'session' => $session, 'pharmacie' => $session->getPharmacie(),
                'coupures' => SessionCaisse::COUPURES, 'voit_especes' => $voitEspeces,
            ]);
        });
        $pourLeProprietaire = $rapport(true);
        foreach (['Espèces attendues', 'Écart', 'Encaissements espèces', "27\u{00A0}500\u{00A0}FCFA"] as $texte) {
            self::assertStringContainsString($texte, $pourLeProprietaire);
        }
        $pourLeVendeur = $rapport(false);
        self::assertStringContainsString('Espèces comptées', $pourLeVendeur);
        foreach (['Espèces attendues', 'Écart', 'Encaissements espèces', '<td>Espèces</td>', "27\u{00A0}500\u{00A0}FCFA"] as $texte) {
            self::assertStringNotContainsString($texte, $pourLeVendeur);
        }

        $this->sansFiltre(static function (EntityManagerInterface $em) use ($session): void {
            $session = $em->find(SessionCaisse::class, $session->getId());
            self::assertSame([27500, 27000, -500], [$session?->getEspecesAttendues(), $session?->getEspecesComptees(), $session?->getEcart()]);
            $audit = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'caisse.ecart']);
            self::assertSame(-500, $audit?->getApres()['ecart'] ?? null);
        });

        // Caisse clôturée : plus d'encaissement, et l'annulation passe par un avoir.
        $this->client->request('GET', '/caisse');
        self::assertSelectorExists('form[action="/caisse/ouvrir"]');
        $this->connecter($this->officine->proprietaire)->request('GET', '/ventes/'.$vente->getId());
        $this->client->submitForm('Annuler la vente', ['motif' => 'Trop tard']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'il faut établir un avoir');
    }

    public function testLAdjointNeVoitPasNonPlusLesEspecesAttendues(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1000]);
        LotFactory::createOne(['produit' => $produit]);

        $this->ouvrirCaisse($this->officine->adjoint, 1000);
        $this->ajouter($produit, 2);
        $this->encaisser(['especes' => ['remis' => '2000']]);

        $this->client->request('GET', '/caisse/sessions/cloture');
        $this->client->followRedirect();
        $this->assertEspecesMasquees();
        self::assertSelectorTextContains('#especes', 'visibles que par le propriétaire');

        $this->client->submitForm('Clôturer la caisse', ['comptage[b2000]' => '1', 'comptage[p500]' => '2']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'clôturée.');
        self::assertSelectorTextNotContains('.alert-success', 'sans écart');
        $this->assertEspecesMasquees();
        self::assertSelectorNotExists('#ecart');
    }

    public function testClotureSansEcartEtTableauDeBord(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1200]);
        LotFactory::createOne(['produit' => $produit]);

        $this->ouvrirCaisse($this->officine->proprietaire);
        $this->ajouter($produit, 2);
        $this->encaisser(['wave' => ['montant' => '2400', 'reference' => 'WV-1']]);

        $crawler = $this->client->request('GET', '/');
        self::assertSame('1', $crawler->filter('.card:contains("Ventes du jour") .fs-5')->text());
        self::assertSame("2\u{00A0}400\u{00A0}FCFA", $crawler->filter('.card:contains("Chiffre d\'affaires du jour") .fs-5')->text());

        $this->client->request('GET', '/caisse/sessions/cloture');
        $this->client->followRedirect();
        $this->client->submitForm('Clôturer la caisse');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'clôturée, sans écart');
        self::assertSelectorTextContains('#synthese', "2\u{00A0}400\u{00A0}FCFA");
        self::assertSelectorTextContains('#synthese', 'Wave');
        self::assertSelectorTextContains('#synthese', 'Carte bancaire');
    }

    /**
     * La caisse est partagée : chacun voit et peut clôturer la session ouverte, et revoit les sessions qu'il a ouvertes ;
     * le propriétaire et l'adjoint voient tout.
     */
    public function testChacunVoitSesSessionsEtLaCaisseOuverteLAdjointVoitTout(): void
    {
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->cloturerLaCaisse();
        $this->ouvrirCaisse($this->officine->proprietaire);
        $this->cloturerLaCaisse();
        $this->ouvrirCaisse($this->officine->adjoint);
        $sessions = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(SessionCaisse::class)->findBy([], ['id' => 'ASC']));
        [$duVendeur, $duProprietaire, $ouverte] = $sessions;
        self::assertSame($duVendeur->getCaisse()?->getId(), $ouverte->getCaisse()?->getId(), 'Une seule caisse par défaut.');

        $this->connecter($this->officine->vendeur)->request('GET', '/caisse/sessions');
        self::assertSelectorTextContains('tbody', $duVendeur->getNumero());
        self::assertSelectorTextContains('tbody', $ouverte->getNumero());
        self::assertSelectorTextNotContains('tbody', $duProprietaire->getNumero());
        $this->client->request('GET', '/caisse/sessions/'.$duProprietaire->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/caisse/sessions/'.$ouverte->getId());
        self::assertSelectorExists('#cloture', 'Le vendeur peut clôturer la caisse ouverte par l\'adjoint.');

        $this->connecter($this->officine->adjoint)->request('GET', '/caisse/sessions');
        self::assertSelectorTextContains('tbody', $duProprietaire->getNumero());
        self::assertSelectorTextContains('tbody', $duVendeur->getNumero());
        $this->client->request('GET', '/caisse/sessions/'.$ouverte->getId().'/rapport-z');
        self::assertResponseStatusCodeSame(404, 'Pas de rapport Z avant la clôture.');
    }

    private function cloturerLaCaisse(): void
    {
        $this->client->request('GET', '/caisse/sessions/cloture');
        $this->client->followRedirect();
        $this->client->submitForm('Clôturer la caisse');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'clôturée');
    }

    /** Hors propriétaire : ni mode espèces, ni espèces attendues, ni encaissements espèces. */
    private function assertEspecesMasquees(): void
    {
        self::assertSelectorNotExists('#attendues');
        self::assertSelectorTextNotContains('#synthese', 'Espèces');
        self::assertSelectorTextNotContains('#especes', 'Encaissements espèces');
        self::assertSelectorTextNotContains('#especes', 'Remboursements espèces');
    }
}
