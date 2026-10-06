<?php

namespace App\Twig;

use App\Reporting\ExportRapport;
use App\Service\AuditLogger;
use App\Util\Fcfa;
use App\Util\Telephone;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class AppExtension
{
    /**
     * {{ 12500|fcfa }} => « 12 500 FCFA » ; {{ 12500|fcfa(false) }} => « 12 500 ».
     */
    #[AsTwigFilter('fcfa')]
    public function fcfa(int|float|string|null $montant, bool $avecDevise = true): string
    {
        return Fcfa::format($montant, $avecDevise);
    }

    /**
     * {{ '76123456'|telephone }} => « +223 76 12 34 56 ».
     */
    #[AsTwigFilter('telephone')]
    public function telephone(?string $numero): string
    {
        return Telephone::format($numero);
    }

    /**
     * Cellule d'un tableau de rapport : {{ valeur|cellule('montant') }}.
     */
    #[AsTwigFilter('cellule')]
    public function cellule(mixed $valeur, string $type): string
    {
        return ExportRapport::formater($valeur, $type);
    }

    #[AsTwigFunction('audit_libelle')]
    public function auditLibelle(string $action): string
    {
        return AuditLogger::libelle($action);
    }

    /**
     * Valeurs avant / après d'une entrée du journal, lisibles : « motif : Casse · quantité : 3 ».
     *
     * @param array<string, mixed> $valeurs
     */
    #[AsTwigFilter('audit_valeurs')]
    public function auditValeurs(array $valeurs): string
    {
        $morceaux = [];
        foreach ($valeurs as $cle => $valeur) {
            // prixVente, prix_vente => « prix vente »
            $libelle = mb_strtolower(trim((string) preg_replace(['/(?<!^)([A-Z])/', '/_+/'], [' $1', ' '], (string) $cle)));
            $texte = match (true) {
                null === $valeur => '—',
                \is_bool($valeur) => $valeur ? 'oui' : 'non',
                \is_scalar($valeur) => (string) $valeur,
                default => (string) json_encode($valeur, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            };
            $morceaux[] = $libelle.' : '.$texte;
        }

        return implode(' · ', $morceaux);
    }
}
