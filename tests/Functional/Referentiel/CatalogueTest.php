<?php

namespace App\Tests\Functional\Referentiel;

use App\Entity\Categorie;
use App\Entity\FormeGalenique;
use App\Entity\OrganismeAmo;
use App\Entity\ParametrePharmacie;
use App\Entity\Produit;
use App\Enum\PolitiqueSansOrdonnance;
use App\Service\ParametresPharmacie;
use App\Tenant\TenantContext;
use App\Tests\Factory\CategorieFactory;
use App\Tests\Factory\EtagereFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Catalogue, catégories, étagères et paramètres (RF-01 à RF-03, PH-01, PH-02).
 */
final class CatalogueTest extends AppWebTestCase
{
    private Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    public function testCreationRechercheEtArchivageDUnProduit(): void
    {
        $categorie = CategorieFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Antalgiques']);
        $etagere = EtagereFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'code' => 'E1-R3']);
        $comprime = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(FormeGalenique::class)->findOneBy(['nom' => 'Comprimé']));

        $this->connecter($this->officine->adjoint)->request('GET', '/produits/nouveau');
        $this->client->submitForm('Enregistrer', [
            'produit[nomCommercial]' => 'Doliprane',
            'produit[dci]' => 'Paracétamol',
            'produit[dosage]' => '500 mg',
            'produit[forme]' => (string) $comprime?->getId(),
            'produit[categorie]' => (string) $categorie->getId(),
            'produit[etagere]' => (string) $etagere->getId(),
            'produit[prixAchat]' => '1150',
            'produit[prixVente]' => '1500',
            'produit[seuilAlerte]' => '10',
            'produit[stockMax]' => '60',
            'produit[remboursableAmo]' => true,
        ]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Doliprane');
        self::assertSelectorTextContains('main', "1\u{00A0}500\u{00A0}FCFA");
        self::assertSelectorTextContains('main', "350\u{00A0}FCFA", 'Marge brute de référence.');

        foreach (['Doli', 'parac'] as $recherche) {
            $this->client->request('GET', '/produits?categorie=&q='.$recherche);
            self::assertSelectorTextContains('tbody', 'Doliprane', $recherche);
        }

        $crawler = $this->client->request('GET', '/produits?q=Doliprane');
        $this->client->click($crawler->selectLink('Doliprane')->link());
        $this->client->submitForm('Archiver');
        $this->client->request('GET', '/produits?q=Doliprane');
        self::assertSelectorNotExists('tbody a', 'Un produit archivé disparaît de la liste.');
        $this->client->request('GET', '/produits?q=Doliprane&archives=1');
        self::assertSelectorTextContains('tbody a', 'Doliprane');
    }

    public function testLeFormulaireProduitNaPlusDeCodeBarres(): void
    {
        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/produits/nouveau');
        self::assertCount(0, $crawler->filter('[name="produit[codeBarres]"]'));
        self::assertSelectorTextNotContains('main', 'Code-barres');
    }

    public function testValidationDesPrixEtDuStock(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/produits/nouveau');
        $this->client->submitForm('Enregistrer', [
            'produit[nomCommercial]' => 'Invalide',
            'produit[prixVente]' => '0',
            'produit[seuilAlerte]' => '50',
            'produit[stockMax]' => '10',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Le prix de vente doit être supérieur à zéro');
        self::assertSelectorTextContains('main', 'Le stock maximum doit être supérieur ou égal au seuil');
    }

    public function testCategoriesSurDeuxNiveauxSeulement(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/categories');
        $this->client->submitForm('Créer', ['categorie[nom]' => 'Médicaments']);
        $this->client->followRedirect();
        $medicaments = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Categorie::class)->findOneBy(['nom' => 'Médicaments']));
        self::assertInstanceOf(Categorie::class, $medicaments);

        $this->client->submitForm('Créer', ['categorie[nom]' => 'Antibiotiques', 'categorie[parent]' => (string) $medicaments->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Antibiotiques');

        // Une sous-catégorie n'est pas proposée comme parent : impossible de créer un 3e niveau.
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('#categorie_parent option[value]:not([value=""])'));

        // Doublon au même niveau refusé.
        $this->client->submitForm('Créer', ['categorie[nom]' => 'Médicaments']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Cette catégorie existe déjà');
    }

    public function testUneCategorieArchiveeNEstPlusProposee(): void
    {
        $categorie = CategorieFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Ancienne']);

        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/categories');
        $this->client->submit($crawler->filter(\sprintf('form[action="/categories/%d/archiver"]', $categorie->getId()))->form());

        $crawler = $this->client->request('GET', '/produits/nouveau');
        self::assertCount(0, $crawler->filter(\sprintf('#produit_categorie option[value="%d"]', $categorie->getId())));
    }

    public function testEtagereCodeUnique(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/etageres');
        $this->client->submitForm('Créer', ['etagere[code]' => 'f1-r2', 'etagere[libelle]' => 'Vaccins', 'etagere[zone]' => 'refrigerateur']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'F1-R2');
        self::assertSelectorTextContains('main', 'Réfrigérateur');

        $this->client->submitForm('Créer', ['etagere[code]' => 'F1-R2', 'etagere[libelle]' => 'Autre']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Une étagère porte déjà ce code');
    }

    public function testReglesDeGestion(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=regles');
        self::assertCheckboxChecked('parametres[politiqueSansOrdonnance]', 'Par défaut : blocage.');
        $this->client->submitForm('Enregistrer les règles', [
            'parametres[plafondRemise]' => '15',
            'parametres[delaiAlertePeremption]' => '120',
            'parametres[politiqueSansOrdonnance]' => 'confirmation',
            'parametres[mentionsTicket]' => 'Merci de votre visite.',
        ]);
        self::assertResponseRedirects('/parametres?onglet=regles');

        $parametres = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(ParametrePharmacie::class)->findOneBy(['pharmacie' => $this->officine->pharmacie->getId()]));
        self::assertSame(15, $parametres?->getPlafondRemise());
        self::assertSame(120, $parametres->getDelaiAlertePeremption());
        self::assertSame(PolitiqueSansOrdonnance::Confirmation, $parametres->getPolitiqueSansOrdonnance());
    }

    public function testTauxAmoHistoriseParDateDEffet(): void
    {
        $inps = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'INPS']));
        self::assertInstanceOf(OrganismeAmo::class, $inps);

        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=amo');
        self::assertSelectorTextContains('main', '70 %');

        foreach ([['80', 'yesterday'], ['90', '+10 days']] as [$taux, $date]) {
            $this->client->request('GET', '/parametres?onglet=amo');
            $this->client->submitForm('Ajouter ce taux', [
                'taux_amo[organisme]' => (string) $inps->getId(),
                'taux_amo[taux]' => $taux,
                'taux_amo[dateEffet]' => (new \DateTimeImmutable($date))->format('Y-m-d'),
            ]);
            self::assertResponseRedirects('/parametres?onglet=amo');
        }

        /** @var TenantContext $tenant */
        $tenant = self::getContainer()->get(TenantContext::class);
        $tenant->forcer($this->officine->pharmacie);
        /** @var ParametresPharmacie $service */
        $service = self::getContainer()->get(ParametresPharmacie::class);
        $this->viderGestionnaire();
        $inps = self::getContainer()->get(EntityManagerInterface::class)->find(OrganismeAmo::class, $inps->getId());
        self::assertInstanceOf(OrganismeAmo::class, $inps);

        self::assertSame(70, $service->tauxAmo($inps, new \DateTimeImmutable('-1 week')), 'Avant tout taux saisi : 70 %.');
        self::assertSame(80, $service->tauxAmo($inps), 'Aujourd\'hui : le taux entré en vigueur hier.');
        self::assertSame(90, $service->tauxAmo($inps, new \DateTimeImmutable('+1 month')), 'Le taux futur s\'appliquera à sa date.');
    }

    public function testLogoStockeHorsDuDossierPublicEtServiApresControle(): void
    {
        $image = sys_get_temp_dir().'/logo-test.png';
        imagepng(imagecreatetruecolor(40, 20), $image);
        $autre = $this->creerOfficine();

        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres');
        $this->client->submitForm('Enregistrer la fiche', ['fiche_pharmacie[logo]' => new UploadedFile($image, 'logo.png', 'image/png', test: true)]);
        self::assertResponseRedirects();

        $this->client->request('GET', '/parametres/logo');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');

        $projet = (string) self::getContainer()->getParameter('kernel.project_dir');
        self::assertStringStartsNotWith($projet.'/public', (string) self::getContainer()->getParameter('app.dossier_fichiers'));

        // Un utilisateur d'une autre pharmacie obtient son propre logo (ici : aucun), jamais celui-ci.
        $this->connecter($autre->vendeur)->request('GET', '/parametres/logo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnFichierNonImageEstRefuseCommeLogo(): void
    {
        $faux = sys_get_temp_dir().'/logo.png';
        file_put_contents($faux, '<?php echo "pas une image";');

        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres');
        $this->client->submitForm('Enregistrer la fiche', ['fiche_pharmacie[logo]' => new UploadedFile($faux, 'logo.png', 'image/png', test: true)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'PNG ou JPEG');
    }

    public function testFicheDeLaPharmacieModifiable(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres');
        $this->client->submitForm('Enregistrer la fiche', ['fiche_pharmacie[telephone]' => '66 77 88 99', 'fiche_pharmacie[email]' => 'Contact@Officine.ML']);
        self::assertResponseRedirects();

        $pharmacie = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->find(\App\Entity\Pharmacie::class, $this->officine->pharmacie->getId()));
        self::assertSame('+22366778899', $pharmacie?->getTelephone());
        self::assertSame('contact@officine.ml', $pharmacie->getEmail());
    }

    public function testDesignationDuProduit(): void
    {
        $produit = (new Produit())->setNomCommercial('Doliprane')->setDci('Paracétamol')->setDosage('500 mg')
            ->setForme((new FormeGalenique())->setNom('Comprimé'));

        self::assertSame('Doliprane — Paracétamol 500 mg, comprimé', $produit->getDesignation());
    }
}
