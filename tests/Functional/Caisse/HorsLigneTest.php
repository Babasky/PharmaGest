<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\Lot;
use App\Entity\OrganismeAmo;
use App\Entity\Recette;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Entity\VenteHorsLigne;
use App\Enum\ModePaiement;
use App\Enum\PolitiqueSansOrdonnance;
use App\Enum\StatutVente;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Caisse hors ligne : copie du catalogue emportée par le poste, ventes faites pendant une coupure d'internet,
 * envoyées au retour du réseau et importées comme des ventes normales, ou gardées en anomalie.
 */
final class HorsLigneTest extends CaisseTestCase
{
    public function testLePosteEmporteLeCatalogueDeSaPharmacie(): void
    {
        $p = $this->officine->pharmacie;
        $produit = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Doliprane', 'dci' => 'Paracétamol', 'dosage' => '500 mg', 'prixVente' => 1500, 'codeBarres' => '3400935955534']);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 12]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 5, 'datePeremption' => new \DateTimeImmutable('yesterday')]);
        ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Archivé', 'actif' => false]);
        $ailleurs = $this->creerOfficine();
        ProduitFactory::createOne(['pharmacie' => $ailleurs->pharmacie, 'nomCommercial' => 'Ailleurs']);

        $this->connecter($this->officine->vendeur)->request('GET', '/caisse/hors-ligne/donnees');
        self::assertResponseIsSuccessful();
        $donnees = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($this->officine->vendeur->getId(), $donnees['utilisateur']['id']);
        self::assertSame($p->getId(), $donnees['pharmacie']['id']);
        self::assertFalse($donnees['sessionOuverte']);
        self::assertNotEmpty($donnees['jeton']);
        self::assertSame('blocage', $donnees['politiqueSansOrdonnance']);
        self::assertNotEmpty($donnees['organismes']);
        // Seuls les produits vendables de la pharmacie, avec le stock non périmé.
        self::assertSame([[$produit->getId(), 'Doliprane', 'Paracétamol 500 mg', '3400935955534', 1500, null, 0, 0, 12]], $donnees['produits']);

        // La page elle-même ne contient aucune donnée : elle peut être gardée par le service worker.
        $this->client->request('GET', '/caisse/hors-ligne');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#hl-caisse');
        self::assertStringNotContainsString('Doliprane', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('caisse_hors_ligne', (string) $this->client->getResponse()->getContent());
    }

    public function testLaVenteHorsLigneDevientUneVenteNormaleALEnvoi(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Doliprane', 'prixVente' => 1500]);
        $lot = LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 10]);
        $this->ouvrirCaisse($this->officine->vendeur, 5000);

        $venduLe = new \DateTimeImmutable('-2 hours');
        $vente = $this->venteHorsLigne([['produit' => $produit->getId(), 'quantite' => 3]], 4500, [
            'especes' => ['remis' => 3000],
            'orange_money' => ['montant' => 2000, 'reference' => 'OM-778'],
        ], venduLe: $venduLe);
        $resultats = $this->envoyer([$vente]);

