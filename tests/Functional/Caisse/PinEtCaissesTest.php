<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\Caisse;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Enum\StatutVente;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Retours de test du 18/10/2026 : le code PIN est demandé à chaque vente, même à l'utilisateur connecté, et celui
 * qui le saisit fait la vente et prend la main. L'officine a une seule caisse par défaut, partagée par l'équipe,
 * et le propriétaire peut en ajouter.
 */
final class PinEtCaissesTest extends CaisseTestCase
{
    private Utilisateur $collegue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collegue = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_VENDEUR, 'nom' => 'Bakary Touré']);
        AffectationFactory::createOne(['utilisateur' => $this->collegue, 'pharmacie' => $this->officine->pharmacie]);
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Augmentin', 'prixVente' => 6800]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 20]);
    }

    public function testChaqueVenteDemandeLeCodePinMemeAUtilisateurConnecte(): void
    {
        $this->definirPin($this->officine->vendeur, '1357');
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouterAugmentin();
        $this->client->request('GET', '/caisse');
        self::assertSelectorExists('#pin-vente[required]');

        $this->poster('/caisse/encaisser', ['vendeur' => $this->officine->vendeur->getId()]);
        self::assertStringContainsString('Saisissez le code PIN du vendeur', $this->erreur());
        $this->poster('/caisse/encaisser', ['vendeur' => $this->officine->vendeur->getId(), 'pin_vendeur' => '9753']);
        self::assertStringContainsString('Code PIN du vendeur incorrect', $this->erreur());
        self::assertSame(StatutVente::EnCours, $this->derniereVente()->getStatut(), 'Sans le bon code PIN, rien n\'est validé.');

        $this->encaisser();
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame($this->officine->vendeur->getId(), $vente->getVendeur()->getId());

        // Vente suivante : le code est redemandé.
        $this->ajouterAugmentin();
        $this->poster('/caisse/encaisser', ['vendeur' => $this->officine->vendeur->getId()]);
        self::assertStringContainsString('Saisissez le code PIN du vendeur', $this->erreur());
    }

    public function testLeCollegueQuiSaisitSonPinFaitLaVenteEtPrendLaMain(): void
    {
        $this->definirPin($this->collegue, '8642');
        $this->definirPin($this->officine->adjoint, '3691');
        $this->definirPin($this->officine->vendeur, '1357');
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouterAugmentin();

        $crawler = $this->client->request('GET', '/caisse');
        $proposes = $crawler->filter('#vente-vendeur option')->each(static fn ($o) => $o->text());
        self::assertContains('Bakary Touré', $proposes);
        self::assertContains($this->officine->vendeur->getNom(), $proposes);
        self::assertNotContains($this->officine->adjoint->getNom(), $proposes, 'Un adjoint ne vend par PIN que s\'il est connecté.');

        $this->poster('/caisse/encaisser', ['vendeur' => $this->officine->adjoint->getId(), 'pin_vendeur' => '3691']);
        self::assertStringContainsString('Code PIN du vendeur incorrect', $this->erreur());

        $this->encaisser([], $this->collegue);
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame($this->collegue->getId(), $vente->getVendeur()->getId(), 'La vente est au nom de celui qui a saisi son code.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-info', 'Bonjour Bakary Touré : la caisse est à vous.');
        self::assertSelectorTextContains('header', 'Bakary Touré');
    }

    public function testEnvoyerALaCaisseDemandeAussiLeCodePin(): void
    {
        $this->definirPin($this->collegue, '8642');
        $this->connecter($this->officine->vendeur);
        $this->ajouterAugmentin();

        $this->poster('/caisse/envoyer', ['vendeur' => $this->collegue->getId(), 'pin_vendeur' => '1111']);
        self::assertStringContainsString('Code PIN du vendeur incorrect', $this->erreur());
        self::assertSame(StatutVente::EnCours, $this->derniereVente()->getStatut());

        $this->poster('/caisse/envoyer', ['vendeur' => $this->collegue->getId(), 'pin_vendeur' => '8642']);
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::AEncaisser, $vente->getStatut());
        self::assertSame($this->collegue->getId(), $vente->getVendeur()->getId());
    }

    public function testSansCodePinPersonneNeValideLaVente(): void
    {
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouterAugmentin();
        $this->client->request('GET', '/caisse');
        self::assertSelectorTextContains('#pin-manquant', 'Définissez votre code PIN');
        self::assertSelectorExists('#encaissement button[type=submit][disabled]');

        $this->poster('/caisse/encaisser', ['pin_vendeur' => '1234']);
        self::assertStringContainsString('définissez d\'abord votre code PIN', $this->erreur());
        self::assertSame(StatutVente::EnCours, $this->derniereVente()->getStatut());
    }

    public function testUneSeuleCaisseParDefautPartageeParLEquipe(): void
    {
        $caissier = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_CAISSIER, 'nom' => 'Awa Konaté']);
        AffectationFactory::createOne(['utilisateur' => $caissier, 'pharmacie' => $this->officine->pharmacie]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouterAugmentin();
        $this->encaisser(['especes' => ['remis' => '7000']]);
        $premiere = $this->derniereVente();

        // Le collègue arrive sur la caisse déjà ouverte et y encaisse.
        $this->connecter($this->collegue)->request('GET', '/caisse');
        self::assertSelectorNotExists('form[action="/caisse/ouvrir"]');
        self::assertSelectorTextContains('#session-caisse', 'ouverte par '.$this->officine->vendeur->getNom());
        self::assertSelectorNotExists('#poste-caisse', 'Avec une seule caisse, rien à choisir.');
        $this->ajouterAugmentin();
        $this->encaisser(['especes' => ['remis' => '7000']]);
        $seconde = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $seconde->getStatut());
        self::assertSame($premiere->getSession()?->getId(), $seconde->getSession()?->getId(), 'Une seule session pour toute l\'équipe.');
        self::assertSame($this->collegue->getId(), $seconde->getEncaisseePar()?->getId(), 'La vente dit qui l\'a encaissée, pas qui a ouvert la caisse.');

        // Le caissier aussi travaille dans cette caisse, sans l'ouvrir.
        $this->connecter($caissier)->request('GET', '/encaissement');
        self::assertSelectorExists('#file-encaissement');

        // Une caisse ouverte ne s'ouvre pas deux fois.
        $this->connecter($this->officine->proprietaire);
        $this->poster('/caisse/ouvrir', ['fond' => '0']);
        self::assertSame(1, $this->compter(SessionCaisse::class));
        self::assertSame(1, $this->compter(Caisse::class));
    }

    public function testLeProprietaireAjouteUneCaisseEtChaquePosteChoisitLaSienne(): void
    {
        $this->ouvrirCaisse($this->officine->vendeur);

        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=caisses');
        self::assertSelectorExists('#liste-caisses input[value="Caisse principale"]');
        $this->client->submitForm('Ajouter la caisse', ['libelle' => 'Caisse 2']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Caisse 2 ajoutée');
        $this->client->submitForm('Ajouter la caisse', ['libelle' => 'Caisse 2']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Une caisse s\'appelle déjà « Caisse 2 »');

        // Sur le second poste, le vendeur choisit « Caisse 2 », l'ouvre et y encaisse.
        $this->connecter($this->collegue);
        $crawler = $this->client->request('GET', '/caisse');
        self::assertSelectorExists('#poste-caisse');
        $this->client->submit($crawler->filter('#poste-caisse')->form(['caisse' => (string) $this->caisse('Caisse 2')->getId()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('#caisse-fermee', 'Caisse 2 n\'est pas ouverte');
        $this->client->submitForm('Ouvrir la caisse', ['fond' => '0']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Caisse 2 ouverte');
        $this->ajouterAugmentin();
        $this->encaisser(['especes' => ['remis' => '7000']]);
        self::assertSame('Caisse 2', $this->derniereVente()->getSession()?->getCaisse()?->getLibelle());

        // Une caisse ouverte ne se retire pas du service ; une caisse fermée, si, tant qu'il en reste une.
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=caisses');
        $jeton = (string) $this->client->getCrawler()->filter('#ajouter-caisse input[name="_token"]')->attr('value');
        $this->client->request('POST', '/parametres/caisses/'.$this->caisse('Caisse 2')->getId().'/basculer', ['_token' => $jeton]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Caisse 2 est ouverte');
    }

    public function testLaDerniereCaisseResteEnServiceEtLeChoixResteDansLaPharmacie(): void
    {
        $autre = $this->creerOfficine();
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=caisses');
        $jeton = (string) $this->client->getCrawler()->filter('#ajouter-caisse input[name="_token"]')->attr('value');
        $this->client->request('POST', '/parametres/caisses/'.$this->caisse('Caisse principale')->getId().'/basculer', ['_token' => $jeton]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'au moins une caisse');

        $this->client->request('POST', '/parametres/caisses/'.$this->caisse('Caisse principale')->getId().'/renommer', ['_token' => $jeton, 'libelle' => 'Comptoir']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Caisse renommée « Comptoir »');

        // La caisse d'une autre pharmacie n'existe pas pour ce poste.
        $this->connecter($autre->proprietaire)->request('GET', '/caisse');
        $autreCaisse = $this->caisse('Caisse principale');
        $this->connecter($this->officine->proprietaire);
        $this->poster('/caisse/poste', ['caisse' => $autreCaisse->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/parametres/caisses/'.$autreCaisse->getId().'/basculer', ['_token' => $jeton]);
        self::assertResponseStatusCodeSame(404);
    }

    private function ajouterAugmentin(): void
    {
        $produit = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(\App\Entity\Produit::class)->findOneBy(['nomCommercial' => 'Augmentin']));
        self::assertNotNull($produit);
        $this->ajouter($produit);
    }

    private function caisse(string $libelle): Caisse
    {
        $caisse = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Caisse::class)->findOneBy(['libelle' => $libelle], ['id' => 'DESC']));
        self::assertInstanceOf(Caisse::class, $caisse);

        return $caisse;
    }

    /**
     * @param class-string $classe
     */
    private function compter(string $classe): int
    {
        return (int) $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->createQuery('SELECT COUNT(e.id) FROM '.$classe.' e')->getSingleScalarResult());
    }
}
