<?php

namespace App\Tests\Functional\Finance;

use App\Entity\CategorieDepense;
use App\Entity\Produit;
use App\Reporting\Periode;
use App\Tests\Factory\ClientFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Functional\Amo\AmoTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Rapports par période (RA-01, RA-05, RA-06, RE-05, RA-02 à RA-04), exports Excel et PDF (RA-11),
 * tableau de bord et droits d'accès.
 */
final class RapportTest extends AmoTestCase
{
    private Produit $doliprane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doliprane = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Doliprane', 'dosage' => '500 mg', 'prixVente' => 500]);
        LotFactory::createOne(['produit' => $this->doliprane, 'quantiteInitiale' => 100]);
    }

    public function testRapportDesVentesEtSesExports(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($this->doliprane, 4);
        $this->encaisser(['orange_money' => ['montant' => '2000', 'reference' => 'OM-7']]);
        $this->vendreAmo(2);
        $aujourdhui = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $this->client->request('GET', '/rapports');
        self::assertResponseRedirects('/rapports/ventes');
        $this->client->request('GET', '/rapports/ventes', ['periode' => 'jour', 'date' => $aujourdhui]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('#onglets-rapports', 'Finances');
        // CA = 2 000 + 2 000 (vente AMO, part AMO comprise) ; 2 ventes ; panier moyen 2 000.
        $synthese = $this->ligne('#tableau-synthese', "Chiffre d'affaires");
        self::assertSame(["Chiffre d'affaires", "4\u{00A0}000\u{00A0}FCFA", "0\u{00A0}FCFA", '—'], $synthese);
        self::assertSame(['Nombre de ventes', '2', '0', '—'], $this->ligne('#tableau-synthese', 'Nombre de ventes'));
        self::assertSame(['Ordonnance AMO / assurance', '1', "2\u{00A0}000\u{00A0}FCFA", "50\u{00A0}%"], $this->ligne('#tableau-par-type', 'Ordonnance AMO'));
        self::assertSame(['Orange Money', '1', "2\u{00A0}000\u{00A0}FCFA", "50\u{00A0}%"], $this->ligne('#tableau-par-mode', 'Orange Money'));
        self::assertSame(['Espèces', '1', "600\u{00A0}FCFA", "15\u{00A0}%"], $this->ligne('#tableau-par-mode', 'Espèces'));
        self::assertSame(['Part AMO (créance sur les organismes)', '—', "1\u{00A0}400\u{00A0}FCFA", "35\u{00A0}%"], $this->ligne('#tableau-par-mode', 'Part AMO'));
        self::assertSame(['1', 'Doliprane 500 mg', '4', "2\u{00A0}000\u{00A0}FCFA"], $this->ligne('#tableau-top-quantite', 'Doliprane'));

        // La veille, rien : la période précédente du lendemain porte les ventes du jour.
        $demain = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $this->client->request('GET', '/rapports/ventes', ['periode' => 'jour', 'date' => $demain]);
        self::assertSame(["Chiffre d'affaires", "0\u{00A0}FCFA", "4\u{00A0}000\u{00A0}FCFA", "-100\u{00A0}%"], $this->ligne('#tableau-synthese', "Chiffre d'affaires"));

        // Exports Excel et PDF des mêmes chiffres.
        $this->client->request('GET', '/rapports/ventes/excel', ['periode' => 'jour', 'date' => $aujourdhui]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('rapport-ventes-'.$aujourdhui.'.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $valeurs = $this->excel();
        self::assertContains('Rapport des ventes — '.$this->libelleJour(), $valeurs);
        self::assertContains('Doliprane 500 mg', $valeurs);
        self::assertContains(4000, $valeurs);

        $this->client->request('GET', '/rapports/ventes/pdf', ['periode' => 'jour', 'date' => $aujourdhui]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    public function testRapportDesRemisesParVendeurEtParClient(): void
    {
        $client = ClientFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Aïssata Cissé', 'privilegie' => true]);
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($this->doliprane, 4);
        $this->poster('/caisse/client', ['client' => $client->getId()]);
        $this->poster('/caisse/vente', ['type' => 'sans_ordonnance', 'remise_valeur' => '10', 'remise_type' => 'pourcentage']);
        $this->encaisser();
        self::assertResponseRedirects();

        $this->connecter($this->officine->adjoint)->request('GET', '/rapports/remises', ['periode' => 'mois']);
        self::assertResponseIsSuccessful();
        $vendeur = $this->officine->vendeur->getNom();
        self::assertSame([$vendeur, '1', "2\u{00A0}000\u{00A0}FCFA", "200\u{00A0}FCFA", "10\u{00A0}%"], $this->ligne('#tableau-par-vendeur', $vendeur));
        self::assertSame(['Aïssata Cissé', '1', "2\u{00A0}000\u{00A0}FCFA", "200\u{00A0}FCFA", "10\u{00A0}%"], $this->ligne('#tableau-par-client', 'Aïssata Cissé'));
        self::assertSelectorTextContains('#tableau-detail', $this->derniereVente()->getNumero() ?? 'V-');
        $this->client->request('GET', '/rapports/remises/excel', ['periode' => 'mois']);
        self::assertContains('Aïssata Cissé', $this->excel());
    }

    public function testRapportFinancierReserveAuProprietaire(): void
    {
        $this->connecter($this->officine->vendeur)->request('GET', '/rapports/ventes');
        self::assertResponseStatusCodeSame(403);
        $this->connecter($this->officine->adjoint);
        foreach (['/rapports/finances', '/rapports/finances/excel', '/rapports/finances/pdf'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        // Une vente de 2 000 et un loyer de 1 500 dans le mois.
        $this->ouvrirCaisse($this->officine->proprietaire);
        $this->ajouter($this->doliprane, 4);
        $this->encaisser();
        $this->client->request('GET', '/depenses');
        $loyer = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(CategorieDepense::class)->findOneBy(['nom' => 'Loyer', 'pharmacie' => $this->officine->pharmacie->getId()]));
        self::assertInstanceOf(CategorieDepense::class, $loyer);
        $this->client->request('GET', '/depenses/nouvelle');
        $this->client->submitForm('Enregistrer', [
            'depense[date]' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'depense[categorie]' => (string) $loyer->getId(),
            'depense[libelle]' => 'Loyer',
            'depense[montant]' => '1500',
            'depense[mode]' => 'especes',
        ]);

        $this->client->request('GET', '/rapports/finances', ['periode' => 'mois']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#onglets-rapports', 'Finances');
        self::assertSame("2\u{00A0}000\u{00A0}FCFA", $this->ligne('#tableau-synthese', 'Recettes')[1]);
        self::assertSame("1\u{00A0}500\u{00A0}FCFA", $this->ligne('#tableau-synthese', 'Dépenses')[1]);
        self::assertSame("500\u{00A0}FCFA", $this->ligne('#tableau-synthese', 'Résultat')[1]);
        self::assertSame(['Loyer', '1', "1\u{00A0}500\u{00A0}FCFA", "100\u{00A0}%"], $this->ligne('#tableau-depenses-categorie', 'Loyer'));
        self::assertSame(['Espèces', "2\u{00A0}000\u{00A0}FCFA", "1\u{00A0}500\u{00A0}FCFA", "500\u{00A0}FCFA"], $this->ligne('#tableau-par-mode', 'Espèces'));
        self::assertSame(12, $this->client->getCrawler()->filter('#tableau-mois tbody tr')->count());

        $this->client->request('GET', '/rapports/finances/excel', ['periode' => 'mois']);
        self::assertResponseIsSuccessful();
        self::assertContains('Détail des dépenses', $this->excel());
        $this->client->request('GET', '/rapports/finances/pdf', ['periode' => 'mois']);
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());

        // Tableau de bord du propriétaire : dépenses et résultat du mois, graphiques RA-03 et RA-04.
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('#indicateur-depenses-mois', "1\u{00A0}500\u{00A0}FCFA");
        self::assertSelectorTextContains('#indicateur-resultat-mois', "500\u{00A0}FCFA");
        self::assertSelectorTextContains('#indicateur-ca-mois', "2\u{00A0}000\u{00A0}FCFA");
        self::assertSelectorExists('#graphique-mois canvas');
        self::assertSelectorExists('#graphique-categories canvas');
    }

    public function testTableauDeBordSelonLeRole(): void
    {
        $this->connecter($this->officine->vendeur)->request('GET', '/');
        self::assertSelectorExists('#indicateur-ventes-jour');
        foreach (['ca-jour', 'ca-mois', 'depenses-mois', 'encours-amo', 'valeur-stock'] as $absent) {
            self::assertSelectorNotExists('#indicateur-'.$absent);
        }

        $this->connecter($this->officine->adjoint)->request('GET', '/');
        foreach (['ca-jour', 'ca-mois', 'panier', 'encours-amo', 'valeur-stock'] as $present) {
            self::assertSelectorExists('#indicateur-'.$present);
        }
        self::assertSelectorNotExists('#indicateur-depenses-mois');
        self::assertSelectorNotExists('#graphique-mois');
    }

    /**
     * Cellules (texte) de la première ligne d'un tableau dont la première cellule commence par $debut.
     *
     * @return list<string>
     */
    private function ligne(string $tableau, string $debut): array
    {
        foreach ($this->client->getCrawler()->filter($tableau.' tbody tr') as $tr) {
            $cellules = array_map(static fn (\DOMNode $td) => trim($td->textContent), iterator_to_array($tr->childNodes));
            $cellules = array_values(array_filter($cellules, static fn (string $c, int $i) => 'td' === $tr->childNodes->item($i)?->nodeName, \ARRAY_FILTER_USE_BOTH));
            if (str_starts_with($cellules[0] ?? '', $debut) || str_starts_with($cellules[1] ?? '', $debut)) {
                return $cellules;
            }
        }
        self::fail(\sprintf('Aucune ligne « %s » dans %s.', $debut, $tableau));
    }

    /**
     * Valeurs non vides du classeur Excel renvoyé.
     *
     * @return list<mixed>
     */
    private function excel(): array
    {
        $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($chemin, (string) $this->client->getResponse()->getContent());
        $valeurs = [];
        foreach (IOFactory::load($chemin)->getActiveSheet()->toArray(null, true, false) as $ligne) {
            foreach ($ligne as $valeur) {
                if (null !== $valeur && '' !== $valeur) {
                    $valeurs[] = $valeur;
                }
            }
        }
        unlink($chemin);

        return $valeurs;
    }

    private function libelleJour(): string
    {
        return Periode::pour(Periode::JOUR, new \DateTimeImmutable('today'))->libelle();
    }
}
