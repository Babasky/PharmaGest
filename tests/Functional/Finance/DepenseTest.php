<?php

namespace App\Tests\Functional\Finance;

use App\Entity\CategorieDepense;
use App\Entity\Depense;
use App\Entity\JournalAudit;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * Dépenses (FI-01), catégories de dépenses (FI-02) et droits : réservé au propriétaire.
 */
final class DepenseTest extends AppWebTestCase
{
    private Officine $officine;
    private Officine $autre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
        $this->autre = $this->creerOfficine();
    }

    public function testSeulLeProprietaireAccedeAuxDepenses(): void
    {
        foreach ([$this->officine->adjoint, $this->officine->vendeur] as $utilisateur) {
            $this->connecter($utilisateur)->request('GET', '/depenses');
            self::assertResponseStatusCodeSame(403);
            $this->client->request('GET', '/depenses/nouvelle');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testLesCategoriesProposeesSontCopieesPuisAdaptables(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/depenses');
        self::assertResponseIsSuccessful();
        $categories = $this->client->getCrawler()->filter('#categories li')->each(static fn ($li) => trim($li->filter('span')->text()));
        self::assertSame(['Achats de marchandises', 'Divers', 'Eau', 'Électricité', 'Impôts et taxes', 'Loyer', 'Salaires', 'Transport'], $categories);

        $this->client->submitForm('Ajouter', ['nom' => 'Gardiennage']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Catégorie « Gardiennage » ajoutée');
        $this->client->submitForm('Ajouter', ['nom' => 'loyer']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'La catégorie « Loyer » existe déjà');

        // Archivée, une catégorie n'est plus proposée à la saisie.
        $transport = $this->categorie('Transport');
        $this->client->request('GET', '/depenses');
        $this->client->submit($this->client->getCrawler()->filter('#categories form[action="/depenses/categories/'.$transport->getId().'/archiver"]')->form());
        $this->client->request('GET', '/depenses/nouvelle');
        $choix = $this->client->getCrawler()->filter('#depense_categorie option')->each(static fn ($o) => $o->text());
        self::assertNotContains('Transport', $choix);
        self::assertContains('Gardiennage', $choix);

        // Une autre pharmacie garde sa propre liste.
        $this->connecter($this->autre->proprietaire)->request('GET', '/depenses');
        $categories = $this->client->getCrawler()->filter('#categories li')->each(static fn ($li) => trim($li->filter('span')->text()));
        self::assertNotContains('Gardiennage', $categories);
        self::assertContains('Transport', $categories);
    }

    public function testSaisieAvecJustificatifNumerotationEtIsolation(): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $this->connecter($this->officine->proprietaire)->request('GET', '/depenses');
        $loyer = $this->categorie('Loyer');

        $crawler = $this->client->request('GET', '/depenses/nouvelle');
        $formulaire = $crawler->selectButton('Enregistrer')->form([
            'depense[date]' => $aujourdhui->modify('+1 day')->format('Y-m-d'),
            'depense[categorie]' => (string) $loyer->getId(),
            'depense[libelle]' => 'Loyer du mois',
            'depense[montant]' => '150000',
            'depense[mode]' => 'virement',
            'depense[beneficiaire]' => 'SCI Badalabougou',
        ]);
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'La date ne peut pas être dans le futur');

        $formulaire['depense[date]'] = $aujourdhui->format('Y-m-d');
        $formulaire['depense[montant]'] = '0';
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Le montant doit être supérieur à zéro');

        $formulaire['depense[montant]'] = '150000';
        $justificatif = $formulaire['depense[justificatif]'];
        self::assertInstanceOf(FileFormField::class, $justificatif);
        $justificatif->upload(self::pdf());
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $annee = $aujourdhui->format('Y');
        self::assertSelectorTextContains('.alert-success', "Dépense DEP-$annee-000001 enregistrée");
        self::assertSelectorTextContains('#montant', "150\u{00A0}000\u{00A0}FCFA");
        self::assertSelectorTextContains('#fiche', 'SCI Badalabougou');
        self::assertSelectorTextContains('#fiche', 'Virement bancaire');

        $depense = $this->derniereDepense();
        self::assertSame(["DEP-$annee-000001", 150000, 'Loyer'], [$depense->getNumero(), $depense->getMontant(), $depense->getCategorie()->getNom()]);
        $this->client->request('GET', '/depenses/'.$depense->getId().'/justificatif');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

        $this->client->request('GET', '/depenses');
        self::assertSelectorTextContains('#depenses', "DEP-$annee-000001");
        self::assertSelectorTextContains('#total-depenses', "150\u{00A0}000\u{00A0}FCFA");
        self::assertSelectorTextContains('#par-categorie', 'Loyer');

        // Une autre pharmacie ne voit ni la dépense ni son justificatif, et sa numérotation est la sienne.
        $this->connecter($this->autre->proprietaire)->request('GET', '/depenses/'.$depense->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/depenses/'.$depense->getId().'/justificatif');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/depenses');
        self::assertSelectorTextNotContains('#depenses', 'DEP-');
    }

    public function testModificationEtAnnulationJournalisees(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/depenses');
        $eau = $this->categorie('Eau');
        $this->client->request('GET', '/depenses/nouvelle');
        $this->client->submitForm('Enregistrer', [
            'depense[date]' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'depense[categorie]' => (string) $eau->getId(),
            'depense[libelle]' => 'Facture SOMAGEP',
            'depense[montant]' => '18000',
            'depense[mode]' => 'especes',
        ]);
        $depense = $this->derniereDepense();

        $this->client->request('GET', '/depenses/'.$depense->getId().'/modifier');
        $this->client->submitForm('Enregistrer', ['depense[montant]' => '21500']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#montant', "21\u{00A0}500\u{00A0}FCFA");

        $this->client->submitForm('Annuler la dépense', ['motif' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Le motif de l\'annulation est obligatoire');
        $this->client->submitForm('Annuler la dépense', ['motif' => 'Saisie en double']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#annulee', 'Saisie en double');
        self::assertSelectorNotExists('#annulation');

        $this->client->request('GET', '/depenses');
        self::assertSelectorTextContains('#depenses', 'Annulée');
        self::assertSelectorTextContains('#total-depenses', "0\u{00A0}FCFA");
        $this->client->request('GET', '/depenses/'.$depense->getId().'/modifier');
        self::assertResponseRedirects('/depenses/'.$depense->getId());

        $this->sansFiltre(static function (EntityManagerInterface $em): void {
            $modification = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'depense.modifiee']);
            self::assertSame([18000, 21500], [$modification?->getAvant()['montant'] ?? null, $modification?->getApres()['montant'] ?? null]);
            $annulation = $em->getRepository(JournalAudit::class)->findOneBy(['action' => 'depense.annulee']);
            self::assertSame('Saisie en double', $annulation?->getApres()['motif'] ?? null);
        });
    }

    private function categorie(string $nom): CategorieDepense
    {
        $pharmacie = $this->officine->pharmacie->getId();

        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($nom, $pharmacie): CategorieDepense {
            $categorie = $em->getRepository(CategorieDepense::class)->findOneBy(['nom' => $nom, 'pharmacie' => $pharmacie]);
            self::assertInstanceOf(CategorieDepense::class, $categorie);

            return $categorie;
        });
    }

    private function derniereDepense(): Depense
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em): Depense {
            $depense = $em->getRepository(Depense::class)->findOneBy([], ['id' => 'DESC']);
            self::assertInstanceOf(Depense::class, $depense);
            $depense->getCategorie()->getNom();

            return $depense;
        });
    }

    private static function pdf(): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'just').'.pdf';
        file_put_contents($chemin, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return $chemin;
    }
}
