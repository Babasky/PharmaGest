<?php

namespace App\Tenant;

/**
 * Référentiel partagé : une valeur sans pharmacie est commune à toutes (créée par l'éditeur), une valeur avec
 * pharmacie n'est visible que de celle-ci (ex. : assurance ajoutée par une pharmacie). Le filtre tenant laisse
 * donc passer les deux, et une nouvelle valeur n'est rattachée à la pharmacie courante que si on le demande.
 */
interface TenantPartageInterface extends TenantOptionnelInterface
{
}
