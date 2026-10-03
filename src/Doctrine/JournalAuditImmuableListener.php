<?php

namespace App\Doctrine;

use App\Entity\JournalAudit;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Le journal d'audit est en écriture seule (AU-02) : ni modification, ni suppression.
 */
#[AsEntityListener(event: Events::preUpdate, entity: JournalAudit::class)]
#[AsEntityListener(event: Events::preRemove, entity: JournalAudit::class)]
final class JournalAuditImmuableListener
{
    public function preUpdate(): void
    {
        throw new \LogicException("Une entrée du journal d'audit ne se modifie pas.");
    }

    public function preRemove(): void
    {
        throw new \LogicException("Une entrée du journal d'audit ne se supprime pas.");
    }
}
