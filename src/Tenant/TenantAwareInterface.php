<?php

namespace App\Tenant;

use App\Entity\Pharmacie;

/**
 * Entité métier rattachée à une pharmacie (le tenant).
 *
 * Toute entité qui l'implémente est automatiquement :
 *  - filtrée par {@see TenantFilter} sur la pharmacie courante ;
 *  - rattachée à la pharmacie courante à sa création ({@see TenantAssignationListener}).
 */
interface TenantAwareInterface
{
    public function getPharmacie(): ?Pharmacie;

    public function setPharmacie(Pharmacie $pharmacie): static;
}
