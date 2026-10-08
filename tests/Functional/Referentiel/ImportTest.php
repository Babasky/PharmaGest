<?php

namespace App\Tests\Functional\Referentiel;

use App\Entity\Categorie;
use App\Entity\Client;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * RF-08 : import Excel / CSV avec rapport d'erreurs ligne par ligne.
 */
final class ImportTest extends AppWebTestCase
{
    private Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    public function testImportDeProduitsAvecErreursSignaleesLigneParLigne(): void
    {
        $fichier = $this->xlsx([
            ['Nom commercial *', 'DCI', 'Forme', 'Dosage', 'Code-barres', 'Catégorie', 'Étagère', 'Fournisseur', "Prix d'achat", 'Prix de vente *', 'TVA', 'Remboursable AMO'],
            ['Doliprane', 'Paracétamol', 'Comprimé', '500 mg', 3400930000011, 'Médicaments > Antalgiques', 'E1-R3', 'PPM', 1150, '1 500', 0, 'oui'],
            ['Amoxil', 'Amoxicilline', 'Gélule', '500 mg', '3400930000028', 'Médicaments > Antibiotiques', 'E1-R4', 'PPM', 2000, 2600, 0, 'oui'],
            ['', 'Sans nom', '', '', '', '', '', '', '', 100, '', ''],
            ['Mauvais prix', '', 'Comprimé', '', '', '', '', '', '', 'gratuit', '', ''],
            ['Forme inconnue', '', 'Pastille magique', '', '', '', '', '', '', 300, '', ''],
            ['Doublon', '', '', '', 3400930000011, '', '', '', '', 300, '', ''],
            ['Crème solaire', '', 'Crème', '50 ml', '', 'Parapharmacie', 'P1', 'Laborex', 3500, 5000, 18, 'non'],
        ]);

        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/imports/produits');
        self::assertSame('false', $crawler->filter('form[enctype="multipart/form-data"]')->attr('data-turbo'), 'Le rapport d\'analyse s\'affiche hors Turbo.');
        $this->client->submitForm('Vérifier le fichier', ['fichier' => $fichier]);
        self::assertResponseIsSuccessful();

        // Rapport : 3 lignes valides, 4 erreurs avec leur numéro de ligne Excel.
        self::assertSelectorTextContains('main', 'Importer les 3 lignes valides');
        $erreurs = $this->client->getCrawler()->filter('table tbody tr')->each(static fn ($tr) => $tr->text());
        self::assertCount(4, $erreurs);
        self::assertStringContainsString('4', $erreurs[0]);
        self::assertStringContainsString('Le nom commercial est obligatoire', $erreurs[0]);
        self::assertStringContainsString('« gratuit » n\'est pas un nombre', $erreurs[1]);
        self::assertStringContainsString('Forme inconnue : « Pastille magique »', $erreurs[2]);
        self::assertStringContainsString('apparaît déjà à la ligne 2', $erreurs[3]);
        self::assertSelectorTextContains('main', 'Catégorie « Médicaments › Antalgiques » créée.');

        // L'analyse n'a rien enregistré.
        self::assertSame(0, $this->compter(Produit::class));
        self::assertSame(0, $this->compter(Categorie::class));

        $this->client->submitForm('Importer les 3 lignes valides');
        self::assertResponseRedirects('/produits');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '3 créé(s), 0 mis à jour, 4 ligne(s) ignorée(s)');

        self::assertSame(3, $this->compter(Produit::class));
        self::assertSame(4, $this->compter(Categorie::class), 'Médicaments, Antalgiques, Antibiotiques, Parapharmacie.');
        self::assertSame(2, $this->compter(Fournisseur::class), 'PPM créé une seule fois, plus Laborex.');

