<?php

namespace App\Stockage;

use App\Entity\Pharmacie;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fichiers des pharmacies (logos, copies d'ordonnances ; plus tard justificatifs), rangés par pharmacie
 * hors du dossier public (§ 7.3) et servis uniquement par un contrôleur qui vérifie les droits.
 */
class StockageFichiers
{
    public function __construct(
        #[Autowire('%app.dossier_fichiers%')]
        private readonly string $racine,
    ) {
    }

    /**
     * Enregistre le fichier sous un nom aléatoire (le nom d'origine n'est jamais réutilisé) et renvoie ce nom.
     */
    public function enregistrer(Pharmacie $pharmacie, string $categorie, UploadedFile $fichier): string
    {
        $nom = bin2hex(random_bytes(12)).'.'.($fichier->guessExtension() ?? 'bin');
        $fichier->move($this->dossier($pharmacie, $categorie), $nom);

        return $nom;
    }

    /**
     * Enregistre un contenu déjà préparé (ex. image recompressée) sous un nom aléatoire et renvoie ce nom.
     */
    public function ecrire(Pharmacie $pharmacie, string $categorie, string $contenu, string $extension): string
    {
        $dossier = $this->dossier($pharmacie, $categorie);
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier de stockage inaccessible.');
        }
        $nom = bin2hex(random_bytes(12)).'.'.$extension;
        file_put_contents($dossier.'/'.$nom, $contenu);

        return $nom;
    }

    public function chemin(Pharmacie $pharmacie, string $categorie, string $nom): ?string
    {
        if (1 !== preg_match('/^[a-f0-9]{24}\.[a-z0-9]{1,5}$/', $nom)) {
            return null;
        }
        $chemin = $this->dossier($pharmacie, $categorie).'/'.$nom;

        return is_file($chemin) ? $chemin : null;
    }

    public function supprimer(Pharmacie $pharmacie, string $categorie, ?string $nom): void
    {
        $chemin = null === $nom ? null : $this->chemin($pharmacie, $categorie, $nom);
        if (null !== $chemin) {
            unlink($chemin);
        }
    }

    private function dossier(Pharmacie $pharmacie, string $categorie): string
    {
        if (1 !== preg_match('/^[a-z\-]+$/', $categorie)) {
            throw new \InvalidArgumentException('Catégorie de fichier invalide.');
        }

        return \sprintf('%s/pharmacie-%d/%s', $this->racine, $pharmacie->getId(), $categorie);
    }
}
