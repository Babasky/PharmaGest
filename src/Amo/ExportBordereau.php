<?php

namespace App\Amo;

use App\Entity\BordereauAmo;
use App\Entity\CreanceAmo;
use App\Entity\Pharmacie;
use App\Pdf\GenerateurPdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports du bordereau AMO (AM-07) : classeur Excel, et PDF unique avec les copies des ordonnances en annexe.
 */
class ExportBordereau
{
    private const FORMAT_FCFA = '#,##0';

    public function __construct(
        private readonly GenerateurPdf $generateur,
        private readonly CopieOrdonnance $copies,
    ) {
    }

    public function excel(BordereauAmo $bordereau): Spreadsheet
    {
        $pharmacie = $this->pharmacie($bordereau);
        $classeur = new Spreadsheet();
        $classeur->getProperties()->setCreator('PharmaGest')->setTitle('Bordereau AMO '.$bordereau->getLibelle());
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Bordereau');

        $feuille->setCellValue('A1', \sprintf('Bordereau AMO %s — %s', $bordereau->getLibelle(), $bordereau->getOrganisme()->getNom()));
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $feuille->setCellValue('A2', \sprintf('%s · autorisation n° %s', $pharmacie->getNom(), $pharmacie->getNumeroAutorisation()));
        $feuille->setCellValue('A3', \sprintf('Ventes du %s au %s · %s', $bordereau->getDebut()->format('d/m/Y'), $bordereau->getFin()->format('d/m/Y'), $bordereau->getStatut()->libelle()));

        $entetes = ['N°', 'Date', 'N° vente', 'Assuré', 'N° assuré', 'N° ordonnance', 'Date ordonnance', 'Prescripteur', 'Structure', 'Total vente', 'Base remboursable', 'Taux (%)', 'Part AMO'];
        $transmis = $bordereau->estTransmis();
        if ($transmis) {
            array_push($entetes, 'Réglé', 'Statut', 'Motif du rejet');
        }
        $ligne = 5;
        foreach ($entetes as $i => $entete) {
            $feuille->setCellValue([$i + 1, $ligne], $entete);
        }
        $derniere = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(\count($entetes));
        $feuille->getStyle("A{$ligne}:{$derniere}{$ligne}")->getFont()->setBold(true);
        $feuille->getStyle("A{$ligne}:{$derniere}{$ligne}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9F3EE');

        foreach ($bordereau->getCreances() as $n => $creance) {
            ++$ligne;
            $vente = $creance->getVente();
            $ordonnance = $vente->getOrdonnance();
            $valeurs = [
                $n + 1,
                $creance->getDateVente()->format('d/m/Y'),
                $vente->getNumero(),
                $vente->getClient()?->getNom(),
                $vente->getNumeroAssure(),
                $ordonnance?->getNumero(),
                $ordonnance?->getDate()?->format('d/m/Y'),
                $ordonnance?->getPrescripteur(),
                $ordonnance?->getStructure(),
                $vente->getTotalBrut(),
                $vente->getBaseAmo(),
                $vente->getTauxAmo(),
                $creance->getMontant(),
            ];
            if ($transmis) {
                array_push($valeurs, $creance->getMontantRegle(), $creance->getStatut()->libelle(), $creance->getMotifRejet());
            }
            foreach ($valeurs as $i => $valeur) {
                if (\is_string($valeur)) {
                    // Les numéros (assuré, ordonnance) restent du texte : pas de zéro initial perdu.
                    $feuille->setCellValueExplicit([$i + 1, $ligne], $valeur, DataType::TYPE_STRING);
                } else {
                    $feuille->setCellValue([$i + 1, $ligne], $valeur);
                }
            }
        }

        ++$ligne;
        $feuille->setCellValue([1, $ligne], 'Total');
        $feuille->setCellValue([13, $ligne], $bordereau->getMontant());
        if ($transmis) {
            $feuille->setCellValue([14, $ligne], $bordereau->getMontantRegle());
        }
        $feuille->getStyle("A{$ligne}:{$derniere}{$ligne}")->getFont()->setBold(true);
        $feuille->getStyle("J6:K{$ligne}")->getNumberFormat()->setFormatCode(self::FORMAT_FCFA);
        $feuille->getStyle("M6:N{$ligne}")->getNumberFormat()->setFormatCode(self::FORMAT_FCFA);
        foreach (range(1, \count($entetes)) as $colonne) {
            $feuille->getColumnDimensionByColumn($colonne)->setAutoSize(true);
        }
        $feuille->freezePane('A6');

        return $classeur;
    }

    public function reponseExcel(BordereauAmo $bordereau): Response
    {
        $classeur = $this->excel($bordereau);
        $reponse = new StreamedResponse(static function () use ($classeur): void {
            (new Xlsx($classeur))->save('php://output');
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->nomFichier($bordereau).'.xlsx'));
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }

    /**
     * PDF unique : le relevé, puis une page par copie d'ordonnance (AM-07).
     */
    public function pdf(BordereauAmo $bordereau): string
    {
        $pharmacie = $this->pharmacie($bordereau);
        $annexes = [];
        $sansCopie = [];
        foreach ($bordereau->getCreances() as $n => $creance) {
            $ordonnance = $creance->getVente()->getOrdonnance();
            $image = null === $ordonnance ? null : $this->copies->dataUri($pharmacie, $ordonnance);
            if (null === $image) {
                $sansCopie[] = $creance;
            } else {
                $annexes[] = ['numero' => $n + 1, 'creance' => $creance, 'image' => $image];
            }
        }

        return $this->generateur->rendre('pdf/bordereau_amo.html.twig', [
            'bordereau' => $bordereau,
            'pharmacie' => $pharmacie,
            'annexes' => $annexes,
            'sans_copie' => array_map(static fn (CreanceAmo $c) => $c->getVente()->getNumero(), $sansCopie),
        ]);
    }

    public function reponsePdf(BordereauAmo $bordereau): Response
    {
        return GenerateurPdf::reponse($this->pdf($bordereau), $this->nomFichier($bordereau).'.pdf');
    }

    private function nomFichier(BordereauAmo $bordereau): string
    {
        return 'bordereau-'.($bordereau->getNumero() ?? 'brouillon-'.$bordereau->getId()).'-'.strtolower($bordereau->getOrganisme()->getCode());
    }

    private function pharmacie(BordereauAmo $bordereau): Pharmacie
    {
        return $bordereau->getPharmacie() ?? throw new \LogicException('Bordereau sans pharmacie.');
    }
}
