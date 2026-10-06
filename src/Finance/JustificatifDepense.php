<?php

namespace App\Finance;

use App\Entity\Depense;
use App\Entity\Pharmacie;
use App\Stockage\StockageFichiers;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Justificatif d'une dépense (FI-01) : photo ou PDF, rangé par pharmacie hors du dossier public.
 */
class JustificatifDepense
{
    public const CATEGORIE = 'justificatifs';
    public const TAILLE_MAXIMALE = 5 * 1024 * 1024;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(private readonly StockageFichiers $stockage)
    {
    }

    /**
     * Vérifie le fichier avant toute écriture.
     *
     * @throws FinanceException
     */
    public function verifier(UploadedFile $fichier): void
    {
        if (!$fichier->isValid()) {
            throw new FinanceException('Le justificatif n\'a pas pu être envoyé. Réessayez avec un fichier plus léger.');
        }
        if ($fichier->getSize() > self::TAILLE_MAXIMALE) {
            throw new FinanceException('Le justificatif ne doit pas dépasser 5 Mo.');
        }
        if (!isset(self::TYPES[(string) $fichier->getMimeType()])) {
            throw new FinanceException('Le justificatif doit être une photo (JPEG, PNG, WebP) ou un PDF.');
        }
    }

    /**
     * @throws FinanceException
     */
    public function enregistrer(Pharmacie $pharmacie, Depense $depense, UploadedFile $fichier): void
    {
        $this->verifier($fichier);
        $extension = self::TYPES[(string) $fichier->getMimeType()];
        $ancien = $depense->getJustificatif();
        $depense->setJustificatif($this->stockage->ecrire($pharmacie, self::CATEGORIE, (string) file_get_contents($fichier->getPathname()), $extension));
        $this->stockage->supprimer($pharmacie, self::CATEGORIE, $ancien);
    }

    public function chemin(Pharmacie $pharmacie, Depense $depense): ?string
    {
        return null === $depense->getJustificatif() ? null : $this->stockage->chemin($pharmacie, self::CATEGORIE, $depense->getJustificatif());
    }
}
