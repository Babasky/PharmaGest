<?php

namespace App\Amo;

use App\Entity\BordereauAmo;
use App\Entity\CreanceAmo;
use App\Entity\LigneVente;
use App\Entity\Pharmacie;
use App\Pdf\GenerateurPdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports du bordereau AMO (AM-07) : classeur Excel, et PDF unique avec les copies des ordonnances et des accords
 * préalables en annexe.
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
        $classeur->getProperties()->setCreator('SoftPharm')->setTitle('Bordereau '.$bordereau->getLibelle());
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Bordereau');

        $feuille->setCellValue('A1', \sprintf('Bordereau %s — %s', $bordereau->getLibelle(), $bordereau->getOrganisme()->getNom()));
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $feuille->setCellValueExplicit('A2', \sprintf('%s · autorisation n° %s', $pharmacie->getNom(), $pharmacie->getNumeroAutorisation()), DataType::TYPE_STRING);
        $feuille->setCellValue('A3', \sprintf('Ventes du %s au %s · %s', $bordereau->getDebut()->format('d/m/Y'), $bordereau->getFin()->format('d/m/Y'), $bordereau->getStatut()->libelle()));

        $entetes = ['N°', 'Date', 'N° vente', 'Assuré', 'N° assuré', 'N° ordonnance', 'Date ordonnance', 'Prescripteur', 'Structure', 'Total vente', $bordereau->getOrganisme()->appliqueTarifAmo() ? 'Base remboursable (prix AMO)' : 'Base remboursable', 'Taux (%)', 'Part '.$bordereau->getOrganisme()->getCode()];
        $transmis = $bordereau->estTransmis();
        if ($transmis) {
            array_push($entetes, 'Réglé', 'Statut', 'Motif du rejet');
        }
        $accords = $bordereau->getOrganisme()->appliqueTarifAmo();
        if ($accords) {
            $entetes[] = 'Accords préalables';
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
            if ($accords) {
                $numeros = [];
                foreach ($vente->getLignes() as $l) {
                    if (null !== $l->getNumeroAccord() && $l->isCouverte()) {
                        $numeros[] = \sprintf('%s : %s', $l->getProduit()->getNomCommercial(), $l->getNumeroAccord());
                    }
                }
                $valeurs[] = [] === $numeros ? null : implode(' ; ', $numeros);
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
            if (null === $ordonnance || null === $this->copies->chemin($pharmacie, $ordonnance)) {
                $sansCopie[] = $creance;
            } else {
                // Une copie PDF ne s'insère pas dans le PDF du bordereau : son annexe renvoie au fichier, fourni dans le ZIP.
                $annexes[] = ['numero' => $n + 1, 'creance' => $creance, 'image' => $this->copies->dataUri($pharmacie, $ordonnance), 'fichier' => $this->nomCopie($n + 1, $creance), 'accord' => null];
            }
            foreach ($this->accords($creance) as $k => $ligne) {
                $annexes[] = ['numero' => $n + 1, 'creance' => $creance, 'image' => $this->copies->dataUriAccord($pharmacie, $ligne), 'fichier' => $this->nomAccord($n + 1, $creance, $k, $ligne), 'accord' => $ligne];
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

    /**
     * Archive ZIP des copies d'ordonnances et d'accords préalables du bordereau (photos et PDF), nommées par n° de ligne et de vente,
     * à remettre à l'organisme avec le bordereau. Fichier temporaire, supprimé après l'envoi.
     */
    public function reponseCopies(BordereauAmo $bordereau): Response
    {
        $pharmacie = $this->pharmacie($bordereau);
        $chemin = (string) tempnam(sys_get_temp_dir(), 'ord');
        $zip = new \ZipArchive();
        if (true !== $zip->open($chemin, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Impossible de préparer l\'archive des ordonnances.');
        }
        foreach ($bordereau->getCreances() as $n => $creance) {
            $ordonnance = $creance->getVente()->getOrdonnance();
            $copie = null === $ordonnance ? null : $this->copies->chemin($pharmacie, $ordonnance);
            if (null !== $copie) {
                $zip->addFile($copie, $this->nomCopie($n + 1, $creance));
            }
            foreach ($this->accords($creance) as $k => $ligne) {
                $zip->addFile((string) $this->copies->cheminAccord($pharmacie, $ligne), $this->nomAccord($n + 1, $creance, $k, $ligne));
            }
        }
        $zip->close();

        $reponse = new BinaryFileResponse($chemin, headers: ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
        $reponse->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->nomFichier($bordereau).'-ordonnances.zip');

        return $reponse->deleteFileAfterSend();
    }

    /** Nom de la copie dans l'archive, repris dans l'annexe du PDF : « 03-V-2026-000012.pdf ». */
    private function nomCopie(int $rang, CreanceAmo $creance): string
    {
        $copie = (string) $creance->getVente()->getOrdonnance()?->getCopie();

        return \sprintf('%02d-%s.%s', $rang, $creance->getVente()->getNumero(), pathinfo($copie, \PATHINFO_EXTENSION));
    }

    /**
     * Lignes prises en charge avec la copie de leur accord préalable, indexées par rang dans la vente (à partir de 1).
     *
     * @return array<int, LigneVente>
     */
    private function accords(CreanceAmo $creance): array
    {
        $accords = [];
        foreach (array_values($creance->getVente()->getLignes()->toArray()) as $i => $ligne) {
            if ($ligne->isAccordPrealable() && null !== $ligne->getCopieAccord() && $ligne->isCouverte()) {
                $accords[$i + 1] = $ligne;
            }
        }

        return $accords;
    }

    /** « 03-V-2026-000012-accord-2.pdf » : accord de la 2e ligne de la vente. */
    private function nomAccord(int $rang, CreanceAmo $creance, int $ligne, LigneVente $accord): string
    {
        return \sprintf('%02d-%s-accord-%d.%s', $rang, $creance->getVente()->getNumero(), $ligne, pathinfo((string) $accord->getCopieAccord(), \PATHINFO_EXTENSION));
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
