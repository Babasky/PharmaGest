<?php

namespace App\Enum;

/**
 * Moyen par lequel une recette entre ou une dépense sort (FI-01, FI-04). Les modes de la caisse
 * ({@see ModePaiement}) gardent la même valeur.
 */
enum ModeReglement: string
{
    case Especes = 'especes';
    case OrangeMoney = 'orange_money';
    case MoovMoney = 'moov_money';
    case Wave = 'wave';
    case Carte = 'carte';
    case Virement = 'virement';
    case Cheque = 'cheque';

    public function libelle(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::OrangeMoney => 'Orange Money',
            self::MoovMoney => 'Moov Money',
            self::Wave => 'Wave',
            self::Carte => 'Carte bancaire',
            self::Virement => 'Virement bancaire',
            self::Cheque => 'Chèque',
        };
    }

    /**
     * Modes proposés pour saisir une recette ou une dépense.
     *
     * @return list<self>
     */
    public static function proposes(): array
    {
        return self::cases();
    }

    public static function depuisPaiement(ModePaiement $mode): self
    {
        return self::from($mode->value);
    }
}