        $doliprane = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Produit::class)->findOneBy(['codeBarres' => '3400930000011']));
        self::assertInstanceOf(Produit::class, $doliprane);
        self::assertSame(1500, $doliprane->getPrixVente(), '« 1 500 » est lu comme 1500 FCFA.');
        self::assertSame('Médicaments › Antalgiques', $doliprane->getCategorie()?->getNomComplet());
        self::assertTrue($doliprane->isRemboursableAmo());
        self::assertSame($this->officine->pharmacie->getId(), $doliprane->getPharmacie()?->getId());
    }

    public function testUnProduitExistantEstMisAJourParSonCodeBarres(): void
    {
        ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Ancien nom', 'codeBarres' => '555', 'prixVente' => 1000]);

        $this->connecter($this->officine->adjoint)->request('GET', '/imports/produits');
        $this->client->submitForm('Vérifier le fichier', ['fichier' => $this->xlsx([['nom_commercial', 'code_barres', 'prix_vente'], ['Nouveau nom', '555', 1250]])]);
        self::assertSelectorTextContains('main', 'Importer les 1 ligne valide');

        // L'analyse ne doit pas avoir modifié le produit.
        $nom = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Produit::class)->findOneBy(['codeBarres' => '555'])?->getNomCommercial());
        self::assertSame('Ancien nom', $nom);

        $this->client->submitForm('Importer les 1 ligne valide');
        $produit = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Produit::class)->findOneBy(['codeBarres' => '555']));
        self::assertSame('Nouveau nom', $produit?->getNomCommercial());
        self::assertSame(1250, $produit->getPrixVente());
        self::assertSame(1, $this->compter(Produit::class));
    }

    public function testPrixDeVenteAmoImporteOuConserve(): void
    {
        ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Coartem', 'codeBarres' => '777', 'prixVente' => 3800, 'prixVenteAmo' => 3500]);

        // Fichier sans la colonne : le prix AMO déjà saisi ne bouge pas.
        $this->connecter($this->officine->adjoint)->request('GET', '/imports/produits');
        $this->client->submitForm('Vérifier le fichier', ['fichier' => $this->xlsx([['nom_commercial', 'code_barres', 'prix_vente'], ['Coartem', '777', 3900]])]);
        $this->client->submitForm('Importer les 1 ligne valide');
        self::assertSame([3900, 3500], $this->prix('777'));

        $this->client->request('GET', '/imports/produits');
        $this->client->submitForm('Vérifier le fichier', ['fichier' => $this->xlsx([
            ['nom_commercial', 'code_barres', 'prix_vente', 'prix_vente_amo'],
            ['Coartem', '777', 3900, '3 600'],
            ['Doliprane', '888', 1500, ''],
        ])]);
        $this->client->submitForm('Importer les 2 lignes valides');
        self::assertSame([3900, 3600], $this->prix('777'));
        self::assertSame([1500, null], $this->prix('888'), 'Cellule vide : pas de prix AMO, le prix de la pharmacie sert de base.');
    }

    /**
     * @return array{?int, ?int} prix de vente et prix de vente AMO
     */
    private function prix(string $codeBarres): array
    {
        $produit = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Produit::class)->findOneBy(['codeBarres' => $codeBarres]));
        self::assertInstanceOf(Produit::class, $produit);

        return [$produit->getPrixVente(), $produit->getPrixVenteAmo()];
    }

    public function testImportDeClientsEnCsvPointVirguleEtAccents(): void
    {
        $csv = sys_get_temp_dir().'/clients-'.uniqid().'.csv';
        file_put_contents($csv, mb_convert_encoding("Nom;Téléphone;Privilégié;Organisme AMO;N° d'assuré\nMariam Diallo;76 12 34 56;oui;INPS;INPS-1\nSékou Traoré;;non;;\nAssuré incomplet;66 11 22 33;non;CMSS;\n", 'Windows-1252', 'UTF-8'));

        $this->connecter($this->officine->proprietaire)->request('GET', '/imports/clients');
        $this->client->submitForm('Vérifier le fichier', ['fichier' => new UploadedFile($csv, 'clients.csv', 'text/csv', test: true)]);
        self::assertSelectorTextContains('main', 'Importer les 2 lignes valides');
        self::assertSelectorTextContains('main', 'renseignez à la fois le numéro d\'assuré et l\'organisme');

        $this->client->submitForm('Importer les 2 lignes valides');
        $mariam = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Client::class)->findOneBy(['telephone' => '+22376123456']));
        self::assertSame('Mariam Diallo', $mariam?->getNom());
        self::assertTrue($mariam->isPrivilegie());
        self::assertTrue($mariam->isAssureAmo());
        self::assertNotNull($this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Client::class)->findOneBy(['nom' => 'Sékou Traoré'])));
    }

    public function testColonneObligatoireAbsente(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/imports/fournisseurs');
        $this->client->submitForm('Vérifier le fichier', ['fichier' => $this->xlsx([['Contact', 'Téléphone'], ['Moussa', '76000000']])]);

        self::assertSelectorTextContains('.alert-danger', 'Colonne obligatoire absente : « Nom »');
        self::assertSelectorNotExists('button:contains("Importer les")');
    }

    public function testFichierIllisibleOuMauvaisFormat(): void
    {
        $faux = sys_get_temp_dir().'/image.png';
        imagepng(imagecreatetruecolor(5, 5), $faux);

        $this->connecter($this->officine->adjoint)->request('GET', '/imports/produits');
        $this->client->submitForm('Vérifier le fichier', ['fichier' => new UploadedFile($faux, 'image.png', 'image/png', test: true)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Formats acceptés');
    }

    public function testLeModeleExcelContientLesColonnesEtUneAide(): void
    {
        $classeur = $this->modele('produits');

        self::assertSame('Nom commercial *', $classeur->getSheet(0)->getCell('A1')->getValue());
        self::assertContains('Prix de vente AMO', $classeur->getSheet(0)->rangeToArray('A1:Q1')[0]);
        self::assertSame('Aide', $classeur->getSheet(1)->getTitle());
    }

    /**
     * Les modèles sont fournis avec des exemples réalistes (100 produits, 10 catégories, 10 fournisseurs, 30 clients)
     * qui s'importent tels quels, sans aucune erreur.
     *
     * @return iterable<string, array{string, int, class-string, int}>
     */
    public static function modeles(): iterable
    {
        yield 'fournisseurs' => ['fournisseurs', 10, Fournisseur::class, 10];
        yield 'produits' => ['produits', 100, Produit::class, 100];
        yield 'clients' => ['clients', 30, Client::class, 30];
    }

    /**
     * @param class-string $classe
     */
    #[DataProvider('modeles')]
    public function testLesExemplesDuModeleSImportentSansErreur(string $type, int $lignes, string $classe, int $attendus): void
    {
        $classeur = $this->modele($type);
        self::assertSame($lignes + 1, $classeur->getSheet(0)->getHighestDataRow(), 'En-tête + lignes d\'exemple.');
        $chemin = sys_get_temp_dir().'/modele-'.uniqid().'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        $this->client->request('GET', '/imports/'.$type);
        $this->client->submitForm('Vérifier le fichier', ['fichier' => new UploadedFile($chemin, 'modele.xlsx', test: true)]);
        self::assertSelectorNotExists('table tbody tr', 'Aucune ligne en erreur.');
        $this->client->submitForm(\sprintf('Importer les %d lignes valides', $lignes));

        self::assertSame($attendus, $this->compter($classe));
        if ('produits' === $type) {
            // 9 sous-catégories de « Médicaments » + « Parapharmacie » : 10 catégories de rangement, et les 10 fournisseurs.
            $feuilles = $this->sansFiltre(static fn (EntityManagerInterface $em): int => (int) $em->createQuery('SELECT COUNT(DISTINCT c.id) FROM '.Produit::class.' p JOIN p.categorie c')->getSingleScalarResult());
            self::assertSame(10, $feuilles);
            self::assertSame(10, $this->compter(Fournisseur::class));
            self::assertSame([1500, 1350], $this->prix('6190000000002'), 'Prix de vente AMO repris du modèle.');
        }
    }

    private function modele(string $type): Spreadsheet
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/imports/'.$type.'/modele');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $chemin = sys_get_temp_dir().'/modele-'.uniqid().'.xlsx';
        file_put_contents($chemin, $this->client->getInternalResponse()->getContent());

        return IOFactory::load($chemin);
    }

    public function testLeFichierDeposeNeSertQuALaPharmacieQuiLADepose(): void
    {
        $autre = $this->creerOfficine();
        $this->connecter($this->officine->adjoint)->request('GET', '/imports/fournisseurs');
        $this->client->submitForm('Vérifier le fichier', ['fichier' => $this->xlsx([['Nom'], ['Grossiste confidentiel']])]);
        $fichier = $this->client->getCrawler()->filter('input[name="fichier"]')->attr('value');

        // L'adjoint d'une autre pharmacie tente de confirmer l'import avec le nom du fichier.
        $crawler = $this->connecter($autre->adjoint)->request('GET', '/imports/fournisseurs');
        $jeton = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/imports/fournisseurs/confirmer', ['_token' => $jeton, 'fichier' => $fichier]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'n\'est plus disponible');
        self::assertSame(0, $this->compter(Fournisseur::class));
    }

    /**
     * @param list<list<mixed>> $lignes
     */
    private function xlsx(array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet();
        $classeur->getActiveSheet()->fromArray($lignes, null, 'A1', true);
        $chemin = sys_get_temp_dir().'/import-'.uniqid().'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', test: true);
    }

    /**
     * @param class-string $classe
     */
    private function compter(string $classe): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository($classe)->count([]));
    }
}
