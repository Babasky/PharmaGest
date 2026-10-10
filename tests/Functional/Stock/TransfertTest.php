<?php

namespace App\Tests\Functional\Stock;

use App\Entity\JournalAudit;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\Notification;
use App\Entity\Offre;
use App\Entity\Pharmacie;
use App\Entity\Produit;
use App\Entity\TransfertStock;
use App\Entity\Utilisateur;
use App\Enum\StatutTransfert;
use App\Enum\TypeMouvement;
use App\Service\AuditLogger;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transferts de stock entre officines (ST-11) : permis seulement entre officines du même pharmacien.
 *
 * Mariam détient l'Officine Alpha et l'Officine Bêta (Premium) ; l'Officine Gamma appartient à un autre pharmacien.
 * Alpha a du Doliprane en trois lots (dont un périmé, qui ne part jamais) et du Coartem, inconnu du catalogue de Bêta.
 */
final class TransfertTest extends AppWebTestCase
{
    private Officine $alpha;
    private Pharmacie $beta;
    private Utilisateur $adjointBeta;
    private Officine $gamma;
    private Produit $doliprane;
    private Produit $coartem;
    private Produit $dolipraneBeta;

    protected function setUp(): void
    {
        parent::setUp();
        $premium = static fn ($f) => $f->offre(Offre::PREMIUM);
        $this->alpha = $this->creerOfficine(static fn ($f) => $premium($f)->with(['nom' => 'Officine Alpha']));
        $this->beta = PharmacieFactory::new()->offre(Offre::PREMIUM)->create(['nom' => 'Officine Bêta', 'ville' => 'Kati']);
        AffectationFactory::createOne(['utilisateur' => $this->alpha->proprietaire, 'pharmacie' => $this->beta]);
        $this->adjointBeta = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_ADJOINT, 'nom' => 'Adjoint Bêta']);
        AffectationFactory::createOne(['utilisateur' => $this->adjointBeta, 'pharmacie' => $this->beta]);
        $this->gamma = $this->creerOfficine(static fn ($f) => $premium($f)->with(['nom' => 'Officine Gamma']));

        $a = $this->alpha->pharmacie;
        $this->doliprane = ProduitFactory::createOne(['pharmacie' => $a, 'nomCommercial' => 'Doliprane', 'dosage' => '500 mg', 'prixAchat' => 1150, 'prixVente' => 1500]);
        $this->coartem = ProduitFactory::createOne(['pharmacie' => $a, 'nomCommercial' => 'Coartem', 'dci' => 'Artéméther + luméfantrine', 'dosage' => '20/120 mg', 'prixAchat' => 2900, 'prixVente' => 3800, 'ordonnanceObligatoire' => true]);
        LotFactory::createOne(['produit' => $this->doliprane, 'numero' => 'DP-PERIME', 'datePeremption' => new \DateTimeImmutable('today -5 days'), 'quantiteInitiale' => 5]);
        LotFactory::createOne(['produit' => $this->doliprane, 'numero' => 'DP-PROCHE', 'datePeremption' => new \DateTimeImmutable('today +3 months'), 'quantiteInitiale' => 10, 'prixAchat' => 1100]);
        LotFactory::createOne(['produit' => $this->doliprane, 'numero' => 'DP-LOIN', 'datePeremption' => new \DateTimeImmutable('today +1 year'), 'quantiteInitiale' => 20, 'prixAchat' => 1150]);
        LotFactory::createOne(['produit' => $this->coartem, 'numero' => 'CO-1', 'datePeremption' => null, 'quantiteInitiale' => 8, 'prixAchat' => 2900]);
        // Bêta connaît déjà le Doliprane 500 mg (avec ses propres prix) : les lots iront sur sa fiche.
        $this->dolipraneBeta = ProduitFactory::createOne(['pharmacie' => $this->beta, 'nomCommercial' => 'Doliprane', 'dosage' => '500 mg', 'prixAchat' => 1200, 'prixVente' => 1600]);
    }

    public function testTransfertExpedieParLOrigineEtRecuParLaDestination(): void
    {
        $annee = date('Y');
        $crawler = $this->connecter($this->alpha->proprietaire)->request('GET', '/transferts');
        self::assertResponseIsSuccessful();
        $destinations = $crawler->filter('#destination option')->each(static fn ($o) => $o->text());
        self::assertSame(['Officine Bêta (Kati)'], $destinations, 'Gamma appartient à un autre pharmacien.');

        $this->client->submitForm('Créer le transfert', ['note' => 'Dépannage']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', "Transfert TRF-$annee-000001 vers Officine Bêta créé");
        $id = $this->transfert()->getId();

        $this->ajouter($id, 'Doliprane', 15);
        $this->ajouter($id, 'Coartem', 3);
        self::assertSelectorTextContains('#quantite-totale', '18');

        $this->client->submitForm('Expédier');
        self::assertResponseRedirects('/transferts/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', "Transfert TRF-$annee-000001 expédié");
        self::assertSelectorTextContains('#statut', 'Expédié');
        self::assertSelectorTextContains('#lignes', 'DP-PROCHE');
        self::assertSelectorNotExists('form#annuler', 'Un transfert expédié ne s\'annule plus.');
        self::assertSelectorTextContains('#valeur', "25\u{00A0}450\u{00A0}FCFA", '10 × 1 100 + 5 × 1 150 + 3 × 2 900.');

        // Origine : sortie FEFO, le lot périmé reste ; mouvements « Transfert » portant le numéro.
        self::assertSame(['DP-PERIME' => 5, 'DP-PROCHE' => 0, 'DP-LOIN' => 15, 'CO-1' => 5], $this->quantitesLots($this->alpha->pharmacie));
        $mouvements = $this->mouvements($this->alpha->pharmacie);
        self::assertSame([-10, -5, -3], array_map(static fn (MouvementStock $m) => $m->getQuantite(), $mouvements));
        self::assertSame(["TRF-$annee-000001"], array_values(array_unique(array_map(static fn (MouvementStock $m) => $m->getDocument(), $mouvements))));
        self::assertSame([AuditLogger::TRANSFERT_EXPEDIE], $this->actions($this->alpha->pharmacie));

        // Les responsables de Bêta sont prévenus.
        $notifications = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(Notification::class)->findBy(['pharmacie' => $this->beta]));
        self::assertCount(2, $notifications, 'Mariam et l\'adjoint de Bêta.');
        self::assertStringContainsString("Transfert TRF-$annee-000001 de Officine Alpha à réceptionner : 18 unité(s).", $notifications[0]->getMessage());
        self::assertSame('/transferts/'.$id, $notifications[0]->getLien());

        // Destination : l'adjoint de Bêta confirme la réception.
        $crawler = $this->connecter($this->adjointBeta)->request('GET', '/transferts');
        self::assertSelectorTextContains('#nombre-a-recevoir', '1 à réceptionner');
        self::assertSelectorTextContains('#entrants', "TRF-$annee-000001");
        self::assertSelectorTextContains('#entrants', 'Officine Alpha');
        self::assertSelectorTextContains('#sortants', 'Aucun transfert envoyé');
        $this->client->click($crawler->filter('#entrants a')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#a-receptionner');
        $this->client->submitForm('Confirmer la réception');
        self::assertResponseRedirects('/transferts/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', "Transfert TRF-$annee-000001 reçu");
        self::assertSelectorTextContains('.alert-success', 'Produit(s) ajouté(s) à votre catalogue : Coartem');
        self::assertSelectorTextContains('#statut', 'Reçu');
        self::assertSelectorTextContains('#historique', 'Reçu le');
        self::assertSelectorTextContains('#historique', 'par Adjoint Bêta');

        // Mêmes lots, dates et prix d'achat chez Bêta ; Doliprane sur sa fiche existante, Coartem créé.
        $lots = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(Lot::class)->findBy(['pharmacie' => $this->beta], ['id' => 'ASC']));
        self::assertSame([
            ['Doliprane', 'DP-PROCHE', (new \DateTimeImmutable('today +3 months'))->format('Y-m-d'), 10, 1100],
            ['Doliprane', 'DP-LOIN', (new \DateTimeImmutable('today +1 year'))->format('Y-m-d'), 5, 1150],
            ['Coartem', 'CO-1', null, 3, 2900],
        ], array_map(static fn (Lot $l) => [$l->getProduit()->getNomCommercial(), $l->getNumero(), $l->getDatePeremption()?->format('Y-m-d'), $l->getQuantiteRestante(), $l->getPrixAchat()], $lots));
        self::assertSame($this->dolipraneBeta->getId(), $lots[0]->getProduit()->getId());
        $coartemBeta = $lots[2]->getProduit();
        self::assertSame([1, '20/120 mg', 3800, true], [
            \count($this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(Produit::class)->findBy(['pharmacie' => $this->beta, 'nomCommercial' => 'Coartem']))),
            $coartemBeta->getDosage(), $coartemBeta->getPrixVente(), $coartemBeta->isOrdonnanceObligatoire(),
        ]);
        $entrees = $this->mouvements($this->beta);
        self::assertSame([10, 5, 3], array_map(static fn (MouvementStock $m) => $m->getQuantite(), $entrees));
        self::assertSame([TypeMouvement::Transfert], array_values(array_unique(array_map(static fn (MouvementStock $m) => $m->getType(), $entrees), \SORT_REGULAR)));
        self::assertSame([AuditLogger::TRANSFERT_RECU], $this->actions($this->beta));

        $nonLues = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(Notification::class)->findBy(['pharmacie' => $this->beta, 'lueLe' => null]));
        self::assertSame([], $nonLues, 'L\'avis « à réceptionner » est marqué lu une fois le transfert reçu.');

        $transfert = $this->transfert();
        self::assertSame(StatutTransfert::Recu, $transfert->getStatut());
        self::assertSame($this->adjointBeta->getId(), $transfert->getRecuPar()?->getId());

        // L'origine voit le transfert reçu.
        $this->connecter($this->alpha->proprietaire)->request('GET', '/transferts/'.$id);
        self::assertSelectorTextContains('#statut', 'Reçu');
    }

    public function testRefuseEntreOfficinesDePharmaciensDifferents(): void
    {
        $this->connecter($this->gamma->proprietaire)->request('GET', '/transferts');
        self::assertSelectorExists('#aucune-destination');
        self::assertSelectorNotExists('#nouveau-transfert');

        // Même en forgeant le formulaire vers une officine d'un autre pharmacien.
        $this->client->request('POST', '/transferts/nouveau', [
            '_token' => $this->jeton('transfert-nouveau'),
            'destination' => (string) $this->alpha->pharmacie->getId(),
        ]);
        self::assertResponseRedirects('/transferts');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger, .alert-error', 'Officine Gamma et Officine Alpha n\'appartiennent pas au même pharmacien');
        self::assertSame(0, $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(TransfertStock::class)->count([])));
    }

    public function testExpeditionRefuseeSiLesOfficinesNOntPlusLeMemeProprietaire(): void
    {
        $id = $this->preparer(['Doliprane' => 5]);
        // Mariam n'a plus accès à Bêta : le transfert préparé ne peut plus partir.
        $this->sansFiltre(function (EntityManagerInterface $em): void {
            $affectation = $em->getRepository(\App\Entity\Affectation::class)->findOneBy(['utilisateur' => $this->alpha->proprietaire->getId(), 'pharmacie' => $this->beta->getId()]);
            self::assertNotNull($affectation);
            $affectation->setActif(false);
            $em->flush();
        });

        $this->connecter($this->alpha->proprietaire)->request('GET', '/transferts/'.$id);
        $this->client->submitForm('Expédier');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger, .alert-error', 'n\'appartiennent pas au même pharmacien');
        self::assertSame(StatutTransfert::EnPreparation, $this->transfert()->getStatut());
        self::assertSame(30, $this->quantitesLots($this->alpha->pharmacie)['DP-PROCHE'] + $this->quantitesLots($this->alpha->pharmacie)['DP-LOIN'], 'Stock intact.');
    }

    public function testAnnulationAvantExpeditionSansMouvementDeStock(): void
    {
        $id = $this->preparer(['Doliprane' => 5]);
        $this->client->submitForm('Annuler le transfert', ['motif' => 'Rupture réglée par le grossiste']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#statut', 'Annulé');
        self::assertSelectorTextContains('#historique', 'Rupture réglée par le grossiste');
        self::assertSelectorNotExists('#recherche-produit');
        self::assertSame([], $this->mouvements($this->alpha->pharmacie));
        self::assertSame([AuditLogger::TRANSFERT_ANNULE], $this->actions($this->alpha->pharmacie));

        // Un transfert annulé ne s'expédie pas, et la destination ne le voit pas.
        $this->client->request('POST', '/transferts/'.$id.'/expedier', ['_token' => $this->jeton('transfert-'.$id)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger, .alert-error', 'n\'est plus modifiable');
        $this->connecter($this->adjointBeta)->request('GET', '/transferts/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testStockInsuffisantEtLotsPerimesExclus(): void
    {
        $id = $this->preparer([]);
        // 35 unités au total, mais 5 sont périmées : 30 transférables.
        $this->client->request('GET', '/transferts/'.$id, ['q' => 'Doliprane']);
        $this->client->submit($this->client->getCrawler()->filter('#resultats form')->first()->form(['quantite' => '31']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger, .alert-error', 'Stock insuffisant pour Doliprane : 30 disponible(s) pour 31 demandé(s).');
        self::assertSelectorTextContains('#lignes', 'Aucun produit');
    }

    public function testAccesReserveAuxDeuxOfficinesEtAuxResponsables(): void
    {
        $id = $this->preparer(['Doliprane' => 5]);

        // En préparation : la destination ne le voit pas encore ; une autre officine jamais.
        $this->connecter($this->adjointBeta)->request('GET', '/transferts/'.$id);
        self::assertResponseStatusCodeSame(404);
        $this->connecter($this->gamma->adjoint)->request('GET', '/transferts/'.$id);
        self::assertResponseStatusCodeSame(404);

        $this->connecter($this->alpha->proprietaire)->request('GET', '/transferts/'.$id);
        $this->client->submitForm('Expédier');

        // Expédié : la destination le voit mais ne peut ni le modifier ni l'expédier ; l'origine ne peut pas le réceptionner.
        $this->connecter($this->adjointBeta)->request('GET', '/transferts/'.$id);
        self::assertResponseIsSuccessful();
        $jeton = $this->jeton('transfert-'.$id);
        $this->client->request('POST', '/transferts/'.$id.'/annuler', ['_token' => $jeton, 'motif' => 'test']);
        self::assertResponseStatusCodeSame(404);
        $this->connecter($this->alpha->adjoint)->request('GET', '/transferts/'.$id);
        self::assertSelectorExists('#en-attente');
        self::assertSelectorNotExists('#a-receptionner');
        $this->client->request('POST', '/transferts/'.$id.'/receptionner', ['_token' => $this->jeton('transfert-'.$id)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger, .alert-error', 'Seule l\'officine destinataire confirme la réception');
        $this->connecter($this->gamma->proprietaire)->request('GET', '/transferts/'.$id);
        self::assertResponseStatusCodeSame(404);

        // Le vendeur n'a pas accès aux transferts.
        $this->connecter($this->alpha->vendeur)->request('GET', '/transferts');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Mariam (sur Alpha) prépare un transfert vers Bêta avec les produits donnés.
     *
     * @param array<string, int> $produits
     */
    private function preparer(array $produits): int
    {
        $this->connecter($this->alpha->proprietaire)->request('GET', '/transferts');
        $this->client->submitForm('Créer le transfert');
        self::assertResponseRedirects();
        $id = (int) $this->transfert()->getId();
        foreach ($produits as $nom => $quantite) {
            $this->ajouter($id, $nom, $quantite);
        }
        $this->client->request('GET', '/transferts/'.$id);

        return $id;
    }

    private function ajouter(int $id, string $produit, int $quantite): void
    {
        $this->client->request('GET', '/transferts/'.$id, ['q' => $produit]);
        $this->client->submit($this->client->getCrawler()->filter('#resultats form')->first()->form(['quantite' => (string) $quantite]));
        self::assertResponseRedirects('/transferts/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', \sprintf('%s : ', $produit));
    }

    /**
     * Jeton CSRF valide pour l'utilisateur connecté, pour forger une requête sans passer par un formulaire.
     */
    private function jeton(string $id): string
    {
        $this->client->request('GET', '/transferts');
        $session = $this->client->getRequest()->getSession();
        /** @var \Symfony\Component\HttpFoundation\RequestStack $pile */
        $pile = self::getContainer()->get('request_stack');
        $requete = new \Symfony\Component\HttpFoundation\Request();
        $requete->setSession($session);
        $pile->push($requete);
        try {
            /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf */
            $csrf = self::getContainer()->get('security.csrf.token_manager');
            $jeton = $csrf->getToken($id)->getValue();
            $session->save();

            return $jeton;
        } finally {
            $pile->pop();
        }
    }

    private function transfert(): TransfertStock
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em): TransfertStock {
            $transfert = $em->getRepository(TransfertStock::class)->findOneBy([], ['id' => 'DESC']);
            self::assertInstanceOf(TransfertStock::class, $transfert);

            return $transfert;
        });
    }

    /**
     * @return array<string, int> quantité restante par numéro de lot
     */
    private function quantitesLots(Pharmacie $pharmacie): array
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($pharmacie): array {
            $quantites = [];
            foreach ($em->getRepository(Lot::class)->findBy(['pharmacie' => $pharmacie], ['id' => 'ASC']) as $lot) {
                $quantites[$lot->getNumero()] = $lot->getQuantiteRestante();
            }

            return $quantites;
        });
    }

    /**
     * @return list<MouvementStock> mouvements de type transfert de la pharmacie
     */
    private function mouvements(Pharmacie $pharmacie): array
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(MouvementStock::class)->findBy(['pharmacie' => $pharmacie, 'type' => TypeMouvement::Transfert], ['id' => 'ASC']));
    }

    /**
     * @return list<string>
     */
    private function actions(Pharmacie $pharmacie): array
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => array_values(array_filter(
            array_map(static fn (JournalAudit $j) => $j->getAction(), $em->getRepository(JournalAudit::class)->findBy(['pharmacie' => $pharmacie], ['id' => 'ASC'])),
            static fn (string $a) => str_starts_with($a, 'transfert.'),
        )));
    }
}
