<?php

namespace App\Tests\Functional\Amo;

use App\Entity\BordereauAmo;
use App\Entity\CreanceAmo;
use App\Entity\JournalAudit;
use App\Entity\ReglementAmo;
use App\Enum\StatutBordereau;
use App\Enum\StatutCreance;
use App\Enum\StatutVente;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Créances (AM-05), bordereaux (AM-06, RG-11), exports (AM-07), règlements et rejets (AM-08) ; recette R-11.
 */
final class BordereauTest extends AmoTestCase
{
    public function testUneVenteAmoCreeUneCreanceEnAttenteEtSonAnnulationLAnnule(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $amo = $this->vendreAmo(2);
        $this->ajouter($this->remboursable);
        $this->encaisser(['especes' => ['remis' => '1000']]);
        $sansOrdonnance = (int) $this->derniereVente()->getId();

        $creance = $this->creance($amo);
        self::assertSame([1400, StatutCreance::EnAttente, 'INPS'], [$creance->getMontant(), $creance->getStatut(), $creance->getOrganisme()->getCode()], 'AM-05 : part AMO de 2 × 1 000 à 70 %.');
        self::assertNull($this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(CreanceAmo::class)->findOneBy(['vente' => $sansOrdonnance])));

        $this->client->request('GET', '/ventes/'.$amo);
        self::assertSelectorTextContains('#creance-amo', 'En attente');

