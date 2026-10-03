<?php

namespace App\Tenant;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Refuse d'enregistrer une donnée qui pointe vers une donnée d'une autre pharmacie
 * (ex. un produit rangé dans la catégorie d'une autre officine), même si un formulaire a été contourné.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class TenantCoherenceListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entite) {
            if (!$entite instanceof TenantAwareInterface || null === $entite->getPharmacie()) {
                continue;
            }

            $metadonnees = $em->getClassMetadata($entite::class);
            foreach ($metadonnees->getAssociationNames() as $association) {
                if (!$metadonnees->isSingleValuedAssociation($association)) {
                    continue;
                }
                $cible = $metadonnees->getFieldValue($entite, $association);
                if ($cible instanceof TenantAwareInterface && null !== $cible->getPharmacie()
                    && $cible->getPharmacie()->getId() !== $entite->getPharmacie()->getId()) {
                    throw new \LogicException(\sprintf('%s::%s pointe vers une donnée d\'une autre pharmacie.', $entite::class, $association));
                }
            }
        }
    }
}
