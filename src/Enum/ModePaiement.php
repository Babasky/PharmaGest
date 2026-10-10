<?php

namespace App\Enum;

/**
 * Modes de paiement d'une vente (VE-04). Une vente peut être réglée par plusieurs modes (paiement mixte).
 * La carte bancaire n'est plus proposée à la caisse : elle reste lisible dans l'historique des ventes déjà encaissées.
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
        return [self::Especes, self::OrangeMoney, self::MoovMoney, self::Wave];
    }

    /**
     * Modes mobiles saisis à côté des espèces (paiement mixte).
     *
     * @return list<self>
     */
    public static function mobiles(): array
    {
        return [self::OrangeMoney, self::MoovMoney, self::Wave];
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
