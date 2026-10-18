<?php

namespace App\Tests\Functional\Amo;

use App\Entity\ClientAssurance;
use App\Entity\LigneVente;
use App\Entity\OrganismeAmo;
use App\Entity\Produit;
use App\Enum\TypeOrganisme;
use App\Tests\Factory\ClientFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Retours des pharmacies du 18/10/2026 : part AMO sur le prix le plus bas (l'ordonnance reste au prix de la pharmacie),
 * accord préalable de l'AMO, taux propre à l'assuré et produits cochés pour une assurance autre que l'AMO.
 */
final class AccordEtPriseEnChargeTest extends AmoTestCase
{
    public function testAugmentinAvecAccordPrealable(): void
    {
        $augmentin = ProduitFactory::createOne([
            'pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Augmentin', 'prixVente' => 6800, 'prixVenteAmo' => 6000,
            'remboursableAmo' => true, 'accordPrealableAmo' => true,
        ]);
        LotFactory::createOne(['produit' => $augmentin, 'quantiteInitiale' => 20]);

        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($augmentin, 2);
        $this->poster('/caisse/client', ['client' => $this->assure->getId()]);
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d')]);
        $this->client->request('GET', '/caisse');

        // Sans le n° d'accord, l'Augmentin reste à la charge de l'assuré.
        self::assertSelectorExists('#panier .accord-prealable input[name=accord]');
        self::assertSelectorTextContains('#panier', 'Sans le n° d\'accord de l\'organisme, ce produit reste à la charge de l\'assuré.');
        self::assertSelectorTextContains('#totaux', "13\u{00A0}600\u{00A0}FCFA");
        self::assertSelectorTextContains('#part-organisme', "70 % de 0\u{00A0}FCFA");

        $ligne = $this->ligne($augmentin);
        $this->poster('/caisse/ligne/'.$ligne->getId(), ['quantite' => '2', 'accord' => 'ap-2026-0412']);
        self::assertResponseRedirects('/caisse');
        $this->client->request('GET', '/caisse');
        self::assertSelectorNotExists('#panier .text-warning-emphasis');
        self::assertSelectorTextContains('#panier', "part AMO sur 6\u{00A0}000\u{00A0}FCFA");
        self::assertSelectorTextContains('#part-organisme', "70 % de 12\u{00A0}000\u{00A0}FCFA");

        // Une coche « pris en charge » n'a pas de sens pour l'AMO.
        $this->poster('/caisse/ligne/'.$ligne->getId(), ['quantite' => '2', 'prise_en_charge' => '0']);
        self::assertStringContainsString('assurance autre que l\'AMO', $this->erreur());

        $this->encaisser(['especes' => ['remis' => '5200']]);
        self::assertResponseRedirects();
        $vente = $this->derniereVente();
        self::assertSame([13600, 8400, 5200], [$vente->getTotalBrut(), $vente->getPartAmo(), $vente->getMontantEncaisse()], 'Ordonnance 2 × 6 800 ; AMO 70 % de 2 × 6 000 ; l\'assuré paie le reste, écart de 1 600 compris.');
        self::assertSame(8400, $this->creance((int) $vente->getId())->getMontant());
        self::assertSame('AP-2026-0412', $this->ligne($augmentin)->getNumeroAccord());

        $this->client->request('GET', '/ventes/'.$vente->getId());
        self::assertSelectorTextContains('main', 'Accord préalable n° AP-2026-0412');
    }

    public function testAutreAssuranceTauxDeLAssureEtProduitsCoches(): void
    {
        $mutuelle = $this->sansFiltre(static function (EntityManagerInterface $em): OrganismeAmo {
            $mutuelle = (new OrganismeAmo())->setNom('Mutuelle Santé Sahel')->setCode('MSS')->setType(TypeOrganisme::Assurance);
            $em->persist($mutuelle);
            $em->flush();

            return $mutuelle;
        });
        $employe = ClientFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Fatoumata Keïta', 'numeroAssure' => 'MSS-2041', 'organismeAmo' => $mutuelle]);
        $creme = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Biafine', 'prixVente' => 2000]);
        LotFactory::createOne(['produit' => $creme, 'quantiteInitiale' => 20]);

        // Taux propre à l'assurée, selon son statut : 90 % au lieu du taux de la pharmacie.
        $crawler = $this->connecter($this->officine->vendeur)->request('GET', '/clients/'.$employe->getId().'/modifier');
        $valeurs = $crawler->filter('form[name=client]')->form()->getPhpValues();
        $valeurs['client']['assurances'][0]['taux'] = '90';
        $this->client->request('POST', '/clients/'.$employe->getId().'/modifier', $valeurs);
        self::assertResponseRedirects('/clients');
        $id = $employe->getId();
        self::assertSame(90, $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(ClientAssurance::class)->findOneBy(['client' => $id])?->getTaux()));

        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($this->remboursable, 1);
        $this->ajouter($creme, 1);
        $this->poster('/caisse/client', ['client' => $employe->getId()]);
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d')]);
        $coartem = $this->ligne($this->remboursable);
        $biafine = $this->ligne($creme);

        // Par défaut, les produits remboursables sont cochés ; on coche ce que la mutuelle prend en charge.
        $this->client->request('GET', '/caisse');
        self::assertSelectorExists('#prise-'.$coartem->getId().'[checked]');
        self::assertSelectorNotExists('#prise-'.$biafine->getId().'[checked]');
        self::assertSelectorTextContains('#part-organisme', "Part MSS (90 % de 1\u{00A0}000\u{00A0}FCFA)");

        $this->poster('/caisse/ligne/'.$coartem->getId(), ['quantite' => '1', 'prise_en_charge' => '0']);
        $this->poster('/caisse/ligne/'.$biafine->getId(), ['quantite' => '1', 'prise_en_charge' => '1']);
        $this->client->request('GET', '/caisse');
        self::assertSelectorNotExists('#prise-'.$coartem->getId().'[checked]');
        self::assertSelectorExists('#prise-'.$biafine->getId().'[checked]');
        self::assertSelectorTextContains('#part-organisme', "Part MSS (90 % de 2\u{00A0}000\u{00A0}FCFA)");

        $this->encaisser(['especes' => ['remis' => '1200']]);
        self::assertResponseRedirects();
        $vente = $this->derniereVente();
        self::assertSame([3000, 90, 2000, 1800, 1200], [$vente->getTotalBrut(), $vente->getTauxAmo(), $vente->getBaseAmo(), $vente->getPartAmo(), $vente->getMontantEncaisse()]);
    }

    public function testLaFicheProduitIndiqueLAccordPrealable(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/produits/'.$this->remboursable->getId().'/modifier');
        $this->client->submitForm('Enregistrer', ['produit[accordPrealableAmo]' => '1']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('#accord-prealable', 'Accord préalable AMO');
    }

    private function ligne(Produit $produit): LigneVente
    {
        $id = $produit->getId();

        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($id): LigneVente {
            $ligne = $em->getRepository(LigneVente::class)->findOneBy(['produit' => $id], ['id' => 'DESC']);
            self::assertInstanceOf(LigneVente::class, $ligne);

            return $ligne;
        });
    }
}
