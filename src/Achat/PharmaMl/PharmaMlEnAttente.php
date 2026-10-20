<?php

namespace App\Achat\PharmaMl;

use App\Entity\Commande;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Client Pharma-ML en attente de la spécification de la norme et de l'accord du répartiteur : il ne transmet rien.
 */
#[AsAlias(ClientPharmaMl::class)]
final class PharmaMlEnAttente implements ClientPharmaMl
{
    public function transmettre(Commande $commande): string
    {
        throw new PharmaMlIndisponible('La transmission Pharma-ML n\'est pas encore active dans SoftPharm.');
    }
}
