<?php

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Page d'arrivée après la connexion, selon le rôle : le vendeur arrive sur l'écran de vente, le caissier
 * sur l'encaissement, le propriétaire et l'adjoint sur le tableau de bord, le super admin sur son espace.
 */
final class PageAccueil
{
    public function __construct(private readonly Security $security)
    {
    }

    public function route(): string
    {
        return match (true) {
            $this->security->isGranted(Utilisateur::ROLE_SUPER_ADMIN) => 'admin',
            $this->security->isGranted(Utilisateur::ROLE_ADJOINT) => 'app_tableau_de_bord',
            $this->security->isGranted(Utilisateur::ROLE_VENDEUR) => 'app_caisse',
            $this->security->isGranted(Utilisateur::ROLE_CAISSIER) => 'app_encaissement_index',
            default => 'app_tableau_de_bord',
        };
    }
}
