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
use Doctrine\ORM\EntityManagerInterface;

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
        $this->client->request('GET', '/caisse/sessions/'.$vente->getSession()?->getId());
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
        self::assertSelectorTextContains('#attendues', "27\u{00A0}500\u{00A0}FCFA");

        // 500 FCFA manquants : 2 billets de 10 000, 1 de 5 000, 4 pièces de 500.
        $comptage = ['comptage[b10000]' => '2', 'comptage[b5000]' => '1', 'comptage[p500]' => '4'];
        $this->client->submitForm('Clôturer la caisse', $comptage);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', "Écart de -500\u{00A0}FCFA : la justification est obligatoire");
        self::assertSelectorExists('#cloture', 'La session reste ouverte.');

        $this->client->submitForm('Clôturer la caisse', $comptage + ['justification' => 'Erreur de monnaie sur une vente']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'écart de -500');
        self::assertSelectorTextContains('#ecart', "-500\u{00A0}FCFA");
        self::assertSelectorNotExists('#cloture');

        $this->client->clickLink('Rapport Z');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

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
        self::assertSelectorTextNotContains('#synthese', 'Carte bancaire');
    }

    public function testChacunVoitSesSessionsLAdjointVoitTout(): void
    {
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ouvrirCaisse($this->officine->proprietaire);
        $sessions = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(SessionCaisse::class)->findBy([], ['id' => 'ASC']));
        [$duVendeur, $duProprietaire] = $sessions;

        $this->connecter($this->officine->vendeur)->request('GET', '/caisse/sessions');
        self::assertSelectorTextContains('tbody', $duVendeur->getNumero());
        self::assertSelectorTextNotContains('tbody', $duProprietaire->getNumero());
        $this->client->request('GET', '/caisse/sessions/'.$duProprietaire->getId());
        self::assertResponseStatusCodeSame(404);

        $this->connecter($this->officine->adjoint)->request('GET', '/caisse/sessions');
        self::assertSelectorTextContains('tbody', $duProprietaire->getNumero());
        self::assertSelectorTextContains('tbody', $duVendeur->getNumero());
        $this->client->request('GET', '/caisse/sessions/'.$duVendeur->getId());
        self::assertSelectorExists('#cloture', 'L\'adjoint peut clôturer la caisse d\'un vendeur parti sans la fermer.');
        $this->client->request('GET', '/caisse/sessions/'.$duVendeur->getId().'/rapport-z');
        self::assertResponseStatusCodeSame(404, 'Pas de rapport Z avant la clôture.');
    }
}
