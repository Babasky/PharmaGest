<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\JournalAudit;
use App\Entity\Lot;
use App\Entity\Paiement;
use App\Entity\Recette;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\StatutVente;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rôle caissier : le vendeur valide la vente et l'envoie à la caisse, le caissier l'encaisse dans sa propre
 * session ; le vendeur garde le droit d'encaisser lui-même. Chacun arrive sur son écran à la connexion.
 */
final class EncaissementTest extends CaisseTestCase
{
    private Utilisateur $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->caissier = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_CAISSIER, 'nom' => 'Kadiatou Sangaré']);
        AffectationFactory::createOne(['utilisateur' => $this->caissier, 'pharmacie' => $this->officine->pharmacie]);
    }

    public function testLeVendeurEnvoieLaVenteEtLeCaissierLEncaisse(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Doliprane', 'prixVente' => 1500]);
        $lot = LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 10]);

        // Sans caisse ouverte, le vendeur prépare la vente et l'envoie à la caisse.
        $this->connecter($this->officine->vendeur)->request('GET', '/caisse');
        self::assertSelectorExists('#caisse-fermee');
        $this->ajouter($produit, 3);
        $this->client->followRedirect();
        self::assertSelectorNotExists('form[action="/caisse/encaisser"]');
        $this->poster('/caisse/envoyer');
        self::assertResponseRedirects('/caisse');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'envoyée à la caisse');
        self::assertSelectorTextContains('#panier', 'Le panier est vide');
        self::assertSelectorTextContains('#a-encaisser', "4\u{00A0}500\u{00A0}FCFA");

        // Numérotée, produits sortis du stock, mais rien d'encaissé.
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::AEncaisser, $vente->getStatut());
        self::assertMatchesRegularExpression('/^V-\d{4}-000001$/', (string) $vente->getNumero());
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($lot, $vente): void {
            self::assertSame(7, $em->find(Lot::class, $lot->getId())?->getQuantiteRestante());
            self::assertSame(0, $em->getRepository(Paiement::class)->count([]));
            self::assertSame(0, $em->getRepository(Recette::class)->count(['vente' => $vente->getId()]));
            self::assertNull($em->find(Vente::class, $vente->getId())?->getSession());
        });
        $this->client->request('GET', '/ventes/'.$vente->getId().'/ticket');
        self::assertResponseStatusCodeSame(404, 'Pas de ticket avant l\'encaissement.');

        // Le caissier arrive sur l'encaissement, ouvre sa caisse et encaisse.
        $this->seConnecter($this->caissier->getEmail());
        self::assertResponseRedirects('/encaissement');
        $this->client->followRedirect();
        $this->client->submitForm('Ouvrir la caisse', ['fond' => '2000']);
        self::assertResponseRedirects('/encaissement');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#file-encaissement', (string) $vente->getNumero());
        self::assertSelectorTextContains('#file-encaissement', $this->officine->vendeur->getNom());

        $this->client->clickLink('Encaisser '.$vente->getNumero());
        self::assertSelectorTextContains('#lignes', 'Doliprane');
        $this->client->submitForm('Encaisser', ['paiement[especes][remis]' => '5000']);
        self::assertResponseRedirects('/ventes/'.$vente->getId().'?caisse=1');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Monnaie à rendre : 500');
        self::assertSelectorTextContains('#intervenants', 'encaissée par Kadiatou Sangaré');
        self::assertSelectorExists('a[href="/encaissement"]');

        $this->sansFiltre(function (EntityManagerInterface $em) use ($vente): void {
            $vente = $em->find(Vente::class, $vente->getId());
            self::assertInstanceOf(Vente::class, $vente);
            self::assertSame(StatutVente::Validee, $vente->getStatut());
            self::assertSame($this->officine->vendeur->getId(), $vente->getVendeur()->getId());
            self::assertSame($this->caissier->getId(), $vente->getSession()?->getUtilisateur()->getId());
            self::assertSame(4500, $vente->getMontantEncaisse());
            self::assertSame(1, $em->getRepository(Recette::class)->count(['vente' => $vente->getId()]));
        });

        // Les espèces de la vente sont attendues dans la caisse du caissier, pas dans celle du vendeur.
        $this->client->request('GET', '/caisse/sessions/cloture');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', "6\u{00A0}500");

        // Une deuxième tentative ne l'encaisse pas deux fois.
        $this->client->request('GET', '/encaissement');
        self::assertSelectorTextContains('#file-encaissement', 'Aucune vente à encaisser');
        $this->client->request('GET', '/encaissement/'.$vente->getId());
        self::assertResponseRedirects('/ventes/'.$vente->getId());
    }

    public function testLeVendeurPeutToujoursEncaisserLuiMeme(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1000]);
        LotFactory::createOne(['produit' => $produit]);

        // Une vente envoyée à la caisse par l'adjoint, encaissée par le vendeur dans sa propre session.
        $this->connecter($this->officine->adjoint);
        $this->ajouter($produit, 2);
        $this->poster('/caisse/envoyer');
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::AEncaisser, $vente->getStatut());

        $this->ouvrirCaisse($this->officine->vendeur);
        self::assertSelectorTextContains('#a-encaisser', (string) $vente->getNumero());
        $this->client->request('GET', '/encaissement/'.$vente->getId());
        $this->client->submitForm('Encaisser');
        self::assertResponseRedirects('/ventes/'.$vente->getId().'?caisse=1');
        $this->client->followRedirect();
        self::assertSelectorExists('a[href="/caisse"]');

        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame($this->officine->adjoint->getId(), $vente->getVendeur()->getId());
        self::assertSame($this->officine->vendeur->getId(), $vente->getSession()?->getUtilisateur()->getId());
    }

    public function testUneVenteQueLeClientNePaiePasEstAnnuleeEtLeStockRevient(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1000]);
        $lot = LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 5]);

        $this->connecter($this->officine->vendeur);
        $this->ajouter($produit, 2);
        $this->poster('/caisse/envoyer');
        $vente = $this->derniereVente();

        $this->ouvrirCaissier();
        $this->client->request('GET', '/encaissement/'.$vente->getId());
        $this->client->submitForm('Annuler la vente', ['motif' => 'Client reparti sans payer']);
        self::assertResponseRedirects('/encaissement');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'revenus en stock');

        $this->sansFiltre(function (EntityManagerInterface $em) use ($lot, $vente): void {
            self::assertSame(5, $em->find(Lot::class, $lot->getId())?->getQuantiteRestante());
            $vente = $em->find(Vente::class, $vente->getId());
            self::assertSame(StatutVente::Annulee, $vente?->getStatut());
            self::assertSame($this->caissier->getId(), $vente->getAnnuleePar()?->getId());
            self::assertSame(0, $em->getRepository(Recette::class)->count(['vente' => $vente->getId()]));
            $audit = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'vente.annulee']);
            self::assertSame('Client reparti sans payer', $audit?->getApres()['motif'] ?? null);
        });

        $this->client->request('GET', '/encaissement/'.$vente->getId());
        self::assertResponseRedirects('/ventes/'.$vente->getId());
    }

    public function testLeCaissierNAccedeQuALEncaissementEtAuxVentes(): void
    {
        $this->connecter($this->caissier)->request('GET', '/');
        self::assertResponseRedirects('/encaissement');

        foreach (['/caisse', '/produits', '/stock', '/clients', '/commandes'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        foreach (['/encaissement', '/ventes', '/caisse/sessions'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }
        self::assertSelectorTextContains('.pg-sidebar, nav', 'Encaissement');
        self::assertSelectorTextNotContains('.pg-sidebar, nav', 'Produits');
        self::assertSelectorTextContains('header', 'Caissier');
    }

    public function testChacunArriveSurSonEcranALaConnexion(): void
    {
        $this->seConnecter($this->officine->vendeur->getEmail());
        self::assertResponseRedirects('/caisse');
        $this->seConnecter($this->caissier->getEmail());
        self::assertResponseRedirects('/encaissement');
        $this->seConnecter($this->officine->adjoint->getEmail());
        self::assertResponseRedirects('/');
        $this->seConnecter($this->officine->proprietaire->getEmail());
        self::assertResponseRedirects('/');

        // Déjà connecté, la page de connexion renvoie aussi sur son écran.
        $this->seConnecter($this->caissier->getEmail());
        $this->client->request('GET', '/connexion');
        self::assertResponseRedirects('/encaissement');
    }

    public function testLeProprietaireAjouteUnCaissierASonEquipe(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/equipe/nouveau');
        $this->client->submitForm('Enregistrer', [
            'membre_equipe[nom]' => 'Awa Diakité',
            'membre_equipe[email]' => 'a.diakite@test.ml',
            'membre_equipe[role]' => Utilisateur::ROLE_CAISSIER,
            'membre_equipe[motDePasseInitial]' => 'caisse-2026',
        ]);
        self::assertResponseRedirects('/equipe');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Awa Diakité');
        self::assertSelectorTextContains('main', 'Caissier');
    }

    private function ouvrirCaissier(): void
    {
        $this->connecter($this->caissier)->request('GET', '/encaissement');
        $this->client->submitForm('Ouvrir la caisse', ['fond' => '0']);
        self::assertResponseRedirects('/encaissement');
        $this->client->followRedirect();
    }
}
