<?php

namespace App\Amo;

use App\Entity\LigneVente;
use App\Entity\Ordonnance;
use App\Entity\Pharmacie;
use App\Stockage\StockageFichiers;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photo, scan ou PDF de l'ordonnance (AM-01), jointe en annexe du bordereau AMO (AM-07), et de l'accord préalable
 * de l'AMO pour un médicament qui l'exige.
 *
 * L'image est recompressée en JPEG (1600 px au plus) : elle reste lisible, se charge vite en 3G et
 * s'intègre telle quelle au PDF du bordereau. Le PDF est gardé tel quel, après contrôle de son contenu.
 */
class CopieOrdonnance
{
    public const CATEGORIE = 'ordonnances';
    public const CATEGORIE_ACCORDS = 'accords';
    public const TAILLE_MAXIMALE = 8 * 1024 * 1024;
    private const COTE_MAXIMAL = 1600;
    /** Au-delà, l'image décompressée occuperait des centaines de Mo (« bombe » de décompression) : refusée avant d'être lue. */
    public const PIXELS_MAXIMUM = 30_000_000;
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const PDF = 'application/pdf';

    public function __construct(private readonly StockageFichiers $stockage)
    {
    }

    /**
     * @throws AmoException
     */
    public function enregistrer(Pharmacie $pharmacie, Ordonnance $ordonnance, UploadedFile $fichier): void
    {
        [$contenu, $extension] = $this->preparer($fichier, 'La copie de l\'ordonnance');
        $ancienne = $ordonnance->getCopie();
        $ordonnance->setCopie($this->stockage->ecrire($pharmacie, self::CATEGORIE, $contenu, $extension));
        $this->stockage->supprimer($pharmacie, self::CATEGORIE, $ancienne);
    }

    /**
     * Copie de l'accord préalable de l'organisme AMO pour une ligne de vente.
     *
     * @throws AmoException
     */
    public function enregistrerAccord(Pharmacie $pharmacie, LigneVente $ligne, UploadedFile $fichier): void
    {
        [$contenu, $extension] = $this->preparer($fichier, 'La copie de l\'accord préalable');
        $ancienne = $ligne->getCopieAccord();
        $ligne->setCopieAccord($this->stockage->ecrire($pharmacie, self::CATEGORIE_ACCORDS, $contenu, $extension));
        $this->stockage->supprimer($pharmacie, self::CATEGORIE_ACCORDS, $ancienne);
    }

    public function cheminAccord(Pharmacie $pharmacie, LigneVente $ligne): ?string
    {
        return null === $ligne->getCopieAccord() ? null : $this->stockage->chemin($pharmacie, self::CATEGORIE_ACCORDS, $ligne->getCopieAccord());
    }

    /** Image de l'accord en data URI pour Dompdf ; null pour une copie PDF. */
    public function dataUriAccord(Pharmacie $pharmacie, LigneVente $ligne): ?string
    {
        $chemin = $ligne->copieAccordEstPdf() ? null : $this->cheminAccord($pharmacie, $ligne);

        return null === $chemin ? null : 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($chemin));
    }

    /**
     * Contenu à enregistrer et son extension : le PDF tel quel, l'image recompressée en JPEG.
     *
     * @return array{string, string}
     *
     * @throws AmoException
     */
    private function preparer(UploadedFile $fichier, string $document): array
    {
        if (!$fichier->isValid()) {
            throw new AmoException('Le fichier n\'a pas pu être envoyé. Réessayez avec une photo plus légère.');
        }
        if ($fichier->getSize() > self::TAILLE_MAXIMALE) {
            throw new AmoException(\sprintf('%s ne doit pas dépasser 8 Mo.', $document));
        }
        // Type lu dans le contenu du fichier, pas dans son nom ni dans ce que déclare le navigateur.
        $type = $fichier->getMimeType();
        if (self::PDF === $type) {
            return [$this->lirePdf($fichier), 'pdf'];
        }
        if (!\in_array($type, self::TYPES, true)) {
            throw new AmoException(\sprintf('%s doit être une photo (JPEG, PNG ou WebP) ou un PDF.', $document));
        }
        $dimensions = @getimagesize($fichier->getPathname());
        if (false === $dimensions) {
            throw new AmoException('Cette image est illisible.');
        }
        if ($dimensions[0] * $dimensions[1] > self::PIXELS_MAXIMUM) {
            throw new AmoException('Cette image est trop grande : prenez la photo en résolution normale.');
        }
        $image = @imagecreatefromstring((string) file_get_contents($fichier->getPathname()));
        if (false === $image) {
            throw new AmoException('Cette image est illisible.');
        }

        $largeur = imagesx($image);
        $hauteur = imagesy($image);
        $echelle = min(1, self::COTE_MAXIMAL / max($largeur, $hauteur));
        if ($echelle < 1) {
            $reduite = imagescale($image, max(1, (int) round($largeur * $echelle)), max(1, (int) round($hauteur * $echelle)));
            if (false !== $reduite) {
                $image = $reduite;
            }
        }
        // Fond blanc sous la transparence éventuelle (PNG) : le JPEG n'en a pas.
        $fond = imagecreatetruecolor(imagesx($image), imagesy($image));
        if (false === $fond) {
            throw new AmoException('Cette image est trop grande.');
        }
        imagefill($fond, 0, 0, (int) imagecolorallocate($fond, 255, 255, 255));
        imagecopy($fond, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($fond, null, 80);

        return [(string) ob_get_clean(), 'jpg'];
    }

    /**
     * @throws AmoException
     */
    private function lirePdf(UploadedFile $fichier): string
    {
        $contenu = (string) file_get_contents($fichier->getPathname());
        if (!str_starts_with($contenu, '%PDF-')) {
            throw new AmoException('Ce PDF est illisible.');
        }

        return $contenu;
    }

    public function chemin(Pharmacie $pharmacie, Ordonnance $ordonnance): ?string
    {
        return null === $ordonnance->getCopie() ? null : $this->stockage->chemin($pharmacie, self::CATEGORIE, $ordonnance->getCopie());
    }

    /** Image en data URI pour Dompdf, qui ne charge aucune ressource externe ; null pour une copie PDF. */
    public function dataUri(Pharmacie $pharmacie, Ordonnance $ordonnance): ?string
    {
        $chemin = $ordonnance->copieEstPdf() ? null : $this->chemin($pharmacie, $ordonnance);

        return null === $chemin ? null : 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($chemin));
    }

    public function supprimer(Pharmacie $pharmacie, Ordonnance $ordonnance): void
    {
        $this->stockage->supprimer($pharmacie, self::CATEGORIE, $ordonnance->getCopie());
        $ordonnance->setCopie(null);
    }
}
