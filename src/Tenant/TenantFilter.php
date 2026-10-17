<?php

namespace App\Tenant;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Ajoute « pharmacie_id = :pharmacie » à toute requête portant sur une entité {@see TenantAwareInterface}.
 *
 * Fermé par défaut : sans paramètre, aucune ligne n'est renvoyée (seules les valeurs communes d'un référentiel
 * partagé, {@see TenantPartageInterface}).
 */
final class TenantFilter extends SQLFilter
{
    public const NOM = 'tenant';
    public const PARAMETRE = 'pharmacie';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(TenantAwareInterface::class)) {
            return '';
        }

        $partage = $targetEntity->getReflectionClass()->implementsInterface(TenantPartageInterface::class);
        if (!$this->hasParameter(self::PARAMETRE)) {
            return $partage ? \sprintf('%s.pharmacie_id IS NULL', $targetTableAlias) : '1 = 0';
        }
        if ($partage) {
            return \sprintf('(%1$s.pharmacie_id = %2$s OR %1$s.pharmacie_id IS NULL)', $targetTableAlias, $this->getParameter(self::PARAMETRE));
        }

        return \sprintf('%s.pharmacie_id = %s', $targetTableAlias, $this->getParameter(self::PARAMETRE));
    }
}
