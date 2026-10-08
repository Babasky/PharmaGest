<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Nature d'un organisme de prise en charge (tiers payant). L'AMO applique son propre prix de vente
 * par médicament ({@see \App\Entity\Produit::getPrixVenteAmo()}) ; une autre assurance (privée, ONG,
 * mutuelle d'entreprise) applique son taux au prix de vente de la pharmacie.
 */
enum TypeOrganisme: string implements TranslatableInterface
{
    case Amo = 'amo';
    case Assurance = 'assurance';

    public function libelle(): string
    {
        return match ($this) {
            self::Amo => 'AMO',
            self::Assurance => 'Autre assurance',
        };
    }

    /** Libellé affiché par les listes de choix (formulaires Symfony et EasyAdmin). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->libelle();
    }

    public function appliqueTarifAmo(): bool
    {
        return self::Amo === $this;
    }
}
