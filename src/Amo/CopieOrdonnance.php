<?php

namespace App\Amo;

use App\Entity\Ordonnance;
use App\Entity\Pharmacie;
use App\Stockage\StockageFichiers;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photo ou scan de l'ordonnance (AM-01), jointe en annexe du bordereau AMO (AM-07).
 *
 * L'image est recompressée en JPEG (1600 px au plus) : elle reste lisible, se charge vite en 3G et
 * s'intègre telle quelle au PDF du bordereau.
 */
class CopieOrdonnance
{
    public const CATEGORIE = 'ordonnances';
    public const TAILLE_MAXIMALE = 8 * 1024 * 1024;
    private const COTE_MAXIMAL = 1600;
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly StockageFichiers $stockage)
    {
    }

    /**
     * @throws AmoException
     */
    public function enregistrer(Pharmacie $pharmacie, Ordonnance $ordonnance, UploadedFile $fichier): void
    {
        if (!$fichier->isValid()) {
            throw new AmoException('Le fichier n\'a pas pu être envoyé. Réessayez avec une photo plus légère.');
        }
        if ($fichier->getSize() > self::TAILLE_MAXIMALE) {
            throw new AmoException('La copie de l\'ordonnance ne doit pas dépasser 8 Mo.');
        }
        if (!\in_array($fichier->getMimeType(), self::TYPES, true)) {
            throw new AmoException('La copie de l\'ordonnance doit être une photo (JPEG, PNG ou WebP).');
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
        $contenu = (string) ob_get_clean();

        $ancienne = $ordonnance->getCopie();
        $ordonnance->setCopie($this->stockage->ecrire($pharmacie, self::CATEGORIE, $contenu, 'jpg'));
        $this->stockage->supprimer($pharmacie, self::CATEGORIE, $ancienne);
    }

    public function chemin(Pharmacie $pharmacie, Ordonnance $ordonnance): ?string
    {
        return null === $ordonnance->getCopie() ? null : $this->stockage->chemin($pharmacie, self::CATEGORIE, $ordonnance->getCopie());
    }

    /** Image en data URI pour Dompdf, qui ne charge aucune ressource externe. */
    public function dataUri(Pharmacie $pharmacie, Ordonnance $ordonnance): ?string
    {
        $chemin = $this->chemin($pharmacie, $ordonnance);

        return null === $chemin ? null : 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($chemin));
    }

    public function supprimer(Pharmacie $pharmacie, Ordonnance $ordonnance): void
    {
        $this->stockage->supprimer($pharmacie, self::CATEGORIE, $ordonnance->getCopie());
        $ordonnance->setCopie(null);
    }
}
