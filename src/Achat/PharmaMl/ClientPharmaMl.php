<?php

namespace App\Achat\PharmaMl;

use App\Entity\Commande;

/**
 * Transmission d'une commande au serveur Pharma-ML d'un répartiteur.
 *
 * La norme Pharma-ML (messages XML de commande, de disponibilité et d'accusé de réception) est diffusée par la
 * Chambre syndicale de la répartition pharmaceutique aux éditeurs, sous accord de confidentialité. Tant que
 * SoftPharm n'en dispose pas, {@see PharmaMlEnAttente} répond « indisponible » et la commande part par email.
 */
interface ClientPharmaMl
{
    /**
     * @return string référence de la commande chez le répartiteur (accusé de réception)
     *
     * @throws PharmaMlIndisponible la transmission Pharma-ML n'est pas possible : passer par un autre canal
     */
    public function transmettre(Commande $commande): string;
}
