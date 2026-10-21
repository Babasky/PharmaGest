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
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

    public function testCopieDeLAccordAuPanierALaVenteEtDansLeBordereau(): void
    {
        $augmentin = ProduitFactory::createOne([
            'pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Augmentin', 'prixVente' => 6800, 'prixVenteAmo' => 6000,
            'remboursableAmo' => true, 'accordPrealableAmo' => true,
        ]);
        LotFactory::createOne(['produit' => $augmentin, 'quantiteInitiale' => 20]);

        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($augmentin, 1);
        $this->poster('/caisse/client', ['client' => $this->assure->getId()]);
        $this->client->request('POST', '/caisse/vente', [
            '_token' => $this->jeton(), 'type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ], ['ordonnance_copie' => self::photo()]);
        $ligne = $this->ligne($augmentin);

        // Au panier, l'accord se joint à côté de son n° : photo ou PDF, rien d'autre.
        $this->client->request('GET', '/caisse');
        self::assertSelectorExists('#panier .accord-prealable input[type=file][name=accord_copie]');
        self::assertSelectorExists('#ligne-'.$ligne->getId().'[enctype="multipart/form-data"]');
        $texte = (string) tempnam(sys_get_temp_dir(), 'acc');
        file_put_contents($texte, 'pas une image');
        $this->client->request('POST', '/caisse/ligne/'.$ligne->getId(), ['_token' => $this->jeton(), 'quantite' => '1'], ['accord_copie' => new UploadedFile($texte, 'accord.txt', 'text/plain', null, true)]);
        self::assertStringContainsString('La copie de l\'accord préalable doit être une photo (JPEG, PNG ou WebP) ou un PDF.', $this->erreur());

        $this->client->request('POST', '/caisse/ligne/'.$ligne->getId(), ['_token' => $this->jeton(), 'quantite' => '1', 'accord' => 'AP-2026-0500'], ['accord_copie' => self::pdf()]);
        self::assertResponseRedirects('/caisse');
        $this->client->request('GET', '/caisse');
        self::assertSelectorExists('#panier .copie-accord .bi-paperclip');
        self::assertSelectorTextContains('#panier', 'Copie de l\'accord jointe.');

        $this->encaisser(['especes' => ['remis' => '2600']]);
        self::assertResponseRedirects();
        $vente = (int) $this->derniereVente()->getId();
        $numero = (string) $this->derniereVente()->getNumero();

        // Sur la vente, l'accord s'ouvre et se remplace tant que le bordereau n'est pas transmis.
        $crawler = $this->client->request('GET', '/ventes/'.$vente);
        self::assertSelectorExists('.accord-ligne a.copie-accord .bi-file-earmark-pdf');
        $this->client->request('GET', '/ventes/'.$vente.'/accord/'.$ligne->getId());
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $jeton = (string) $crawler->filter('.accord-ligne form input[name=_token]')->attr('value');
        $this->client->request('POST', '/ventes/'.$vente.'/accord/'.$ligne->getId(), ['_token' => $jeton], ['copie' => self::photo()]);
        self::assertResponseRedirects('/ventes/'.$vente);
        $this->client->request('GET', '/ventes/'.$vente.'/accord/'.$ligne->getId());
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $this->client->request('GET', '/ventes/'.$vente.'/accord/999999');
        self::assertResponseStatusCodeSame(404);

        // Le bordereau l'annexe après l'ordonnance, et l'archive le fournit à l'organisme.
        $this->creerBordereau();
        $bordereau = $this->bordereau();
        $this->client->request('GET', '/amo/bordereaux/'.$bordereau->getId().'/pdf');
        self::assertSame(3, preg_match_all('#/Type\s*/Page[^s]#', (string) $this->client->getResponse()->getContent()), 'Le relevé, l\'ordonnance, puis l\'accord.');
        $this->client->request('GET', '/amo/bordereaux/'.$bordereau->getId().'/ordonnances');
        self::assertResponseIsSuccessful();
        $archive = (string) tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($archive, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        $noms = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $noms[] = (string) $zip->getNameIndex($i);
        }
        sort($noms);
        self::assertSame(['01-'.$numero.'-accord-1.jpg', '01-'.$numero.'.jpg'], $noms);
        $zip->close();
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
