<?php

namespace App\Tests\Functional\Amo;

use App\Entity\LigneVente;
use App\Entity\OrganismeAmo;
use App\Entity\Produit;
use App\Entity\Vente;
use App\Enum\StatutCreance;
use App\Enum\TypeOrganisme;
use App\Tests\Factory\ClientFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Autres assurances que l'AMO (AM-12) et prix de vente AMO par médicament (AM-13, RG-07).
 */
final class AssurancesTest extends AmoTestCase
{
    public function testVenteAvecUneAutreAssuranceASonPropreTaux(): void
    {
        $mutuelle = $this->sansFiltre(static function (EntityManagerInterface $em): OrganismeAmo {
            $mutuelle = (new OrganismeAmo())->setNom('Mutuelle Santé Sahel')->setCode('MSS')->setType(TypeOrganisme::Assurance);
            $em->persist($mutuelle);
            $em->flush();

            return $mutuelle;
        });
        $employe = ClientFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Fatoumata Keïta', 'numeroAssure' => 'MSS-2041', 'organismeAmo' => $mutuelle, 'entreprise' => 'ONG Santé pour tous']);
        $produitId = $this->remboursable->getId();
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($produitId): void {
            $em->find(Produit::class, $produitId)?->setPrixVenteAmo(800);
            $em->flush();
        });

        // Le propriétaire fixe le taux de la mutuelle, comme pour un organisme AMO.
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=amo');
        $this->client->submitForm('Ajouter ce taux', [
            'taux_amo[organisme]' => (string) $mutuelle->getId(),
            'taux_amo[taux]' => '80',
            'taux_amo[dateEffet]' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
        self::assertResponseRedirects('/parametres?onglet=amo');

        $this->ouvrirCaisse($this->officine->adjoint);
        $id = $this->vendreAmo(2, $employe);

        $vente = $this->vente($id);
        self::assertSame('MSS', $vente->getOrganismeAmo()?->getCode());
        self::assertFalse($vente->tarifAmo(), 'Le prix AMO ne concerne pas une autre assurance.');
        self::assertSame([2000, 80, 1600, 400], [$vente->getBaseAmo(), $vente->getTauxAmo(), $vente->getPartAmo(), $vente->getMontantEncaisse()], '80 % de 2 × 1 000, au prix de la pharmacie.');
        $creance = $this->creance($id);
        self::assertSame([1600, StatutCreance::EnAttente, 'MSS'], [$creance->getMontant(), $creance->getStatut(), $creance->getOrganisme()->getCode()]);

        $this->client->request('GET', '/ventes/'.$id);
        self::assertSelectorTextContains('#totaux', 'Part MSS (80 %');

        // Bordereau propre à la mutuelle, comme pour l'INPS.
        $this->creerBordereau($mutuelle);
        self::assertResponseRedirects();
        $bordereau = $this->bordereau();
        self::assertSame(['MSS', 1600], [$bordereau->getOrganisme()->getCode(), $bordereau->getMontant()]);
        self::assertCount(1, $bordereau->getCreances());
        $this->client->request('GET', '/amo');
        self::assertSelectorTextContains('main', 'Mutuelle Santé Sahel');
    }

    public function testLeTauxAmoSAppliqueAuPrixDeVenteAmoDuMedicament(): void
    {
        // Le propriétaire saisit le prix fixé par l'AMO sur la fiche produit (1 000 FCFA à la pharmacie).
        $this->connecter($this->officine->proprietaire)->request('GET', '/produits/'.$this->remboursable->getId().'/modifier');
        $this->client->submitForm('Enregistrer', ['produit[prixVenteAmo]' => '800']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('#prix-vente-amo', "800\u{00A0}FCFA");

        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($this->remboursable, 2);
        $this->poster('/caisse/client', ['client' => $this->assure->getId()]);
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'ordonnance_prescripteur' => 'Dr Coulibaly']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#panier', "AMO 800\u{00A0}FCFA");
        self::assertSelectorTextContains('#part-organisme', "Part INPS (70 % de 1\u{00A0}600\u{00A0}FCFA, prix AMO)");
        self::assertSelectorTextContains('#totaux', "1\u{00A0}120\u{00A0}FCFA");
        self::assertSelectorTextContains('#totaux', "880\u{00A0}FCFA");

        $this->encaisser(['especes' => ['remis' => '1000']]);
        self::assertResponseRedirects();
        $id = (int) $this->derniereVente()->getId();
        $vente = $this->vente($id);
        self::assertTrue($vente->tarifAmo());
        self::assertSame([2000, 1600, 1120, 880], [$vente->getTotalBrut(), $vente->getBaseAmo(), $vente->getPartAmo(), $vente->getMontantEncaisse()], 'Part AMO = 2 × 800 × 70 % ; l\'assuré paie le reste du prix de la pharmacie.');
        self::assertSame(1120, $this->creance($id)->getMontant());

        // Un nouveau prix AMO ne change pas la vente passée (RG-06).
        $this->connecter($this->officine->proprietaire)->request('GET', '/produits/'.$this->remboursable->getId().'/modifier');
        $this->client->submitForm('Enregistrer', ['produit[prixVenteAmo]' => '900']);
        self::assertSame([1600, 1120], [$this->vente($id)->getBaseAmo(), $this->vente($id)->getPartAmo()]);

        // Modification tracée au journal comme les autres prix.
        $crawler = $this->client->request('GET', '/journal');
        self::assertStringContainsString('prix vente amo : 900', $crawler->filter('#journal')->text());
    }

    public function testSansPrixAmoLePrixDeLaPharmacieSertDeBase(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $id = $this->vendreAmo(2);

        $vente = $this->vente($id);
        self::assertSame([2000, 1400, 600], [$vente->getBaseAmo(), $vente->getPartAmo(), $vente->getMontantEncaisse()]);
    }

    private function vente(int $id): Vente
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($id): Vente {
            $vente = $em->find(Vente::class, $id);
            self::assertInstanceOf(Vente::class, $vente);
            $vente->getLignes()->map(static fn (LigneVente $l) => $l->getPrixUnitaireAmo())->toArray();
            $vente->getOrganismeAmo()?->getCode();

            return $vente;
        });
    }
}
