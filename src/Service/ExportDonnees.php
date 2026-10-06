<?php

namespace App\Service;

use App\Entity\FormeGalenique;
use App\Entity\Notification;
use App\Entity\OrganismeAmo;
use App\Entity\Pharmacie;
use App\Repository\AffectationRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Export complet des données d'une pharmacie (réversibilité, § 6) : une archive ZIP avec un fichier CSV par table
 * (lisible dans Excel) et tous les fichiers de la pharmacie (logo, justificatifs, copies d'ordonnances).
 *
 * Les tables sont lues directement en base, ligne à ligne : l'export reste léger quelle que soit la volumétrie, et
 * toute nouvelle entité de la pharmacie y figure sans code à ajouter.
 */
class ExportDonnees
{
    /** Tables techniques sans intérêt pour le propriétaire. */
    private const EXCLUES = [Notification::class];

    /** Référentiels communs auxquels les données de la pharmacie font référence (par identifiant). */
    private const REFERENTIELS = [FormeGalenique::class, OrganismeAmo::class];

    /** @var list<string> fichiers CSV temporaires de l'archive en cours, supprimés une fois l'archive fermée */
    private array $temporaires = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AffectationRepository $affectations,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
        #[Autowire('%app.dossier_fichiers%')]
        private readonly string $dossierFichiers,
    ) {
    }

    /**
     * Crée l'archive dans un fichier temporaire et renvoie son chemin (à supprimer par l'appelant).
     */
    public function creerArchive(Pharmacie $pharmacie): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'export-');
        if (false === $chemin) {
            throw new \RuntimeException('Impossible de créer le fichier d\'export.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($chemin, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Impossible de créer l\'archive d\'export.');
        }

        try {
            $tables = $this->tenantContext->sansFiltre(fn () => $this->ecrireTables($pharmacie, $zip));
            $nombreFichiers = $this->ajouterFichiers($pharmacie, $zip);
            $zip->addFromString('LISEZMOI.txt', $this->lisezMoi($pharmacie, $tables, $nombreFichiers));
            $zip->close();
        } finally {
            foreach ($this->temporaires as $temporaire) {
                @unlink($temporaire);
            }
            $this->temporaires = [];
        }

        return $chemin;
    }

    public function nomArchive(Pharmacie $pharmacie): string
    {
        $nom = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $pharmacie->getNom()) ?: 'pharmacie'), '-'));

        return \sprintf('pharmagest-%s-%s.zip', $nom, $this->horloge->now()->format('Y-m-d'));
    }

    /**
     * @return array<string, int> nombre de lignes par fichier CSV
     */
    private function ecrireTables(Pharmacie $pharmacie, \ZipArchive $zip): array
    {
        $connexion = $this->em->getConnection();
        $id = (int) $pharmacie->getId();
        $tables = [];

        $tables['pharmacie.csv'] = $this->csv($zip, 'pharmacie.csv', $connexion->iterateAssociative(
            'SELECT id, nom, ville, adresse, telephone, email, numero_autorisation, fin_essai, fin_abonnement, cree_le FROM pharmacie WHERE id = ?', [$id]));

        $equipe = [];
        foreach ($this->affectations->equipe($pharmacie) as $affectation) {
            $u = $affectation->getUtilisateur();
            $equipe[] = ['id' => $u->getId(), 'nom' => $u->getNom(), 'email' => $u->getEmail(), 'role' => $u->getLibelleRole(), 'acces_actif' => $affectation->isActif() && $u->isActif() ? 1 : 0];
        }
        $tables['equipe.csv'] = $this->csv($zip, 'equipe.csv', $equipe);

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $classe = $meta->getName();
            if ($meta->isMappedSuperclass || \in_array($classe, self::EXCLUES, true)) {
                continue;
            }
            $tenant = $meta->getReflectionClass()->implementsInterface(TenantAwareInterface::class);
            if (!$tenant && !\in_array($classe, self::REFERENTIELS, true)) {
                continue;
            }
            $table = $meta->getTableName();
            $nom = ($tenant ? '' : 'referentiel_').$table.'.csv';
            $sql = \sprintf('SELECT * FROM %s%s ORDER BY id', $connexion->quoteSingleIdentifier($table), $tenant ? ' WHERE pharmacie_id = ?' : '');
            $tables[$nom] = $this->csv($zip, $nom, $connexion->iterateAssociative($sql, $tenant ? [$id] : []));
        }
        ksort($tables);

        return $tables;
    }

    /**
     * Écrit un CSV « Excel » (UTF-8 avec BOM, séparateur point-virgule) dans l'archive.
     *
     * @param iterable<array<string, mixed>> $lignes
     */
    private function csv(\ZipArchive $zip, string $nom, iterable $lignes): int
    {
        $chemin = tempnam(sys_get_temp_dir(), 'csv-');
        $flux = false === $chemin ? false : fopen($chemin, 'w');
        if (false === $chemin || false === $flux) {
            throw new \RuntimeException('Impossible d\'écrire le fichier d\'export.');
        }
        $this->temporaires[] = $chemin;
        fwrite($flux, "\u{FEFF}");
        $nombre = 0;
        foreach ($lignes as $ligne) {
            if (0 === $nombre) {
                fputcsv($flux, array_keys($ligne), ';', '"', '');
            }
            fputcsv($flux, array_map(self::cellule(...), array_values($ligne)), ';', '"', '');
            ++$nombre;
        }
        fclose($flux);
        $zip->addFile($chemin, 'donnees/'.$nom);

        return $nombre;
    }

    /**
     * Un texte qui commence comme une formule (=, +, @, -…) est préfixé d'une apostrophe : Excel l'affiche tel quel
     * au lieu de l'exécuter (injection de formule, OWASP).
     */
    private static function cellule(mixed $valeur): mixed
    {
        if (\is_bool($valeur)) {
            return (int) $valeur;
        }
        if (\is_string($valeur) && 1 === preg_match('/^(?:[=+@\t\r]|-(?![\d.]+$))/', $valeur)) {
            return "'".$valeur;
        }

        return $valeur;
    }

    private function ajouterFichiers(Pharmacie $pharmacie, \ZipArchive $zip): int
    {
        $dossier = \sprintf('%s/pharmacie-%d', $this->dossierFichiers, $pharmacie->getId());
        if (!is_dir($dossier)) {
            return 0;
        }
        $nombre = 0;
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            /** @var \SplFileInfo $fichier */
            $relatif = substr($fichier->getPathname(), \strlen($dossier) + 1);
            // Les exports d'archivage déjà produits ne sont pas réinclus.
            if (!$fichier->isFile() || str_starts_with($relatif, 'archives')) {
                continue;
            }
            $zip->addFile($fichier->getPathname(), 'fichiers/'.$relatif);
            ++$nombre;
        }

        return $nombre;
    }

    /**
     * @param array<string, int> $tables
     */
    private function lisezMoi(Pharmacie $pharmacie, array $tables, int $nombreFichiers): string
    {
        $lignes = [
            \sprintf('Export complet PharmaGest — %s (%s)', $pharmacie->getNom(), $pharmacie->getVille()),
            'Généré le '.$this->horloge->now()->format('d/m/Y à H:i').' (heure de Bamako)',
            '',
            'Dossier « donnees » : un fichier CSV par table, à ouvrir avec Excel (UTF-8, séparateur point-virgule).',
            'Les colonnes « …_id » renvoient à la colonne « id » de la table correspondante ; les montants sont en FCFA.',
            'Les fichiers « referentiel_… » sont les listes communes à toutes les pharmacies (formes, organismes AMO).',
            'Un texte qui commence par =, +, - ou @ est précédé d\'une apostrophe pour qu\'Excel ne l\'interprète pas comme une formule.',
            'Dossier « fichiers » : logo, justificatifs de dépenses et copies d\'ordonnances, tels qu\'enregistrés.',
            '',
            'Contenu :',
        ];
        foreach ($tables as $nom => $nombre) {
            $lignes[] = \sprintf('  donnees/%s : %d ligne(s)', $nom, $nombre);
        }
        $lignes[] = \sprintf('  fichiers/ : %d fichier(s)', $nombreFichiers);

        return implode("\r\n", $lignes)."\r\n";
    }
}
