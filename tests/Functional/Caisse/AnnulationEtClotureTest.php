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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Annulation le jour même (VE-09, RG-12), clôture de caisse et rapport Z (FI-06, FI-07, RG-13) ;
 * scénarios de recette R-08 et R-12.
 */
final class AnnulationEtClotureTest extends CaisseTestCase
{
    use ClockSensitiveTrait;

    public function testDelaiDAnnulationReglableParLeProprietaire(): void
    {
        self::mockTime('2026-11-04 10:00:00');
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1000]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 20, 'datePeremption' => new \DateTimeImmutable('2027-06-30')]);

        // Par défaut : le jour même. Le propriétaire choisit 15 minutes après la validation.
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=regles');
        self::assertInputValueSame('parametres[delaiAnnulationVente]', '', 'Vide par défaut : le jour même.');
        $this->client->submitForm('Enregistrer les règles', ['parametres[delaiAnnulationVente]' => '0']);
        self::assertResponseStatusCodeSame(422);
        $this->client->submitForm('Enregistrer les règles', ['parametres[delaiAnnulationVente]' => '15']);
        self::assertResponseRedirects('/parametres?onglet=regles');
        $this->client->followRedirect();
        self::assertInputValueSame('parametres[delaiAnnulationVente]', '15');

        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($produit, 2);
        $this->encaisser(['especes' => ['remis' => '2000']]);
        $premiere = $this->derniereVente();
        $this->ajouter($produit, 3);
        $this->encaisser(['especes' => ['remis' => '3000']]);
        $seconde = $this->derniereVente();

        // Dix minutes plus tard : encore possible, la page indique l'échéance.
        self::mockTime('2026-11-04 10:10:00');
        $this->client->request('GET', '/ventes/'.$premiere->getId());
        self::assertSelectorTextContains('#delai-annulation', 'Possible pendant 15 minutes après la validation, jusqu\'au 04/11/2026 à 10:15');
        $this->client->submitForm('Annuler la vente', ['motif' => 'Erreur de produit']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'annulée');

        // La page de la seconde vente est ouverte dans le délai, le formulaire n'est envoyé qu'après.
        $crawler = $this->client->request('GET', '/ventes/'.$seconde->getId());
        $formulaire = $crawler->selectButton('Annuler la vente')->form(['motif' => 'Trop tard']);
        self::mockTime('2026-11-04 10:20:00');
        $this->client->submit($formulaire);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Le délai d\'annulation de 15 minutes après la validation est dépassé');
        self::assertSelectorNotExists('#annulation');
        self::assertSelectorTextContains('#annulation-impossible', 'Le délai d\'annulation (15 minutes après la validation) est dépassé');
        self::assertSame(StatutVente::Validee, $this->derniereVente()->getStatut());
    }

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
        // Pas de clôture à l'aveugle : le vendeur voit les espèces attendues.
        $this->assertEspecesVisibles();
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
        self::assertSelectorTextContains('#comptees', "27\u{00A0}000\u{00A0}FCFA");
        $this->assertEspecesVisibles();
        self::assertSelectorTextContains('#ecart', "-500\u{00A0}FCFA");
        self::assertSelectorNotExists('#cloture');
        $coupures = $this->client->getCrawler()->filter('#comptage td:first-child')->each(static fn (Crawler $td): string => $td->text());
        self::assertSame(['Billet de 10 000', 'Billet de 5 000', 'Pièce de 500'], $coupures, 'Le comptage commence par la plus grosse coupure.');

        $this->client->clickLink('Rapport Z');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

        $this->client->request('GET', '/caisse/sessions');
        self::assertSelectorTextContains('thead', 'Écart');
        self::assertSelectorTextContains('tbody', "-500\u{00A0}FCFA");

        // Le rapport Z donne le contrôle complet des espèces.
        $rapport = $this->sansFiltre(static function (EntityManagerInterface $em) use ($session): string {
            /** @var GestionCaisse $caisse */
            $caisse = self::getContainer()->get(GestionCaisse::class);
            /** @var Environment $twig */
            $twig = self::getContainer()->get('twig');
            $session = $em->find(SessionCaisse::class, $session->getId());
            self::assertInstanceOf(SessionCaisse::class, $session);

            return $twig->render('pdf/rapport_z.html.twig', [
                'synthese' => $caisse->synthese($session), 'session' => $session, 'pharmacie' => $session->getPharmacie(),
                'coupures' => SessionCaisse::COUPURES,
            ]);
        });
        foreach (['<td>Espèces</td>', 'Encaissements espèces', 'Espèces attendues', "27\u{00A0}500\u{00A0}FCFA", 'Espèces comptées', 'Écart'] as $texte) {
            self::assertStringContainsString($texte, $rapport);
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
        self::assertSelectorNotExists('#annulation');
        self::assertSelectorTextContains('#annulation-impossible', 'session de caisse de cette vente est clôturée : pour la reprendre, il faut établir un avoir');
    }

    public function testLAdjointVoitAussiLesEspecesAttendues(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1000]);
        LotFactory::createOne(['produit' => $produit]);

        $this->ouvrirCaisse($this->officine->adjoint, 1000);
        $this->ajouter($produit, 2);
        $this->encaisser(['especes' => ['remis' => '2000']]);

        $this->client->request('GET', '/caisse/sessions/cloture');
        $this->client->followRedirect();
        $this->assertEspecesVisibles();
        self::assertSelectorTextContains('#attendues', "3\u{00A0}000\u{00A0}FCFA");

        $this->client->submitForm('Clôturer la caisse', ['comptage[b2000]' => '1', 'comptage[p500]' => '2']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'clôturée, sans écart');
        $this->assertEspecesVisibles();
        self::assertSelectorTextContains('#ecart', "0\u{00A0}FCFA");
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

    /** Pas de clôture à l'aveugle : mode espèces, encaissements espèces et espèces attendues sont affichés à tous. */
    private function assertEspecesVisibles(): void
    {
        self::assertSelectorExists('#attendues');
        self::assertSelectorTextContains('#synthese', 'Espèces');
        self::assertSelectorTextContains('#especes', 'Encaissements espèces');
        self::assertSelectorTextContains('#especes', 'Remboursements espèces');
    }
}
