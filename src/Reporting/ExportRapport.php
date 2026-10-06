<?php

namespace App\Reporting;

use App\Entity\Pharmacie;
use App\Pdf\GenerateurPdf;
use App\Util\Fcfa;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export Excel et PDF de chaque rapport (RA-11) : les mêmes tableaux qu'à l'écran, sans les graphiques.
 */
class ExportRapport
{
    public const TYPE_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const VERT = 'E9F3EE';
    private const FORMATS = [
        Tableau::MONTANT => '#,##0',
        Tableau::NOMBRE => '#,##0',
        Tableau::POURCENTAGE => '0.0" %"',
        Tableau::DATE => 'dd/mm/yyyy',
    ];

    public function __construct(
        private readonly GenerateurPdf $pdf,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function excel(Rapport $rapport, Pharmacie $pharmacie): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $classeur->getProperties()->setCreator($pharmacie->getNom())->setTitle($rapport->titre.' — '.$rapport->periode->libelle());
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle(mb_substr($rapport->titre, 0, 31));

        $feuille->setCellValueExplicit('A1', $pharmacie->getNom(), DataType::TYPE_STRING);
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $feuille->setCellValueExplicit('A2', $rapport->titre.' — '.$rapport->periode->libelle(), DataType::TYPE_STRING);
        $feuille->getStyle('A2')->getFont()->setBold(true)->setSize(14);
        $feuille->setCellValueExplicit('A3', 'Édité le '.$this->horloge->now()->format('d/m/Y à H:i').' (heure de Bamako)', DataType::TYPE_STRING);
        $feuille->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

        $ligne = 5;
        $largeurs = [];
        foreach ($rapport->tableaux as $tableau) {
            $ligne = $this->tableau($feuille, $tableau, $ligne, $largeurs) + 2;
        }

        foreach ($largeurs as $colonne => $largeur) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($colonne))->setWidth(min(48, max(10, $largeur + 2)));
        }
        $mise = $feuille->getPageSetup();
        $mise->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $mise->setFitToWidth(1)->setFitToHeight(0);
        $feuille->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $feuille->getHeaderFooter()->setOddFooter('&L'.$rapport->titre.' — '.$rapport->periode->libelle().'&RPage &P / &N');

        return $classeur;
    }

    public function reponseExcel(Rapport $rapport, Pharmacie $pharmacie): Response
    {
        $chemin = tempnam(sys_get_temp_dir(), 'rap');
        if (false === $chemin) {
            throw new \RuntimeException('Impossible de créer le fichier temporaire du rapport.');
        }
        try {
            (new Xlsx($this->excel($rapport, $pharmacie)))->save($chemin);
            $contenu = (string) file_get_contents($chemin);
        } finally {
            @unlink($chemin);
        }

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => self::TYPE_MIME,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $rapport->nomFichier('xlsx')),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function reponsePdf(Rapport $rapport, Pharmacie $pharmacie): Response
    {
        $contenu = $this->pdf->rendre('pdf/rapport.html.twig', [
            'rapport' => $rapport,
            'pharmacie' => $pharmacie,
            'edite_le' => $this->horloge->now(),
        ], 'A4');

        return GenerateurPdf::reponse($contenu, $rapport->nomFichier('pdf'));
    }

    /** Texte d'une cellule, à l'écran et dans le PDF. */
    public static function formater(mixed $valeur, string $type): string
    {
        if (null === $valeur || '' === $valeur) {
            return '—';
        }

        return match ($type) {
            Tableau::MONTANT => Fcfa::format(\is_scalar($valeur) ? (string) $valeur : 0),
            Tableau::NOMBRE => number_format(is_numeric($valeur) ? (float) $valeur : 0, 0, ',', "\u{00A0}"),
            Tableau::POURCENTAGE => str_replace('.', ',', (string) round(is_numeric($valeur) ? (float) $valeur : 0, 1))."\u{00A0}%",
            Tableau::DATE => $valeur instanceof \DateTimeInterface ? $valeur->format('d/m/Y') : (\is_scalar($valeur) ? (string) $valeur : ''),
            default => $valeur instanceof \DateTimeInterface ? $valeur->format('d/m/Y') : (\is_scalar($valeur) ? (string) $valeur : ''),
        };
    }

    /**
     * Écrit un tableau à partir de la ligne donnée ; renvoie la dernière ligne écrite.
     *
     * @param array<int, int> $largeurs largeur utile de chaque colonne, mise à jour
     */
    private function tableau(Worksheet $feuille, Tableau $tableau, int $ligne, array &$largeurs): int
    {
        $nombre = \count($tableau->colonnes);
        $derniere = Coordinate::stringFromColumnIndex($nombre);

        $feuille->setCellValueExplicit('A'.$ligne, $tableau->titre, DataType::TYPE_STRING);
        $feuille->getStyle('A'.$ligne)->getFont()->setBold(true)->setSize(12);
        ++$ligne;

        $debut = $ligne;
        foreach (array_keys($tableau->colonnes) as $i => $entete) {
            $feuille->setCellValueExplicit([$i + 1, $ligne], $entete, DataType::TYPE_STRING);
            $largeurs[$i + 1] = max($largeurs[$i + 1] ?? 0, min(30, mb_strlen($entete)));
        }
        $feuille->getStyle("A{$ligne}:{$derniere}{$ligne}")->getFont()->setBold(true);
        $feuille->getStyle("A{$ligne}:{$derniere}{$ligne}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::VERT);
        $feuille->getStyle("A{$ligne}:{$derniere}{$ligne}")->getAlignment()->setWrapText(true);

        if ($tableau->estVide()) {
            ++$ligne;
            $feuille->setCellValueExplicit('A'.$ligne, 'Aucune donnée sur la période.', DataType::TYPE_STRING);
            $feuille->getStyle('A'.$ligne)->getFont()->setItalic(true);
        }
        foreach ($tableau->lignes as $n => $valeurs) {
            ++$ligne;
            $this->ligne($feuille, $tableau, $valeurs, $ligne, $n, $largeurs);
        }
        if (null !== $tableau->total) {
            ++$ligne;
            $this->ligne($feuille, $tableau, $tableau->total, $ligne, -1, $largeurs);
            $feuille->getStyle("A{$ligne}:{$derniere}{$ligne}")->getFont()->setBold(true);
            $feuille->getStyle("A{$ligne}:{$derniere}{$ligne}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::VERT);
        }
        $feuille->getStyle("A{$debut}:{$derniere}{$ligne}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        if (null !== $tableau->note) {
            ++$ligne;
            $feuille->setCellValueExplicit('A'.$ligne, $tableau->note, DataType::TYPE_STRING);
            $feuille->getStyle('A'.$ligne)->getFont()->setItalic(true)->setSize(9);
        }

        return $ligne;
    }

    /**
     * @param list<string|int|float|\DateTimeImmutable|null> $valeurs
     * @param array<int, int>                                $largeurs
     */
    private function ligne(Worksheet $feuille, Tableau $tableau, array $valeurs, int $ligne, int $n, array &$largeurs): void
    {
        foreach ($valeurs as $i => $valeur) {
            $type = $n >= 0 ? $tableau->type($n, $i) : ($tableau->types()[$i] ?? Tableau::TEXTE);
            $cellule = [$i + 1, $ligne];
            if (null === $valeur) {
                continue;
            }
            if ($valeur instanceof \DateTimeInterface) {
                $feuille->setCellValue($cellule, DateExcel::PHPToExcel($valeur));
                $type = Tableau::DATE;
                $largeurs[$i + 1] = max($largeurs[$i + 1] ?? 0, 10);
            } elseif (\is_string($valeur) || !isset(self::FORMATS[$type])) {
                $feuille->setCellValueExplicit($cellule, (string) $valeur, DataType::TYPE_STRING);
                $largeurs[$i + 1] = max($largeurs[$i + 1] ?? 0, mb_strlen((string) $valeur));
            } else {
                $feuille->setCellValue($cellule, $valeur);
                $largeurs[$i + 1] = max($largeurs[$i + 1] ?? 0, 12);
            }
            if (isset(self::FORMATS[$type])) {
                $feuille->getStyle($cellule)->getNumberFormat()->setFormatCode(self::FORMATS[$type]);
            }
        }
    }
}
