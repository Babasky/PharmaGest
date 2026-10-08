<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\JournalAudit;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\OrganismeAmo;
use App\Entity\Vente;
use App\Enum\ModePaiement;
use App\Enum\PolitiqueSansOrdonnance;
use App\Enum\StatutVente;
use App\Enum\TypeMouvement;
use App\Enum\TypeVente;
use App\Security\CodePin;
use App\Tests\Factory\ClientFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\ProduitFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écran de caisse : recherche, panier, types de vente, remises, paiements et mise en attente
 * (VE-01 à VE-07, RE-01 à RE-04, PH-04) ; scénarios de recette R-02 à R-07.
 */
final class CaisseTest extends CaisseTestCase
{
    public function testR04VenteSortieFefoEtMonnaieARendre(): void
    {
        $doliprane = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Doliprane', 'prixVente' => 1500]);
        $lotA = LotFactory::createOne(['produit' => $doliprane, 'numero' => 'A', 'quantiteInitiale' => 10, 'prixAchat' => 1000, 'datePeremption' => new \DateTimeImmutable('first day of +14 months')]);
        $lotB = LotFactory::createOne(['produit' => $doliprane, 'numero' => 'B', 'quantiteInitiale' => 10, 'prixAchat' => 1100, 'datePeremption' => new \DateTimeImmutable('first day of +12 months')]);

        $this->ouvrirCaisse($this->officine->vendeur, 5000);
        self::assertSelectorTextContains('.alert-success', 'Caisse ouverte');

        // Recherche par nom, puis ajout depuis la liste des résultats.
        $this->client->submitForm('Chercher', ['q' => 'Dolip'], 'GET');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#resultats', 'Doliprane');
        $this->client->submitForm('Ajouter Doliprane');
        self::assertResponseRedirects('/caisse');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#panier', 'Doliprane');
        self::assertSelectorTextContains('#panier', '20 en stock');

        // R-04 : 15 unités, alors que le lot B périme avant le lot A.
        $ligne = $crawler->filter('#panier form[action^="/caisse/ligne/"]')->attr('action');
        $this->poster((string) $ligne, ['quantite' => 15]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#totaux', "22\u{00A0}500\u{00A0}FCFA");

        $this->encaisser(['especes' => ['remis' => '25 000']]);
        $vente = $this->derniereVente();
        self::assertResponseRedirects('/ventes/'.$vente->getId().'?caisse=1');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Monnaie à rendre : 2'."\u{00A0}".'500');
        self::assertMatchesRegularExpression('/^V-\d{4}-000001$/', (string) $vente->getNumero());
        self::assertSelectorTextContains('#lignes', 'Lot B (10');
        self::assertSelectorTextContains('#lignes', 'Lot A (5');

        $this->sansFiltre(function (EntityManagerInterface $em) use ($lotA, $lotB, $vente): void {
            self::assertSame(5, $em->find(Lot::class, $lotA->getId())?->getQuantiteRestante());
            self::assertSame(0, $em->find(Lot::class, $lotB->getId())?->getQuantiteRestante());
            $mouvements = $em->getRepository(MouvementStock::class)->findBy(['type' => TypeMouvement::Vente]);
            self::assertCount(2, $mouvements);
            self::assertSame([$vente->getNumero()], array_values(array_unique(array_map(static fn (MouvementStock $m) => $m->getDocument(), $mouvements))));

            $vente = $em->find(Vente::class, $vente->getId());
            self::assertInstanceOf(Vente::class, $vente);
            self::assertSame([22500, 22500, 0], [$vente->getTotalNet(), $vente->getMontantEncaisse(), $vente->getPartAmo()]);
            self::assertSame($this->officine->vendeur->getId(), $vente->getVendeur()->getId());
            self::assertSame(25000 - 22500, $vente->getMonnaieRendue());
            // RG-17 : prix d'achat des lots consommés conservés pour la marge.
            self::assertSame(22500 - (10 * 1100 + 5 * 1000), $vente->getMarge());
        });
    }

    public function testR03VenteRefuseeQuandSeulUnLotPerimeReste(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Perimol']);
        LotFactory::createOne(['produit' => $produit, 'numero' => 'VIEUX', 'datePeremption' => new \DateTimeImmutable('yesterday')]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->client->request('GET', '/caisse?q=Perimol');
        self::assertSelectorTextContains('#resultats', 'Rupture');
        self::assertSelectorNotExists('#resultats form');

        $this->poster('/caisse/ajouter/'.$produit->getId());
        self::assertStringContainsString('Vente impossible : le seul stock de Perimol est périmé (lot VIEUX', $this->erreur());
        self::assertSelectorTextContains('#panier', 'Le panier est vide');
    }

    public function testPaiementMixteTicketEtFacture(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Coartem', 'prixVente' => 3800]);
        LotFactory::createOne(['produit' => $produit]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($produit, 2);
        $this->encaisser(['orange_money' => ['montant' => '8000', 'reference' => 'OM1']]);
        self::assertStringContainsString('dépassent le montant à encaisser', $this->erreur(), 'Un paiement électronique ne peut pas dépasser le dû.');

        $this->encaisser([
            'orange_money' => ['montant' => '5000', 'reference' => 'OM-778'],
            'especes' => ['remis' => '2000'],
        ]);
        self::assertStringContainsString('Espèces insuffisantes', $this->erreur());

        $this->encaisser([
            'orange_money' => ['montant' => '5000', 'reference' => 'OM-778'],
            'especes' => ['remis' => '3000'],
        ]);
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame([5000, 2600], [$vente->montantPaye(ModePaiement::OrangeMoney), $vente->montantPaye(ModePaiement::Especes)]);

        $this->client->request('GET', '/ventes/'.$vente->getId());
        self::assertSelectorTextContains('#totaux', 'Orange Money (OM-778)');
        foreach (['ticket', 'facture'] as $document) {
            $this->client->request('GET', '/ventes/'.$vente->getId().'/'.$document);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/pdf');
            self::assertStringStartsWith('%PDF', (string) $this->client->getInternalResponse()->getContent());
        }
    }

    public function testR07RemiseIndisponiblePourUnClientNonPrivilegie(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie]);
        LotFactory::createOne(['produit' => $produit]);
        $client = ClientFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Sékou Traoré', 'privilegie' => false]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($produit);
        $this->poster('/caisse/client', ['client' => $client->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#client', 'Sékou Traoré');
        self::assertSelectorExists('#remise-indisponible');
        self::assertSelectorNotExists('input[name="remise_valeur"]');

        $this->poster('/caisse/vente', ['type' => 'sans_ordonnance', 'remise_valeur' => '10', 'remise_type' => 'pourcentage']);
        self::assertStringContainsString('client privilégié', $this->erreur());
    }

    public function testR06RemiseAuDelaDuPlafondAutoriseeParLePinDuProprietaire(): void
    {
        self::getContainer()->get(CodePin::class)->definir($this->officine->proprietaire, '4826');
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 2000]);
        LotFactory::createOne(['produit' => $produit]);
        $client = ClientFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Aïssata Cissé', 'privilegie' => true]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($produit, 2);
        $this->poster('/caisse/client', ['client' => $client->getId()]);
        $this->poster('/caisse/vente', ['type' => 'sans_ordonnance', 'remise_valeur' => '25', 'remise_type' => 'pourcentage']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#encaissement', 'Remise de 25 % au-delà du plafond de 10 %');
        self::assertSelectorExists('#code-pin');

        $this->encaisser();
        self::assertStringContainsString('code PIN du propriétaire', $this->erreur());
        $this->encaisser([], '1357');
        self::assertStringContainsString('Code PIN du propriétaire incorrect', $this->erreur());

        $this->encaisser([], '4826');
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame([1000, 3000], [$vente->getRemise(), $vente->getMontantEncaisse()]);
        self::assertSame($this->officine->proprietaire->getId(), $vente->getAutorisePar()?->getId());

        $this->sansFiltre(function (EntityManagerInterface $em) use ($vente): void {
            $audit = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'vente.remise_hors_plafond']);
            self::assertInstanceOf(JournalAudit::class, $audit);
            self::assertSame($this->officine->vendeur->getId(), $audit->getUtilisateur()?->getId());
            self::assertEquals(['vente' => $vente->getNumero(), 'vendeur' => $this->officine->vendeur->getNom(), 'client' => 'Aïssata Cissé', 'remise' => 1000, 'taux' => '25', 'plafond' => 10, 'autorise_par' => $this->officine->proprietaire->getNom()], $audit->getApres());
        });
    }

