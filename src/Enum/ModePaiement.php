<?php

namespace App\Enum;

/**
 * Modes de paiement d'une vente (VE-04). Une vente peut être réglée par plusieurs modes (paiement mixte).
 * La carte bancaire est saisie à la main (montant et référence du ticket du terminal), sans lien avec le terminal.
 */
enum ModePaiement: string
{
    case Especes = 'especes';
    case OrangeMoney = 'orange_money';
    case MoovMoney = 'moov_money';
    case Wave = 'wave';
    case Carte = 'carte';

    public function libelle(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::OrangeMoney => 'Orange Money',
            self::MoovMoney => 'Moov Money',
            self::Wave => 'Wave',
            self::Carte => 'Carte bancaire',
        };
    }

    /**
     * Modes proposés pour un nouvel encaissement.
     *
     * @return list<self>
     */
    public static function proposes(): array
    {
        return [self::Especes, self::OrangeMoney, self::MoovMoney, self::Wave, self::Carte];
    }

    /**
     * Modes saisis à côté des espèces (paiement mixte) : paiements mobiles et carte bancaire.
     *
     * @return list<self>
     */
    public static function horsEspeces(): array
    {
        return [self::OrangeMoney, self::MoovMoney, self::Wave, self::Carte];
    }

    public function icone(): string
    {
        return match ($this) {
            self::Especes => 'cash',
            self::OrangeMoney, self::MoovMoney, self::Wave => 'phone',
            self::Carte => 'credit-card',
        };
    }
}
