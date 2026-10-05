<?php

namespace App\Achat;

use App\Entity\Commande;
use App\Entity\Pharmacie;
use App\Util\Telephone;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bon de commande Excel mis en forme, prêt à imprimer (CO-04) : en-têtes pharmacie et fournisseur, numéro, date,
 * lignes et total. C'est aussi la pièce jointe de l'email au fournisseur (CO-05).
 */
class ExportCommandeExcel
{
    public const TYPE_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const FORMAT_FCFA = '#,##0';
    private const VERT = 'E9F3EE';

    public function excel(Commande $commande): Spreadsheet
    {
        $pharmacie = $commande->getPharmacie() ?? throw new \LogicException('Commande sans pharmacie.');
        $fournisseur = $commande->getFournisseur();

        $classeur = new Spreadsheet();
        $classeur->getProperties()->setCreator($pharmacie->getNom())->setTitle('Bon de commande '.$commande->getLibelle());
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Bon de commande');

        // En-têtes : la pharmacie à gauche, le fournisseur à droite.
        $feuille->setCellValue('A1', $pharmacie->getNom());
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ligne = 2;
        foreach ($this->coordonneesPharmacie($pharmacie) as $texte) {
            $this->texte($feuille, 'A'.$ligne++, $texte);
        }

        $feuille->setCellValue('E1', 'Fournisseur');
        $feuille->getStyle('E1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('6C757D');
        $feuille->setCellValue('E2', $fournisseur->getNom());
        $feuille->getStyle('E2')->getFont()->setBold(true)->setSize(12);
        $ligneF = 3;
        foreach (array_filter([
            $fournisseur->getContact(),
            $fournisseur->getAdresse(),
            null === $fournisseur->getTelephone() ? null : 'Tél. '.Telephone::format($fournisseur->getTelephone()),
            $fournisseur->getEmail(),
        ]) as $texte) {
            $this->texte($feuille, 'E'.$ligneF++, $texte);
        }

        $ligne = max($ligne, $ligneF) + 1;
        $feuille->setCellValue('A'.$ligne, $commande->estBrouillon() ? 'BON DE COMMANDE — BROUILLON' : 'BON DE COMMANDE');
        $feuille->mergeCells("A{$ligne}:G{$ligne}");
        $feuille->getStyle('A'.$ligne)->getFont()->setBold(true)->setSize(16);
        $feuille->getStyle('A'.$ligne)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        ++$ligne;
        $date = $commande->getEnvoyeeLe() ?? $commande->getCreeLe();
        $this->texte($feuille, 'A'.$ligne, 'N° '.($commande->getNumero() ?? 'non attribué (brouillon)'));
        $feuille->getStyle('A'.$ligne)->getFont()->setBold(true);
        $this->texte($feuille, 'E'.$ligne, 'Date : '.$date->format('d/m/Y'));
        $feuille->getStyle('E'.$ligne)->getFont()->setBold(true);

        // Lignes de la commande.
        $ligne += 2;
        $debutTableau = $ligne;
        foreach (['N°', 'Désignation', 'Code-barres', 'Conditionnement', 'Quantité', 'Prix unitaire (FCFA)', 'Montant (FCFA)'] as $i => $entete) {
            $feuille->setCellValue([$i + 1, $ligne], $entete);
        }
        $entetes = "A{$ligne}:G{$ligne}";
        $feuille->getStyle($entetes)->getFont()->setBold(true);
        $feuille->getStyle($entetes)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::VERT);
        $feuille->getStyle($entetes)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

        foreach ($commande->getLignes() as $n => $ligneCommande) {
            ++$ligne;
            $produit = $ligneCommande->getProduit();
            $feuille->setCellValue([1, $ligne], $n + 1);
            $feuille->setCellValueExplicit([2, $ligne], $produit->getDesignation(), DataType::TYPE_STRING);
            // Code-barres en texte : pas de notation scientifique ni de zéro initial perdu.
            $feuille->setCellValueExplicit([3, $ligne], (string) $produit->getCodeBarres(), DataType::TYPE_STRING);
            $feuille->setCellValueExplicit([4, $ligne], (string) $produit->getConditionnement(), DataType::TYPE_STRING);
            $feuille->setCellValue([5, $ligne], $ligneCommande->getQuantite());
            $feuille->setCellValue([6, $ligne], $ligneCommande->getPrixEstime());
            $feuille->setCellValue([7, $ligne], "=E{$ligne}*F{$ligne}");
        }
        $finLignes = $ligne;

        ++$ligne;
        $feuille->setCellValue([1, $ligne], 'Total');
        $feuille->mergeCells("A{$ligne}:D{$ligne}");
        $feuille->setCellValue([5, $ligne], $finLignes > $debutTableau ? '=SUM(E'.($debutTableau + 1).":E{$finLignes})" : 0);
        $feuille->setCellValue([7, $ligne], $finLignes > $debutTableau ? '=SUM(G'.($debutTableau + 1).":G{$finLignes})" : 0);
        $feuille->getStyle("A{$ligne}:G{$ligne}")->getFont()->setBold(true);
        $feuille->getStyle("A{$ligne}:G{$ligne}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::VERT);

        $feuille->getStyle("A{$debutTableau}:G{$ligne}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $feuille->getStyle('F'.($debutTableau + 1).":G{$ligne}")->getNumberFormat()->setFormatCode(self::FORMAT_FCFA);
        $feuille->getStyle('E'.($debutTableau + 1).":E{$ligne}")->getNumberFormat()->setFormatCode(self::FORMAT_FCFA);
        $feuille->getStyle('B'.($debutTableau + 1).":B{$finLignes}")->getAlignment()->setWrapText(true);

        $ligne += 2;
        $this->texte($feuille, 'A'.$ligne, 'Prix unitaires indicatifs, établis d\'après nos derniers achats. Merci d\'indiquer les numéros de lot et dates de péremption sur le bon de livraison.');
        $feuille->getStyle('A'.$ligne)->getFont()->setItalic(true)->setSize(9);

        foreach (['A' => 5, 'B' => 42, 'C' => 16, 'D' => 18, 'E' => 10, 'F' => 13, 'G' => 15] as $colonne => $largeur) {
            $feuille->getColumnDimension($colonne)->setWidth($largeur);
        }

        // Impression : A4 portrait, une page en largeur, en-tête du tableau répété sur chaque page.
        $mise = $feuille->getPageSetup();
        $mise->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $mise->setFitToWidth(1)->setFitToHeight(0);
        $mise->setRowsToRepeatAtTopByStartAndEnd($debutTableau, $debutTableau);
        $mise->setPrintArea("A1:G{$ligne}");
        $feuille->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $feuille->getHeaderFooter()->setOddFooter('&L'.$commande->getLibelle().'&RPage &P / &N');

        return $classeur;
    }

    /** Contenu binaire du fichier .xlsx (pièce jointe de l'email). */
    public function contenu(Commande $commande): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'cmd');
        if (false === $chemin) {
            throw new \RuntimeException('Impossible de créer le fichier temporaire du bon de commande.');
        }
        try {
            (new Xlsx($this->excel($commande)))->save($chemin);

            return (string) file_get_contents($chemin);
        } finally {
            @unlink($chemin);
        }
    }

    public function reponse(Commande $commande): Response
    {
        return new Response($this->contenu($commande), Response::HTTP_OK, [
            'Content-Type' => self::TYPE_MIME,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->nomFichier($commande)),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function nomFichier(Commande $commande): string
    {
        return 'bon-de-commande-'.($commande->getNumero() ?? 'brouillon-'.$commande->getId()).'.xlsx';
    }

    /**
     * @return list<string>
     */
    private function coordonneesPharmacie(Pharmacie $pharmacie): array
    {
        return array_values(array_filter([
            trim($pharmacie->getAdresse().' — '.$pharmacie->getVille(), ' —'),
            'Tél. '.Telephone::format($pharmacie->getTelephone()),
            $pharmacie->getEmail(),
            'Autorisation n° '.$pharmacie->getNumeroAutorisation(),
        ], static fn (?string $t) => null !== $t && '' !== $t));
    }

    private function texte(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $cellule, string $texte): void
    {
        $feuille->setCellValueExplicit($cellule, $texte, DataType::TYPE_STRING);
    }
}
