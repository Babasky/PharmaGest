<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\LigneVente;
use App\Entity\OrganismeAmo;
use App\Entity\Vente;
use App\Enum\ModePaiement;
use App\Enum\StatutVente;
use App\Tests\Factory\ClientFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remise par ligne, produit par produit, dans le panier de la caisse (RE-02), y compris sur une vente AMO / assurance
 * où elle porte sur la part assuré de la ligne (RG-09).
 */
final class RemiseParLigneTest extends CaisseTestCase
{
    public function testRemiseDifferenteSurChaqueProduit(): void
    {
        $p = $this->officine->pharmacie;
        $doliprane = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Doliprane', 'prixVente' => 1500]);
        $amoxicilline = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Amoxicilline', 'prixVente' => 4000]);
        LotFactory::createOne(['produit' => $doliprane]);
        LotFactory::createOne(['produit' => $amoxicilline]);
        $client = ClientFactory::createOne(['pharmacie' => $p, 'nom' => 'Aïssata Cissé', 'privilegie' => true]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($doliprane, 2);
        $this->ajouter($amoxicilline);
        $this->poster('/caisse/client', ['client' => $client->getId()]);
        [$ligneDoliprane, $ligneAmoxicilline] = $this->formulairesDesLignes();

        $this->poster($ligneDoliprane, ['quantite' => 2, 'remise_valeur' => '10', 'remise_type' => 'pourcentage']);
        $this->poster($ligneAmoxicilline, ['quantite' => 1, 'remise_valeur' => '400', 'remise_type' => 'montant']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#panier', "− 300\u{00A0}FCFA");
        self::assertSelectorTextContains('#panier', "− 400\u{00A0}FCFA");
        self::assertSelectorTextContains('#totaux', "6\u{00A0}300\u{00A0}FCFA");

        $this->encaisser(['especes' => ['remis' => '6300']]);
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut(), '10 % au plus sur chaque ligne : pas de code PIN.');
        self::assertSame([7000, 700, 6300], [$vente->getTotalBrut(), $vente->getRemise(), $vente->getMontantEncaisse()]);
        self::assertSame(['Doliprane' => 300, 'Amoxicilline' => 400], $this->remisesFigees($vente));

        $this->client->request('GET', '/ventes/'.$vente->getId());
        self::assertSelectorTextContains('#lignes', "− 300\u{00A0}FCFA");
        self::assertSelectorTextContains('#lignes', "− 400\u{00A0}FCFA");
    }

    public function testSurUneVenteAmoLaRemiseDeLigneNeTouchePasLaPartAmo(): void
    {
        $p = $this->officine->pharmacie;
        $augmentin = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Augmentin', 'prixVente' => 5000, 'remboursableAmo' => true]);
        $creme = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Creme', 'prixVente' => 2000, 'remboursableAmo' => false]);
        LotFactory::createOne(['produit' => $augmentin]);
        LotFactory::createOne(['produit' => $creme]);
        $inps = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'INPS']));
        self::assertInstanceOf(OrganismeAmo::class, $inps);
        $assure = ClientFactory::createOne(['pharmacie' => $p, 'nom' => 'Mariam Diallo', 'privilegie' => true, 'numeroAssure' => 'INPS-0045871', 'organismeAmo' => $inps]);

        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($augmentin, 2);
        $this->ajouter($creme);
        $this->poster('/caisse/client', ['client' => $assure->getId()]);
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'ordonnance_prescripteur' => 'Dr Coulibaly']);
        [$ligneAugmentin, $ligneCreme] = $this->formulairesDesLignes();
        self::assertSelectorExists('#panier input[name="remise_valeur"]');

        $this->poster($ligneAugmentin, ['quantite' => 2, 'remise_valeur' => '10', 'remise_type' => 'pourcentage']);
        $this->poster($ligneCreme, ['quantite' => 1, 'remise_valeur' => '10', 'remise_type' => 'pourcentage']);
        $this->client->followRedirect();
        self::assertSelectorNotExists('.alert-danger');
        self::assertSelectorTextContains('#totaux', "7\u{00A0}000\u{00A0}FCFA");
        self::assertSelectorTextContains('#totaux', "4\u{00A0}500\u{00A0}FCFA");

        $this->encaisser(['especes' => ['remis' => '4500']]);
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        // Part AMO : 10 000 × 70 % sans remise ; part assuré 3 000 + 2 000, remisée de 10 % ligne par ligne.
        self::assertSame([12000, 7000, 500, 4500], [$vente->getTotalBrut(), $vente->getPartAmo(), $vente->getRemise(), $vente->getMontantEncaisse()]);
        self::assertSame(['Augmentin' => 300, 'Creme' => 200], $this->remisesFigees($vente));
        self::assertSame(4500, $vente->montantPaye(ModePaiement::Especes));
    }

    /**
     * Adresses des formulaires des lignes du panier, dans l'ordre d'ajout.
     *
     * @return list<string>
     */
    private function formulairesDesLignes(): array
    {
        $crawler = $this->client->request('GET', '/caisse');

        return $crawler->filter('#panier form[action^="/caisse/ligne/"]')->each(static fn ($form) => (string) $form->attr('action'));
    }

    /**
     * @return array<string, int>
     */
    private function remisesFigees(Vente $vente): array
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($vente): array {
            $remises = [];
            foreach ($em->getRepository(LigneVente::class)->findBy(['vente' => $vente->getId()], ['id' => 'ASC']) as $ligne) {
                $remises[(string) $ligne->getProduit()->getNomCommercial()] = $ligne->getRemise();
            }

            return $remises;
        });
    }
}
