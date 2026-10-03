<?php

namespace App\Tenant;

/**
 * Marque une entité tenant dont la pharmacie peut rester vide (ex. : journal d'audit des actions plateforme).
 * Sans ce marqueur, persister une entité tenant sans pharmacie est une erreur.
 */
interface TenantOptionnelInterface extends TenantAwareInterface
{
}
