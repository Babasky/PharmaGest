<?php

namespace App\Enum;

/**
 * Nature d'un mouvement de stock (ST-04). Toute variation de la quantité d'un lot passe par un mouvement.
 */
enum TypeMouvement: string
{
    case EntreeManuelle = 'entree_manuelle';
    case Reception = 'reception';
    case Vente = 'vente';
    case RetourClient = 'retour_client';
    case Annulation = 'annulation';
    case Ajustement = 'ajustement';
    case Destruction = 'destruction';
    case RetourFournisseur = 'retour_fournisseur';
    case Transfert = 'transfert';

    public function libelle(): string
    {
        return match ($this) {
            self::EntreeManuelle => 'Entrée de stock',
            self::Reception => 'Réception',
            self::Vente => 'Vente',
            self::RetourClient => 'Retour client',
            self::Annulation => 'Annulation de vente',
            self::Ajustement => 'Ajustement',
            self::Destruction => 'Destruction',
            self::RetourFournisseur => 'Retour fournisseur',
            self::Transfert => 'Transfert',
        };
    }
}
