<?php

namespace App\Tests\Functional\Commande;

use App\Entity\Commande;
use App\Tests\Factory\EntrepotFactory;
use App\Tests\Factory\LotFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Avant de commander au grossiste, les suggestions regardent les entrepôts de la pharmacie (Modibo, 2026-10-22) :
 * ce qui y est disponible est à transférer d'abord, seul le reste est commandé.
 */
final class SuggestionsEntrepotsTest extends CommandeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $p = $this->officine->pharmacie;
        $kalaban = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Kalaban']);
        $sotuba = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Sotuba']);
        // Doliprane : 30 en entrepôts (deux entrepôts), Coartem : 3, Smecta : 50 (plus qu'il n'en faut).
        LotFactory::createOne(['produit' => $this->doliprane, 'quantiteInitiale' => 20, 'entrepot' => $kalaban]);
        LotFactory::createOne(['produit' => $this->doliprane, 'quantiteInitiale' => 10, 'entrepot' => $sotuba]);
        LotFactory::createOne(['produit' => $this->coartem, 'quantiteInitiale' => 3, 'entrepot' => $sotuba]);
        LotFactory::createOne(['produit' => $this->smecta, 'quantiteInitiale' => 50, 'entrepot' => $kalaban]);
        // Un entrepôt archivé ne compte pas.
        $archive = EntrepotFactory::createOne(['pharmacie' => $p, 'nom' => 'Ancien dépôt', 'actif' => false]);
        LotFactory::createOne(['produit' => $this->coartem, 'quantiteInitiale' => 40, 'entrepot' => $archive]);
    }

    public function testLeStockDesEntrepotsEstDeduitAvantLAchat(): void
    {
        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/commandes/suggestions?fournisseur='.$this->ppm->getId());
        self::assertResponseIsSuccessful();

        // À transférer d'abord : Doliprane 30 (besoin 56), Coartem 3 (besoin 10), Smecta 28 (besoin 28, 50 en stock).
        self::assertCount(3, $crawler->filter('#suggestions-transfert tbody tr'));
        self::assertStringContainsString('30', $crawler->filter('#suggestions-transfert tr[data-produit="Doliprane"] td')->eq(3)->text());
        self::assertStringContainsString('26', $crawler->filter('#suggestions-transfert tr[data-produit="Doliprane"] td')->eq(4)->text());
        self::assertStringContainsString('3', $crawler->filter('#suggestions-transfert tr[data-produit="Coartem"] td')->eq(3)->text());
        self::assertStringContainsString('28', $crawler->filter('#suggestions-transfert tr[data-produit="Smecta"] td')->eq(3)->text());

        // Seul le reste est commandé : 60 − (4 + 30) pour Doliprane, 10 − 3 pour Coartem.
        self::assertInputValueSame('quantite['.$this->doliprane->getId().']', '26');
        self::assertInputValueSame('quantite['.$this->coartem->getId().']', '7');
        self::assertSelectorTextNotContains('#suggestions tr[data-produit="Coartem"]', 'rupture', 'Il en reste en entrepôt : pas de rupture.');
        // Smecta est entièrement couvert par l'entrepôt : Laborex n'a plus rien à recevoir.
        self::assertSelectorTextNotContains('#fournisseurs-suggeres', 'Laborex');

        // « Préparer le transfert » ouvre le transfert vers l'officine avec les quantités suggérées.
        $this->client->clickLink('Préparer le transfert');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#destination option[selected]', 'Officine');
        self::assertSelectorTextContains('#origine option[selected]', 'Kalaban', 'L\'entrepôt qui a le plus du premier produit.');
        self::assertInputValueSame('p['.$this->doliprane->getId().']', '20', 'Plafonné au disponible de l\'entrepôt choisi.');
        self::assertInputValueSame('p['.$this->smecta->getId().']', '28');

        // Les brouillons préparés ne contiennent que ce qui manque encore.
        $crawler = $this->client->request('GET', '/commandes/suggestions');
        $this->client->submit($crawler->selectButton('Préparer toutes les commandes')->form());
        self::assertResponseRedirects();
        $this->sansFiltre(static function (EntityManagerInterface $em): void {
            $commandes = $em->getRepository(Commande::class)->findAll();
            self::assertCount(1, $commandes, 'Rien chez Laborex.');
            $lignes = [];
            foreach ($commandes[0]->getLignes() as $ligne) {
                $lignes[$ligne->getProduit()->getNomCommercial()] = $ligne->getQuantite();
            }
            ksort($lignes);
            self::assertSame(['Coartem' => 7, 'Doliprane' => 26], $lignes);
        });
    }

    public function testLaReceptionPeutEntrerDansUnEntrepot(): void
    {
        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/commandes/suggestions?fournisseur='.$this->ppm->getId());
        $this->client->submit($crawler->filter('#formulaire-suggestions')->form());
        $id = (int) $this->commande()->getId();
        $this->client->request('GET', '/commandes/'.$id);
        $this->cliquer('Passer sans email');

        $crawler = $this->client->clickLink('Réceptionner');
        self::assertSelectorTextContains('#entrepot', 'Entrepôt Kalaban');
        self::assertSelectorTextNotContains('#entrepot', 'Ancien dépôt', 'Un entrepôt archivé n\'est pas proposé.');
        $formulaire = $crawler->filter('#reception')->form();
        $formulaire['entrepot'] = (string) $this->entrepotId('Sotuba');
        $formulaire['lignes[0][quantite]'] = '5';
        $formulaire['lignes[0][lot]'] = 'REC1';
        $formulaire['lignes[0][peremption]'] = (new \DateTimeImmutable('today +1 year'))->format('Y-m-d');
        $formulaire['lignes[0][prix]'] = '1000';
        $formulaire['lignes[1][quantite]'] = '';
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/commandes/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Réception enregistrée dans l\'entrepôt Sotuba');

        $this->sansFiltre(static function (EntityManagerInterface $em): void {
            $lot = $em->getRepository(\App\Entity\Lot::class)->findOneBy(['numero' => 'REC1']);
            self::assertSame('Sotuba', $lot?->getEntrepot()?->getNom());
        });
    }

    private function entrepotId(string $nom): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => (int) $em->getRepository(\App\Entity\Entrepot::class)->findOneBy(['nom' => $nom])?->getId());
    }
}
