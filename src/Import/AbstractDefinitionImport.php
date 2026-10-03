<?php

namespace App\Import;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Outils communs aux définitions d'import : validation de l'entité et détection des doublons dans le fichier.
 */
abstract class AbstractDefinitionImport implements DefinitionImport
{
    /** @var array<string, int> clé de dédoublonnage => numéro de la première ligne */
    private array $vus = [];

    public function __construct(
        protected readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validateur,
    ) {
    }

    public function reinitialiser(): void
    {
        $this->vus = [];
    }

    /**
     * @param list<string> $erreurs
     */
    protected function dejaVu(string $cle, int $numero, string $libelle, array &$erreurs): void
    {
        $cle = mb_strtolower($cle);
        if (isset($this->vus[$cle])) {
            $erreurs[] = \sprintf('%s apparaît déjà à la ligne %d du fichier.', $libelle, $this->vus[$cle]);

            return;
        }
        $this->vus[$cle] = $numero;
    }

    /**
     * @param list<string> $erreurs
     */
    protected function valider(object $entite, array &$erreurs): void
    {
        foreach ($this->validateur->validate($entite) as $violation) {
            $erreurs[] = (string) $violation->getMessage();
        }
    }

    /**
     * @param list<string> $erreurs
     * @param list<string> $remarques
     *
     * @return array{0: LigneImport, 1: object|null}
     */
    protected function resultat(int $numero, string $libelle, object $entite, array $erreurs, bool $simulation, array $remarques = []): array
    {
        $existante = $this->em->contains($entite);

        // Une entité déjà en base modifiée en mémoire ne doit jamais être enregistrée par erreur :
        // on annule les changements si la ligne est rejetée ou si l'on ne fait qu'analyser.
        if ($existante && ([] !== $erreurs || $simulation)) {
            $this->em->refresh($entite);
        }

        if ([] !== $erreurs) {
            return [new LigneImport($numero, LigneImport::ERREUR, $libelle, array_values(array_unique($erreurs))), null];
        }

        return [new LigneImport($numero, $existante ? LigneImport::MISE_A_JOUR : LigneImport::CREATION, $libelle, $remarques), $entite];
    }
}