    public function testR05VenteAmoAvecRemise(): void
    {
        $p = $this->officine->pharmacie;
        $rembourse = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Augmentin', 'prixVente' => 9000, 'remboursableAmo' => true, 'ordonnanceObligatoire' => true]);
        $nonRembourse = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Creme', 'prixVente' => 2000, 'remboursableAmo' => false]);
        LotFactory::createOne(['produit' => $rembourse]);
        LotFactory::createOne(['produit' => $nonRembourse]);
        $inps = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'INPS']));
        self::assertInstanceOf(OrganismeAmo::class, $inps);
        $assure = ClientFactory::createOne(['pharmacie' => $p, 'nom' => 'Mariam Diallo', 'privilegie' => true, 'numeroAssure' => 'INPS-0045871', 'organismeAmo' => $inps]);
        $sansAssurance = ClientFactory::createOne(['pharmacie' => $p, 'nom' => 'Non Assuré', 'privilegie' => true]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($rembourse, 2);
        $this->ajouter($nonRembourse);
        $this->poster('/caisse/client', ['client' => $sansAssurance->getId()]);
        $this->poster('/caisse/vente', ['type' => 'amo']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#encaissement', 'choisissez l\'organisme, ou un client assuré');
        self::assertSelectorTextContains('#encaissement', 'Renseignez la date de l\'ordonnance');

        $this->poster('/caisse/client', ['client' => $assure->getId()]);
        $this->poster('/caisse/vente', [
            'type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'ordonnance_prescripteur' => 'Dr Coulibaly',
            'ordonnance_structure' => 'CSRéf Commune V', 'remise_valeur' => '10', 'remise_type' => 'pourcentage',
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#totaux', "12\u{00A0}600\u{00A0}FCFA");
        self::assertSelectorTextContains('#totaux', "6\u{00A0}660\u{00A0}FCFA");
        self::assertSelectorTextContains('#panier', 'Non remboursable');

        $this->encaisser(['especes' => ['remis' => '10000']]);
        $vente = $this->derniereVente();
        self::assertSame(TypeVente::Amo, $vente->getType());
        self::assertSame([20000, 12600, 740, 6660, 19260, 70], [$vente->getTotalBrut(), $vente->getPartAmo(), $vente->getRemise(), $vente->getMontantEncaisse(), $vente->getTotalNet(), $vente->getTauxAmo()]);
        self::assertSame(['INPS', 'INPS-0045871', 'Dr Coulibaly'], [$vente->getOrganismeAmo()?->getCode(), $vente->getNumeroAssure(), $vente->getOrdonnance()?->getPrescripteur()]);
        self::assertSame(6660, $vente->montantPaye(ModePaiement::Especes), 'Seule la part assuré est encaissée (RG-10).');
    }

    public function testVenteAmoSansClientNiPrescripteur(): void
    {
        $p = $this->officine->pharmacie;
        $rembourse = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Coartem', 'prixVente' => 4000, 'remboursableAmo' => true]);
        LotFactory::createOne(['produit' => $rembourse]);
        $inps = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'INPS']));
        self::assertInstanceOf(OrganismeAmo::class, $inps);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($rembourse);
        // Ni client ni prescripteur : seuls l'organisme et la date de l'ordonnance sont demandés.
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'organisme' => $inps->getId(), 'numero_assure' => '']);
        $this->client->followRedirect();
        self::assertSelectorExists('#assurance-vente');
        self::assertSelectorNotExists('#encaissement .alert-danger');
        self::assertSelectorTextContains('#totaux', "2\u{00A0}800\u{00A0}FCFA");

        $this->encaisser(['especes' => ['remis' => '1200']]);
        $vente = $this->derniereVente();
        self::assertResponseRedirects('/ventes/'.$vente->getId().'?caisse=1');
        self::assertNull($vente->getClient());
        self::assertNull($vente->getOrdonnance()?->getPrescripteur());
        self::assertSame(['INPS', null, 2800, 1200], [$vente->getOrganismeAmo()?->getCode(), $vente->getNumeroAssure(), $vente->getPartAmo(), $vente->getMontantEncaisse()]);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'client de passage');
    }

    public function testOrdonnanceObligatoireBloqueeOuConfirmeeParLeProprietaire(): void
    {
        self::getContainer()->get(CodePin::class)->definir($this->officine->proprietaire, '4826');
        $antibiotique = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Amoxicilline', 'ordonnanceObligatoire' => true]);
        LotFactory::createOne(['produit' => $antibiotique]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($antibiotique);
        $this->client->request('GET', '/caisse');
        self::assertSelectorTextContains('#encaissement .alert-danger', 'Ordonnance obligatoire pour Amoxicilline');
        $this->encaisser();
        self::assertStringContainsString('Ordonnance obligatoire pour Amoxicilline', $this->erreur(), 'Politique par défaut : blocage (VE-03).');

        // Réglage changé par le propriétaire entre-temps (écrit directement : le vendeur reste connecté).
        $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getConnection()->executeStatement(
            'UPDATE parametre_pharmacie SET politique_sans_ordonnance = ? WHERE pharmacie_id = ?',
            [PolitiqueSansOrdonnance::Confirmation->value, $this->officine->pharmacie->getId()],
        ));
        $this->client->request('GET', '/caisse');
        self::assertSelectorTextContains('#encaissement .alert-warning', 'le propriétaire doit confirmer');
        $this->encaisser([], '4826');
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame($this->officine->proprietaire->getId(), $vente->getAutorisePar()?->getId());
        $actions = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->createQuery('SELECT j.action FROM App\Entity\JournalAudit j')->getSingleColumnResult());
        self::assertContains('vente.sans_ordonnance', $actions);
    }

    public function testMiseEnAttenteEtReprise(): void
    {
        $p = $this->officine->pharmacie;
        $premier = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Premierol']);
        $second = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Secondol']);
        LotFactory::createOne(['produit' => $premier]);
        LotFactory::createOne(['produit' => $second]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($premier);
        $this->poster('/caisse/attente', ['repere' => 'Dame au pagne bleu']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'mise en attente');
        self::assertSelectorTextContains('#panier', 'Le panier est vide');
        self::assertSelectorTextContains('#en-attente', 'Dame au pagne bleu');

        // Un autre client est servi pendant ce temps, puis la première vente est reprise.
        $this->ajouter($second);
        $attente = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Vente::class)->findOneBy(['statut' => StatutVente::EnAttente]));
        self::assertInstanceOf(Vente::class, $attente);
        $this->poster('/caisse/reprendre/'.$attente->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('#panier', 'Premierol');
        self::assertSelectorTextContains('#en-attente', '1 produit(s)');

        // Un panier abandonné ne consomme pas de numéro (RG-02).
        $this->poster('/caisse/abandonner');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#panier', 'Le panier est vide');
        $numeros = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->createQuery('SELECT COUNT(v.id) FROM App\Entity\Vente v WHERE v.numero IS NOT NULL')->getSingleScalarResult());
        self::assertSame(0, (int) $numeros);
    }

    public function testR02CaisseBloqueeQuandLAbonnementEstExpire(): void
    {
        $expiree = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->echue(8));
        $produit = ProduitFactory::createOne(['pharmacie' => $expiree->pharmacie]);
        LotFactory::createOne(['produit' => $produit]);

        $this->connecter($expiree->vendeur)->request('GET', '/caisse');
        self::assertSelectorTextContains('main', 'la caisse est bloquée');
        self::assertSelectorNotExists('form[action="/caisse/ouvrir"]');

        $this->client->request('POST', '/caisse/ouvrir', ['fond' => '0']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'PharmaGest est en lecture seule');
        $sessions = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->createQuery('SELECT COUNT(s.id) FROM App\Entity\SessionCaisse s')->getSingleScalarResult());
        self::assertSame(0, (int) $sessions);
    }

    public function testChangementRapideDeVendeurParCodePin(): void
    {
        self::getContainer()->get(CodePin::class)->definir($this->officine->adjoint, '2580');

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->poster('/caisse/changer-vendeur', ['utilisateur' => $this->officine->adjoint->getId(), 'code_pin' => '0852']);
        self::assertStringContainsString('Code PIN incorrect', $this->erreur());

        $this->poster('/caisse/changer-vendeur', ['utilisateur' => $this->officine->adjoint->getId(), 'code_pin' => '2580']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Bonjour '.$this->officine->adjoint->getNom());
        self::assertSelectorExists('form[action="/caisse/ouvrir"]', 'Chacun a sa propre session de caisse.');
        self::assertSelectorTextContains('header', $this->officine->adjoint->getNom());
    }

    public function testR01LesVentesDUneAutrePharmacieSontIntrouvables(): void
    {
        $autre = $this->creerOfficine();
        $produitB = ProduitFactory::createOne(['pharmacie' => $autre->pharmacie, 'nomCommercial' => 'ProduitDeB']);
        LotFactory::createOne(['produit' => $produitB]);
        $this->ouvrirCaisse($autre->vendeur);
        $this->ajouter($produitB);
        $this->encaisser();
        $venteB = $this->derniereVente();
        $ligneB = $venteB->getLignes()->first();
        self::assertNotFalse($ligneB);

        $this->ouvrirCaisse($this->officine->vendeur);
        foreach (['/ventes/'.$venteB->getId(), '/ventes/'.$venteB->getId().'/ticket', '/ventes/'.$venteB->getId().'/facture', '/caisse/sessions/'.$venteB->getSession()?->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->poster('/caisse/ajouter/'.$produitB->getId());
        self::assertResponseStatusCodeSame(404);
        $this->poster('/caisse/ligne/'.$ligneB->getId(), ['quantite' => 3]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/ventes');
        self::assertSelectorTextNotContains('tbody', (string) $venteB->getNumero());
        $this->client->request('GET', '/caisse?q=ProduitDeB');
        self::assertSelectorTextContains('#resultats', 'Aucun produit ne correspond');
    }
}
