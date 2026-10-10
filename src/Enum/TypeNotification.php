<?php

namespace App\Enum;

/**
 * Sujets du centre de notifications (NO-01).
 */
enum TypeNotification: string
{
    case Rupture = 'rupture';
    case Peremption = 'peremption';
    case Perime = 'perime';
    case Abonnement = 'abonnement';
    case Bordereau = 'bordereau';
    case Transfert = 'transfert';

    public function libelle(): string
    {
        return match ($this) {
            self::Rupture => 'Rupture de stock',
            self::Peremption => 'Péremption proche',
            self::Perime => 'Lot périmé',
            self::Abonnement => 'Abonnement',
            self::Bordereau => 'Bordereau AMO impayé',
            self::Transfert => 'Transfert à réceptionner',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Rupture => 'box-seam',
            self::Peremption => 'hourglass-split',
            self::Perime => 'exclamation-octagon',
            self::Abonnement => 'patch-exclamation',
            self::Bordereau => 'shield-exclamation',
            self::Transfert => 'arrow-left-right',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::Rupture, self::Abonnement => 'warning',
            self::Peremption, self::Bordereau, self::Transfert => 'primary',
            self::Perime => 'danger',
        };
    }
}
