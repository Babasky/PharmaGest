<?php

namespace App\Enum;

/**
 * Cycle de vie d'un compte pharmacie (cahier des charges § 3.2).
 */
enum StatutAbonnement: string
{
    case Essai = 'essai';
    case Actif = 'actif';
    /** Actif, mais l'échéance tombe dans les 30 jours. */
    case Alerte = 'alerte';
    /** Échéance dépassée depuis 7 jours au plus : tout fonctionne encore. */
    case Grace = 'grace';
    /** Lecture seule. */
    case Expire = 'expire';
    /** Plus aucun accès. */
    case Suspendu = 'suspendu';
    /** Plus aucun accès, données archivées. */
    case Archive = 'archive';

    public function libelle(): string
    {
        return match ($this) {
            self::Essai => "Période d'essai",
            self::Actif => 'Actif',
            self::Alerte => 'Échéance proche',
            self::Grace => 'Période de grâce',
            self::Expire => 'Expiré',
            self::Suspendu => 'Suspendu',
            self::Archive => 'Archivé',
        };
    }

    /** Couleur Bootstrap du badge. */
    public function couleur(): string
    {
        return match ($this) {
            self::Essai => 'info',
            self::Actif => 'success',
            self::Alerte => 'warning',
            self::Grace, self::Expire => 'danger',
            self::Suspendu, self::Archive => 'dark',
        };
    }

    public function permetEcriture(): bool
    {
        return \in_array($this, [self::Essai, self::Actif, self::Alerte, self::Grace], true);
    }

    public function permetAcces(): bool
    {
        return !\in_array($this, [self::Suspendu, self::Archive], true);
    }
}
