<?php

namespace App\Enum;

/**
 * Modes de paiement d'une vente (VE-04). Une vente peut être réglée par plusieurs modes (paiement mixte).
 */
enum ModePaiement: string
{
    case Especes = 'especes';
    case OrangeMoney = 'orange_money';
    case MoovMoney = 'moov_money';
    case Carte = 'carte';

    public function libelle(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::OrangeMoney => 'Orange Money',
            self::MoovMoney => 'Moov Money',
            self::Carte => 'Carte bancaire',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Especes => 'cash',
            self::OrangeMoney, self::MoovMoney => 'phone',
            self::Carte => 'credit-card',
        };
    }
}