        $this->client->submitForm('Annuler la vente', ['motif' => 'Erreur d\'assuré']);
        self::assertSame(StatutCreance::Annulee, $this->creance($amo)->getStatut());
        $this->client->request('GET', '/amo');
        self::assertSelectorTextContains('#encours-total', "0\u{00A0}FCFA");
    }

    public function testR11BordereauDeDixCreancesNeufRegleesEtUnRejet(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $ventes = [];
        for ($i = 0; $i < 10; ++$i) {
            $ventes[] = $this->vendreAmo();
        }
        $this->creerBordereau();
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '10 créance(s)');
        self::assertSelectorTextContains('#statut', 'Brouillon');
        self::assertSelectorTextContains('#montant', "7\u{00A0}000\u{00A0}FCFA");
        self::assertSelectorTextContains('#alerte-copies', '10 ordonnances sans copie');

        $this->cliquer('Transmettre');
        $annee = date('Y');
        self::assertSelectorTextContains('.alert-success', "Bordereau BRD-$annee-000001 transmis");
        $bordereau = $this->bordereau();
        self::assertSame([StatutBordereau::Transmis, 7000], [$bordereau->getStatut(), $bordereau->getMontant()]);
        self::assertSame(StatutCreance::Transmise, $this->creance($ventes[0])->getStatut());

        // 9 créances réglées, 1 rejetée.
        $rejetee = $this->creance($ventes[9]);
        $crawler = $this->client->request('GET', '/amo/bordereaux/'.$bordereau->getId());
        $formulaire = $crawler->filter('#reglement')->form();
        $formulaire['montant'] = '6300';
        $formulaire['reference'] = 'VIR-INPS-0045';
        $formulaire['regle['.$rejetee->getId().']'] = '0';
        $formulaire['rejet['.$rejetee->getId().']'] = 'Assuré non éligible à la date des soins';
        $this->client->submit($formulaire);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Règlement de 6');
        self::assertSelectorTextContains('#statut', 'Payé partiellement');
        self::assertSelectorTextContains('#regle', "6\u{00A0}300\u{00A0}FCFA");
        self::assertSelectorTextContains('#rejete', "700\u{00A0}FCFA");
        self::assertSelectorTextContains('#reste', "0\u{00A0}FCFA");
        self::assertSelectorNotExists('#reglement', 'Le bordereau est soldé.');

        $bordereau = $this->bordereau($bordereau->getId());
        self::assertSame(StatutBordereau::PayePartiellement, $bordereau->getStatut());
        self::assertSame(StatutCreance::Rejetee, $this->creance($ventes[9])->getStatut());
        self::assertSame('Assuré non éligible à la date des soins', $this->creance($ventes[9])->getMotifRejet());
        self::assertSame(StatutCreance::Payee, $this->creance($ventes[0])->getStatut());
        $this->sansFiltre(static function (EntityManagerInterface $em): void {
            $reglement = $em->getRepository(ReglementAmo::class)->findOneBy([]);
            self::assertSame([6300, 'VIR-INPS-0045', 9], [$reglement?->getMontant(), $reglement?->getReference(), $reglement?->getAffectations()->count()]);
            self::assertNotNull($em->getRepository(JournalAudit::class)->findOneBy(['action' => 'amo.reglement']));
            self::assertNotNull($em->getRepository(JournalAudit::class)->findOneBy(['action' => 'amo.rejet']));
            self::assertNotNull($em->getRepository(JournalAudit::class)->findOneBy(['action' => 'amo.bordereau_transmis']));
        });

        // Suivi (AM-10) : plus d'encours, 10 % de rejet.
        $this->client->request('GET', '/amo');
        self::assertSelectorTextContains('#encours-total', "0\u{00A0}FCFA");
        self::assertSelectorTextContains('#taux-rejet-total', '10,0 %');
        self::assertSelectorTextContains('#suivi tr[data-organisme="INPS"]', '10,0 %');
    }

    public function testLeBrouillonSeComposeEtUnBordereauTransmisNEstPlusModifiable(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $premiere = $this->vendreAmo();
        $seconde = $this->vendreAmo(3);
        $cmss = $this->vendreAmo(1, $this->assureCmss);

        $this->creerBordereau();
        $this->client->followRedirect();
        $bordereau = $this->bordereau();
        self::assertCount(2, $bordereau->getCreances(), 'Seules les créances INPS de la période.');
        self::assertSame(StatutCreance::EnAttente, $this->creance($cmss)->getStatut());

        // Une créance n'appartient qu'à un seul bordereau (RG-11).
        $this->creerBordereau();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Aucune créance INPS en attente');

        // Retirer, puis la reprendre avec « Ajouter les nouvelles créances ».
        $crawler = $this->client->request('GET', '/amo/bordereaux/'.$bordereau->getId());
        $this->client->submit($crawler->filter('tr[data-vente] form')->first()->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'retirée');
        self::assertCount(1, $this->bordereau($bordereau->getId())->getCreances());
        self::assertNull($this->creance($premiere)->getBordereau());
        $this->cliquer('Ajouter les nouvelles créances de la période');
        self::assertSelectorTextContains('.alert-success', '1 créance(s) ajoutée(s)');

        $this->cliquer('Transmettre');
        $transmis = $this->bordereau($bordereau->getId());
        self::assertSame(2800, $transmis->getMontant());
        self::assertSelectorNotExists('form[action$="/supprimer"]');

        // Plus rien ne bouge : ni retrait, ni suppression, ni annulation de la vente.
        $creance = $this->creance($seconde);
        $this->client->request('POST', '/amo/bordereaux/'.$bordereau->getId().'/retirer/'.$creance->getId(), ['_token' => $this->jetonBordereau($bordereau->getId())]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'n\'est plus modifiable');
        $this->client->request('POST', '/amo/bordereaux/'.$bordereau->getId().'/supprimer', ['_token' => $this->jetonBordereau($bordereau->getId())]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'n\'est plus modifiable');
        self::assertCount(2, $this->bordereau($bordereau->getId())->getCreances());

        $this->client->request('GET', '/ventes/'.$seconde);
        self::assertSelectorNotExists('#copie-ordonnance form');
        $this->client->submitForm('Annuler la vente', ['motif' => 'Test']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'bordereau transmis');
        self::assertSame(StatutVente::Validee, $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(\App\Entity\Vente::class, $seconde)?->getStatut()));
        self::assertSame(StatutCreance::Transmise, $this->creance($seconde)->getStatut());
    }

    public function testSuppressionDuBrouillonEtControlesDuReglement(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $vente = $this->vendreAmo(2);
        $this->creerBordereau();
        $this->client->followRedirect();
        $id = $this->bordereau()->getId();

        $this->cliquer('Supprimer le brouillon');
        self::assertSelectorTextContains('.alert-success', 'Brouillon supprimé');
        self::assertNull($this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(BordereauAmo::class, $id)));
        self::assertTrue($this->creance($vente)->estDisponible());

        $this->creerBordereau();
        $this->client->followRedirect();
        $this->cliquer('Transmettre');
        $bordereau = $this->bordereau();
        self::assertSame('BRD-'.date('Y').'-000001', $bordereau->getNumero(), 'Le brouillon supprimé ne laisse pas de trou (RG-02).');
        $creance = $this->creance($vente);

        $crawler = $this->client->request('GET', '/amo/bordereaux/'.$bordereau->getId());
        $formulaire = $crawler->filter('#reglement')->form();
        $formulaire['montant'] = '1000';
        $this->client->submit($formulaire);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'doit être égal au total affecté');

        $formulaire['regle['.$creance->getId().']'] = '2000';
        $formulaire['montant'] = '2000';
        $this->client->submit($formulaire);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'compris entre 0 et');

        // Paiement partiel, puis rejet du reste du bordereau.
        $formulaire['regle['.$creance->getId().']'] = '1000';
        $formulaire['montant'] = '1000';
        $this->client->submit($formulaire);
        $this->client->followRedirect();
        self::assertSame([StatutCreance::PayeePartiellement, 400], [$this->creance($vente)->getStatut(), $this->creance($vente)->getReste()]);
        self::assertSelectorTextContains('#statut', 'Payé partiellement');

        $this->client->submitForm('Rejeter', ['motif' => 'Plafond annuel atteint']);
        $this->client->followRedirect();
        $creance = $this->creance($vente);
        self::assertSame([StatutCreance::Rejetee, 1000, 400], [$creance->getStatut(), $creance->getMontantRegle(), $creance->getMontantRejete()]);
        self::assertSame(StatutBordereau::PayePartiellement, $this->bordereau($bordereau->getId())->getStatut());
    }

    public function testBordereauEntierementRejete(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $this->vendreAmo();
        $this->creerBordereau();
        $this->client->followRedirect();
        $this->cliquer('Transmettre');
        $this->client->submitForm('Rejeter', ['motif' => 'Bordereau hors délai']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#statut', 'Rejeté');
        self::assertSame(StatutBordereau::Rejete, $this->bordereau()->getStatut());
    }

    public function testExportsExcelEtPdfAvecLesOrdonnancesEnAnnexe(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $avecCopie = $this->vendreAmo(1, null, ['ordonnance_copie' => self::photo()]);
        $this->vendreAmo(2);
        $this->creerBordereau();
        $this->client->followRedirect();
        self::assertSelectorTextContains('#alerte-copies', '1 ordonnance sans copie');
        $bordereau = $this->bordereau();

        ob_start();
        $this->client->request('GET', '/amo/bordereaux/'.$bordereau->getId().'/excel');
        $contenu = (string) ob_get_clean();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $fichier = tempnam(sys_get_temp_dir(), 'brd');
        file_put_contents($fichier, '' !== $contenu ? $contenu : $this->client->getInternalResponse()->getContent());
        $feuille = IOFactory::load($fichier)->getActiveSheet();
        self::assertStringContainsString('INPS', (string) $feuille->getCell('A1')->getValue());
        self::assertSame('INPS-0045871', $feuille->getCell('E6')->getValue(), 'Le n° d\'assuré reste du texte.');
        self::assertSame(700, $feuille->getCell('M6')->getValue());
        self::assertSame(2100, $feuille->getCell('M8')->getValue(), 'Ligne de total.');

        $this->client->request('GET', '/amo/bordereaux/'.$bordereau->getId().'/pdf');
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $pdf = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'Le relevé, puis une page d\'annexe pour la seule copie jointe.');

        // La copie est servie à l'équipe de la pharmacie, et réduite (1600 px au plus).
        $this->client->request('GET', '/ventes/'.$avecCopie.'/ordonnance');
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $reponse = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $reponse);
        $taille = getimagesize($reponse->getFile()->getPathname());
        self::assertIsArray($taille);
        self::assertSame([1600, 1200], [$taille[0], $taille[1]]);
    }

    private function jetonBordereau(?int $id): string
    {
        $crawler = $this->client->request('GET', '/amo/bordereaux/'.$id);

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }
}
