<?php

namespace App\Twig;

use App\Reporting\ExportRapport;
use App\Util\Fcfa;
use App\Util\Telephone;
use Twig\Attribute\AsTwigFilter;

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
}
