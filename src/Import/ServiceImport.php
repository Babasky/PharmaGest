<?php

namespace App\Import;

use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Import Excel / CSV avec rapport d'erreurs ligne par ligne (RF-08).
 *
 * Deux temps : {@see self::analyser()} ne modifie rien et produit le rapport ;
 * {@see self::importer()} enregistre les lignes valides en une seule transaction.
 */
class ServiceImport
{
    public const LIGNES_MAX = 5000;

    /** @var array<string, DefinitionImport> */
    private array $definitions = [];

    /**
     * @param iterable<DefinitionImport> $definitions
     */
    public function __construct(
        #[AutowireIterator(DefinitionImport::class)] iterable $definitions,
        private readonly EntityManagerInterface $em,
    ) {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->code()] = $definition;
        }
    }

    public function definition(string $code): ?DefinitionImport
    {
        return $this->definitions[$code] ?? null;
    }

    public function analyser(DefinitionImport $definition, string $chemin): RapportImport
    {
        return $this->traiter($definition, $chemin, simulation: true);
    }

    public function importer(DefinitionImport $definition, string $chemin): RapportImport
    {
        return $this->em->wrapInTransaction(function () use ($definition, $chemin): RapportImport {
            $rapport = $this->traiter($definition, $chemin, simulation: false);
            $this->em->flush();

            return $rapport;
        });
    }

    /**
     * Fichier modèle : en-têtes (obligatoires marqués d'un *), des lignes d'exemple importables telles quelles,
     * et une feuille d'aide.
     */
    public function modele(DefinitionImport $definition): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet()->setTitle($definition->libelle());
        foreach ($definition->colonnes() as $i => $colonne) {
            $feuille->setCellValue([$i + 1, 1], $colonne->entete.($colonne->obligatoire ? ' *' : ''));
            // En texte, pour qu'Excel garde les zéros et les longs codes-barres tels quels.
            foreach ($definition->exemples() as $j => $exemple) {
                $feuille->setCellValueExplicit([$i + 1, $j + 2], $exemple[$colonne->cle] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $feuille->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
        }
        $entete = $feuille->getStyle([1, 1, \count($definition->colonnes()), 1]);
        $entete->getFont()->setBold(true);
        $entete->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D1E7DD');
        $feuille->freezePane('A2');

        $aide = $classeur->createSheet()->setTitle('Aide');
        $aide->fromArray(['Colonne', 'Obligatoire', 'Explication'], null, 'A1');
        foreach ($definition->colonnes() as $i => $colonne) {
            $aide->fromArray([$colonne->entete, $colonne->obligatoire ? 'oui' : 'non', $colonne->aide], null, 'A'.($i + 2));
        }
        $aide->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (['A', 'B', 'C'] as $lettre) {
            $aide->getColumnDimension($lettre)->setAutoSize(true);
        }
        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    private function traiter(DefinitionImport $definition, string $chemin, bool $simulation): RapportImport
    {
        $rapport = new RapportImport();
        $definition->reinitialiser();

        try {
            $lignes = $this->lire($chemin);
        } catch (\Throwable) {
            $rapport->erreurFichier('Le fichier est illisible. Enregistrez-le au format Excel (.xlsx) ou CSV et réessayez.');

            return $rapport;
        }

        $entetes = array_shift($lignes) ?? [];
        $correspondance = $this->correspondance($definition, $entetes, $rapport);
        if ([] !== $rapport->getErreursFichier()) {
            return $rapport;
        }
        if (\count($lignes) > self::LIGNES_MAX) {
            $rapport->erreurFichier(\sprintf('Le fichier contient %d lignes : découpez-le en fichiers de %d lignes au maximum.', \count($lignes), self::LIGNES_MAX));

            return $rapport;
        }

        foreach ($lignes as $index => $cellules) {
            $valeurs = [];
            foreach ($correspondance as $position => $cle) {
                $valeurs[$cle] = $this->texte($cellules[$position] ?? null);
            }
            if ('' === implode('', $valeurs)) {
                continue; // ligne vide
            }

            [$ligne, $entite] = $definition->preparer($index + 2, $valeurs, $simulation);
            $rapport->ajouter($ligne);
            if (!$simulation && null !== $entite) {
                $this->em->persist($entite);
            }
        }

        return $rapport;
    }

    /**
     * @return list<list<mixed>>
     */
    private function lire(string $chemin): array
    {
        $lecteur = IOFactory::createReaderForFile($chemin);
        if ($lecteur instanceof Csv) {
            $lecteur->setInputEncoding(Csv::GUESS_ENCODING);
        }
        $lecteur->setReadDataOnly(true);

        /** @var list<list<mixed>> */
        return array_values(array_map('array_values', $lecteur->load($chemin)->getActiveSheet()->toArray(null, true, false, false)));
    }

    /**
     * Associe chaque colonne du fichier à une colonne attendue, en tolérant majuscules, accents et astérisques.
     *
     * @param list<mixed> $entetes
     *
     * @return array<int, string> position dans le fichier => clé de colonne
     */
    private function correspondance(DefinitionImport $definition, array $entetes, RapportImport $rapport): array
    {
        $attendues = [];
        foreach ($definition->colonnes() as $colonne) {
            $attendues[self::normaliser($colonne->entete)] = $colonne->cle;
            $attendues[self::normaliser($colonne->cle)] = $colonne->cle;
        }

        $correspondance = [];
        foreach ($entetes as $position => $entete) {
            $cle = $attendues[self::normaliser($this->texte($entete))] ?? null;
            if (null !== $cle) {
                $correspondance[$position] = $cle;
            }
        }

        foreach ($definition->colonnes() as $colonne) {
            if ($colonne->obligatoire && !\in_array($colonne->cle, $correspondance, true)) {
                $rapport->erreurFichier(\sprintf('Colonne obligatoire absente : « %s ». Partez du fichier modèle.', $colonne->entete));
            }
        }

        return $correspondance;
    }

    private function texte(mixed $valeur): string
    {
        return match (true) {
            null === $valeur => '',
            \is_bool($valeur) => $valeur ? 'oui' : 'non',
            // Un code-barres saisi comme nombre ne doit pas devenir « 3.4E+12 ».
            \is_float($valeur) && floor($valeur) === $valeur => number_format($valeur, 0, '', ''),
            default => trim((string) $valeur),
        };
    }

    private static function normaliser(string $texte): string
    {
        $texte = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($texte)));

        return (string) preg_replace('/[^a-z0-9]+/', '', $texte);
    }
}
