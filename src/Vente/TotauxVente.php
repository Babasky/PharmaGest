<?php

namespace App\Vente;

/**
 * Montants d'une vente, en FCFA entiers (RG-01).
 *
 * Vente AMO (RG-07, RG-09) : la part AMO se calcule sur le prix plein des lignes remboursables ;
 * la remise ne porte que sur la part assuré. Exemple du § 5 : 20 000 dont 18 000 remboursables, taux 70 %,
 * remise 10 % → part AMO 12 600, part assuré 7 400, remise 740, encaissé 6 660.
 */
final class TotauxVente
{
    public function __construct(
        /** Somme des lignes au prix plein. */
        public readonly int $totalBrut,
        public readonly int $remiseLignes,
        public readonly int $remiseGlobale,
        /** Total des lignes remboursables (0 hors AMO). */
        public readonly int $baseAmo,
        public readonly int $partAmo,
        /** Montant sur lequel portent les remises : total brut, ou part assuré pour l'AMO. */
        public readonly int $baseRemise,
        /** Taux de remise le plus élevé parmi les remises saisies et la remise totale, en % (RG-08). */
        public readonly float $tauxRemiseMaximal,
    ) {
    }

    public function remiseTotale(): int
    {
        return $this->remiseLignes + $this->remiseGlobale;
    }

    /** Ce que paie le client ce jour : total après remise, part assuré seulement pour l'AMO (RG-10). */
    public function aEncaisser(): int
    {
        return $this->totalBrut - $this->partAmo - $this->remiseTotale();
    }

    /** Valeur de la vente après remise (part AMO comprise). */
    public function totalNet(): int
    {
        return $this->totalBrut - $this->remiseTotale();
    }

    public function partAssureAvantRemise(): int
    {
        return $this->totalBrut - $this->partAmo;
    }
}