        self::assertSame('importee', $resultats[$vente['uuid']]['statut']);
        self::assertMatchesRegularExpression('/^V-\d{4}-000001$/', (string) $resultats[$vente['uuid']]['numero']);
        $importee = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $importee->getStatut());
        self::assertSame('HL-AB12-1', $importee->getNumeroHorsLigne());
        self::assertSame($venduLe->format('Y-m-d H:i'), $importee->getValideeLe()?->format('Y-m-d H:i'), 'La vente garde sa date.');
        self::assertSame([4500, 4500], [$importee->getTotalNet(), $importee->getMontantEncaisse()]);
        $this->sansFiltre(function (EntityManagerInterface $em) use ($lot, $importee): void {
            self::assertSame(7, $em->find(Lot::class, $lot->getId())?->getQuantiteRestante());
            $vente = $em->find(Vente::class, $importee->getId());
            self::assertSame($this->officine->vendeur->getId(), $vente?->getSession()?->getUtilisateur()->getId());
            self::assertSame(2500, $vente->montantPaye(ModePaiement::Especes));
            self::assertSame(2000, $vente->montantPaye(ModePaiement::OrangeMoney));
            self::assertSame(2, $em->getRepository(Recette::class)->count(['vente' => $vente->getId()]));
        });

        // Un nouvel envoi (réponse perdue) ne crée pas de seconde vente.
        $resultats = $this->envoyer([$vente]);
        self::assertSame('importee', $resultats[$vente['uuid']]['statut']);
        $this->sansFiltre(static function (EntityManagerInterface $em): void {
            self::assertSame(1, $em->getRepository(Vente::class)->count([]));
            self::assertSame(1, $em->getRepository(VenteHorsLigne::class)->count([]));
        });

        // La vente affiche le numéro du ticket remis au client.
        $this->client->request('GET', '/ventes/'.$importee->getId());
        self::assertSelectorTextContains('#hors-ligne', 'HL-AB12-1');
    }

    public function testCaisseFermeeLaVenteAttendLOuvertureDeLaCaisse(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1000]);
        LotFactory::createOne(['produit' => $produit]);
        $this->connecter($this->officine->vendeur);

        $vente = $this->venteHorsLigne([['produit' => $produit->getId(), 'quantite' => 2]], 2000);
        $resultats = $this->envoyer([$vente]);
        self::assertSame('anomalie', $resultats[$vente['uuid']]['statut']);
        self::assertStringContainsString('caisse de '.$this->officine->vendeur->getNom().' est fermée', (string) $resultats[$vente['uuid']]['message']);

        $this->client->request('GET', '/ventes/hors-ligne');
        self::assertSelectorTextContains('tbody', 'HL-AB12-1');
        self::assertSelectorTextContains('tbody', 'En anomalie');

        // À l'ouverture de la caisse, la vente y est rattachée.
        $this->client->request('GET', '/caisse');
        $this->client->submitForm('Ouvrir la caisse', ['fond' => '0']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-info', '1 vente(s) faite(s) hors ligne rattachée(s)');
        self::assertSame('HL-AB12-1', $this->derniereVente()->getNumeroHorsLigne());
    }

    public function testStockInsuffisantPuisRejetMotiveParLAdjoint(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Coartem', 'prixVente' => 4000]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 1]);
        $this->ouvrirCaisse($this->officine->vendeur);

        $vente = $this->venteHorsLigne([['produit' => $produit->getId(), 'quantite' => 2]], 8000);
        $resultats = $this->envoyer([$vente]);
        self::assertSame('anomalie', $resultats[$vente['uuid']]['statut']);
        self::assertStringContainsString('Coartem', (string) $resultats[$vente['uuid']]['message']);
        $horsLigne = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(VenteHorsLigne::class)->findOneBy([]));
        self::assertInstanceOf(VenteHorsLigne::class, $horsLigne);

        // Le vendeur ne peut que réessayer ; le rejet revient à l'adjoint.
        $crawler = $this->client->request('GET', '/ventes/hors-ligne');
        self::assertSelectorExists('button:contains("Réessayer")');
        self::assertSelectorNotExists('button:contains("Rejeter")');
        $jeton = $crawler->filter('form[action$="/reessayer"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/ventes/hors-ligne/'.$horsLigne->getId().'/rejeter', ['_token' => $jeton, 'motif' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->connecter($this->officine->adjoint);
        $crawler = $this->client->request('GET', '/ventes/hors-ligne');
        $this->client->submit($crawler->filter('#rejeter-'.$horsLigne->getId().' form')->form(), ['motif' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Le motif du rejet est obligatoire');

        $crawler = $this->client->request('GET', '/ventes/hors-ligne');
        $this->client->submit($crawler->filter('#rejeter-'.$horsLigne->getId().' form')->form(), ['motif' => 'Boîte cassée, rien vendu en réalité']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Ticket HL-AB12-1 rejeté');
        self::assertSelectorTextContains('tbody', 'Rejetée');
        $this->sansFiltre(static function (EntityManagerInterface $em): void {
            self::assertSame(0, $em->getRepository(Vente::class)->count([]));
            $actions = $em->createQuery('SELECT j.action FROM App\Entity\JournalAudit j')->getSingleColumnResult();
            self::assertContains('vente.hors_ligne_rejetee', $actions);
        });
    }

    public function testVenteAmoEtEcartDePrixTrace(): void
    {
        $p = $this->officine->pharmacie;
        $rembourse = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Coartem', 'prixVente' => 4000, 'remboursableAmo' => true]);
        LotFactory::createOne(['produit' => $rembourse]);
        $inps = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'INPS']));
        self::assertInstanceOf(OrganismeAmo::class, $inps);
        $this->ouvrirCaisse($this->officine->vendeur);

        // Le ticket a été fait avec l'ancien prix (3 500) : le client a payé 1 050 au lieu de 1 200.
        $vente = $this->venteHorsLigne([['produit' => $rembourse->getId(), 'quantite' => 1]], 1050, ['especes' => ['remis' => 2000]], 'amo', [
            'ordonnance' => ['date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'numero' => 'ORD-9'],
            'organisme' => $inps->getId(),
            'numeroAssure' => 'INPS-0045871',
        ]);
        $resultats = $this->envoyer([$vente]);
        self::assertSame('importee', $resultats[$vente['uuid']]['statut']);

        $importee = $this->derniereVente();
        self::assertSame(['INPS', 'INPS-0045871', 2800, 1200, 'ORD-9'], [$importee->getOrganismeAmo()?->getCode(), $importee->getNumeroAssure(), $importee->getPartAmo(), $importee->getMontantEncaisse(), $importee->getOrdonnance()?->getNumero()]);
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($importee): void {
            self::assertSame(1200, $em->find(Vente::class, $importee->getId())?->montantPaye(ModePaiement::Especes));
            self::assertSame(150, $em->getRepository(VenteHorsLigne::class)->findOneBy([])?->getEcart());
            $actions = $em->createQuery('SELECT j.action FROM App\Entity\JournalAudit j')->getSingleColumnResult();
            self::assertContains('vente.hors_ligne_ecart', $actions);
        });
    }

    public function testCodePinImpossibleHorsLigne(): void
    {
        $antibiotique = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Amoxicilline', 'ordonnanceObligatoire' => true, 'prixVente' => 2500]);
        LotFactory::createOne(['produit' => $antibiotique]);
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getConnection()->executeStatement(
            'UPDATE parametre_pharmacie SET politique_sans_ordonnance = ? WHERE pharmacie_id = ?',
            [PolitiqueSansOrdonnance::Confirmation->value, $this->officine->pharmacie->getId()],
        ));

        $vente = $this->venteHorsLigne([['produit' => $antibiotique->getId(), 'quantite' => 1]], 2500);
        $resultats = $this->envoyer([$vente]);
        self::assertSame('anomalie', $resultats[$vente['uuid']]['statut']);
        self::assertStringContainsString('code PIN ne peut pas être saisi hors ligne', (string) $resultats[$vente['uuid']]['message']);
    }

    public function testEnvoiProtegeEtCloisonne(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie]);
        LotFactory::createOne(['produit' => $produit]);
        $ailleurs = $this->creerOfficine();
        $etranger = ProduitFactory::createOne(['pharmacie' => $ailleurs->pharmacie]);
        LotFactory::createOne(['produit' => $etranger]);
        $collegue = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_VENDEUR]);
        AffectationFactory::createOne(['utilisateur' => $collegue, 'pharmacie' => $this->officine->pharmacie]);
        $this->ouvrirCaisse($this->officine->vendeur);

        // Sans jeton valide, rien n'est reçu.
        $vente = $this->venteHorsLigne([['produit' => $produit->getId(), 'quantite' => 1]], 1200);
        $this->client->request('POST', '/caisse/hors-ligne/synchroniser', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'faux'], (string) json_encode(['ventes' => [$vente]]));
        self::assertResponseStatusCodeSame(403);

        // Un produit d'une autre pharmacie est introuvable ; une vente mal formée est refusée.
        $intruse = $this->venteHorsLigne([['produit' => $etranger->getId(), 'quantite' => 1]], 1200, numero: 'HL-AB12-2');
        $malFormee = ['uuid' => '0b7e7c3e-1f1a-4c1e-9d1a-000000000003', 'numero' => 'X', 'lignes' => []];
        $resultats = $this->envoyer([$intruse, $malFormee]);
        self::assertSame('anomalie', $resultats[$intruse['uuid']]['statut']);
        self::assertStringContainsString('introuvable', (string) $resultats[$intruse['uuid']]['message']);
        self::assertSame('refusee', $resultats[$malFormee['uuid']]['statut']);

        // Un collègue qui renverrait le même identifiant n'écrase rien et ne voit pas la vente.
        $this->envoyer([$vente]);
        $this->connecter($collegue);
        $resultats = $this->envoyer([$vente]);
        self::assertArrayNotHasKey($vente['uuid'], $resultats);
        $this->client->request('GET', '/ventes/hors-ligne');
        self::assertSelectorTextContains('tbody', 'Aucune vente hors ligne reçue');
    }

    /**
     * @param list<array{produit: int, quantite: int}> $lignes
     * @param array<string, mixed>                     $paiements
     * @param array<string, mixed>                     $autres
     *
     * @return array<string, mixed>
     */
    private function venteHorsLigne(array $lignes, int $montant, array $paiements = [], string $type = 'sans_ordonnance', array $autres = [], string $numero = 'HL-AB12-1', ?\DateTimeImmutable $venduLe = null): array
    {
        static $compteur = 0;

        return [
            'uuid' => \sprintf('0b7e7c3e-1f1a-4c1e-9d1a-%012d', ++$compteur),
            'numero' => $numero,
            'venduLe' => ($venduLe ?? new \DateTimeImmutable('-10 minutes'))->format(\DATE_ATOM),
            'type' => $type,
            'lignes' => $lignes,
            'montant' => $montant,
            'paiements' => $paiements,
            ...$autres,
        ];
    }

    /**
     * Envoi des ventes comme le poste : jeton pris dans la copie du catalogue.
     *
     * @param list<array<string, mixed>> $ventes
     *
     * @return array<string, array{statut: string, message: ?string, numero: ?string}>
     */
    private function envoyer(array $ventes): array
    {
        $this->client->request('GET', '/caisse/hors-ligne/donnees');
        $jeton = json_decode((string) $this->client->getResponse()->getContent(), true)['jeton'];
        $this->client->request('POST', '/caisse/hors-ligne/synchroniser', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $jeton], (string) json_encode(['ventes' => $ventes]));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['resultats'];
    }
}
