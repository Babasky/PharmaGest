<?php

namespace App\Tenant;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Rattache automatiquement toute nouvelle entité tenant à la pharmacie courante,
 * et refuse d'écrire une donnée dans une autre pharmacie que la pharmacie courante.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TenantAssignationListener
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entite = $args->getObject();
        if (!$entite instanceof TenantAwareInterface) {
            return;
        }

        $courante = $this->tenantContext->getPharmacie();

        if (null === $entite->getPharmacie()) {
            if (null !== $courante) {
                $entite->setPharmacie($courante);
            } elseif (!$entite instanceof TenantOptionnelInterface) {
                throw new \LogicException(\sprintf('Impossible d\'enregistrer %s sans pharmacie courante.', $entite::class));
            }

            return;
        }

        if (null !== $courante && $entite->getPharmacie() !== $courante) {
            throw new \LogicException(\sprintf('Tentative d\'écriture de %s dans une autre pharmacie que la pharmacie courante.', $entite::class));
        }
    }
}
