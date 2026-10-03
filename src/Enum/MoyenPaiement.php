<?php

namespace App\Enum;

enum MoyenPaiement: string
{
    case Especes = 'especes';
    case Virement = 'virement';
    case OrangeMoney = 'orange_money';
    case MoovMoney = 'moov_money';
    case Cheque = 'cheque';

    public function libelle(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::Virement => 'Virement bancaire',
            self::OrangeMoney => 'Orange Money',
            self::MoovMoney => 'Moov Money',
            self::Cheque => 'Chèque',
        };
    }
}
