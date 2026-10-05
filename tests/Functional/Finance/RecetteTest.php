<?php

namespace App\Tests\Functional\Finance;

use App\Entity\JournalAudit;
use App\Entity\Recette;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Functional\Amo\AmoTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recettes automatiques des ventes et des règlements AMO, contre-passation à l'annulation (FI-04, RG-10, RG-12),
 * recettes manuelles (FI-05).
 */
final class RecetteTest extends AmoTestCase
{
    public function testRecettesDesVentesDesReglementsAmoEtContrePassation(): void
    {
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Doliprane', 'prixVente' => 1000]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 50]);

        $this->ouvrirCaisse($this->officine->adjoint);
        // Vente payée en deux modes : une recette par mode.
        $this->ajouter($produit, 3);
        $this->encaisser(['orange_money' => ['montant' => '1000', 'reference' => 'OM-1'], 'especes' => ['remis' => '5000']]);
        $mixte = $this->derniereVente();
        // Vente AMO : seule la part assuré (30 %) entre en recette ; la part AMO est une créance (RG-10).
        $this->vendreAmo();
        self::assertSame([['vente', 1000, 'orange_money'], ['vente', 2000, 'especes'], ['vente', 300, 'especes']], $this->recettes());

        // Annulation le jour même : chaque recette de la vente est contre-passée (RG-12).
        $this->client->request('GET', '/ventes/'.$mixte->getId());
        $this->client->submitForm('Annuler la vente', ['motif' => 'Erreur de produit']);
        self::assertSame([
            ['vente', 1000, 'orange_money'], ['vente', 2000, 'especes'], ['vente', 300, 'especes'],
            ['contre_passation', -1000, 'orange_money'], ['contre_passation', -2000, 'especes'],
        ], $this->recettes());

        // Le règlement du bordereau fait entrer la part AMO en recette, à la date du règlement.
        $this->creerBordereau();
        $this->client->followRedirect();
        $this->cliquer('Transmettre');
        $bordereau = $this->bordereau();
        $crawler = $this->client->request('GET', '/amo/bordereaux/'.$bordereau->getId());
        $formulaire = $crawler->filter('#reglement')->form();
        $formulaire['montant'] = '700';
        $formulaire['reference'] = 'VIR-INPS-1';
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
        $recettes = $this->recettes();
        self::assertSame(['amo', 700, 'virement'], end($recettes));

        $this->connecter($this->officine->proprietaire)->request('GET', '/recettes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#total-recettes', "1\u{00A0}000\u{00A0}FCFA");
        self::assertSelectorTextContains('#recettes', 'Contre-passation');
        self::assertSelectorTextContains('#recettes', 'Règlement AMO '.$bordereau->getNumero().' (INPS)');
        self::assertSelectorTextContains('#par-origine', "300\u{00A0}FCFA");
        self::assertSelectorTextContains('#par-origine', "700\u{00A0}FCFA");

        $this->client->request('GET', '/recettes', ['origine' => 'contre_passation']);
        self::assertSame(2, $this->client->getCrawler()->filter('#recettes tbody tr')->count());
    }

    public function testRecetteManuelleReserveeAuProprietaireEtAnnulee(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/recettes');
        self::assertResponseStatusCodeSame(403);

        $this->connecter($this->officine->proprietaire)->request('GET', '/recettes');
        $this->client->submitForm('Enregistrer la recette', ['libelle' => 'Location du parking', 'montant' => 'abc', 'mode' => 'especes']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Le montant de la recette doit être positif');

        $this->client->submitForm('Enregistrer la recette', ['libelle' => 'Location du parking', 'montant' => '25 000', 'mode' => 'orange_money']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Location du parking');
        self::assertSelectorTextContains('#total-recettes', "25\u{00A0}000\u{00A0}FCFA");
        self::assertSame([['manuelle', 25000, 'orange_money']], $this->recettes());

        $this->client->submitForm('Annuler', ['motif' => 'Montant encaissé par erreur']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'annulée par contre-passation');
        self::assertSelectorTextContains('#total-recettes', "0\u{00A0}FCFA");
        self::assertSelectorNotExists('.annulation-recette');
        self::assertSame([['manuelle', 25000, 'orange_money'], ['contre_passation', -25000, 'orange_money']], $this->recettes());
        $this->sansFiltre(static function (EntityManagerInterface $em): void {
            $audit = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'recette.annulee']);
            self::assertSame('Montant encaissé par erreur', $audit?->getApres()['motif'] ?? null);
        });
    }

    /**
     * @return list<array{string, int, string}> origine, montant, mode
     */
    private function recettes(): array
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => array_map(
            static fn (Recette $r) => [$r->getOrigine()->value, $r->getMontant(), $r->getMode()->value],
            $em->getRepository(Recette::class)->findBy([], ['id' => 'ASC']),
        ));
    }
}
