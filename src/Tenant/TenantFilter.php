<?php

namespace App\Tenant;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Ajoute « pharmacie_id = :pharmacie » à toute requête portant sur une entité {@see TenantAwareInterface}.
 *
 * Fermé par défaut : sans paramètre, aucune ligne n'est renvoyée.
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

        if (!$this->hasParameter(self::PARAMETRE)) {
            return '1 = 0';
        }

        return \sprintf('%s.pharmacie_id = %s', $targetTableAlias, $this->getParameter(self::PARAMETRE));
    }
}
