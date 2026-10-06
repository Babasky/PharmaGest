<?php

namespace App\Twig;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Tenant\TenantContext;
use Twig\Attribute\AsTwigFunction;

/**
 * Cloche de la barre supérieure (NO-01).
 */
final class NotificationExtension
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly NotificationRepository $notifications,
    ) {
    }

    #[AsTwigFunction('notifications_non_lues')]
    public function nonLues(): int
    {
        $utilisateur = $this->tenantContext->getUtilisateur();

        return null === $utilisateur || null === $this->tenantContext->getPharmacie() ? 0 : $this->notifications->compterNonLues($utilisateur);
    }

    /**
     * @return list<Notification>
     */
    #[AsTwigFunction('notifications_recentes')]
    public function recentes(int $nombre = 5): array
    {
        $utilisateur = $this->tenantContext->getUtilisateur();

        return null === $utilisateur || null === $this->tenantContext->getPharmacie() ? [] : $this->notifications->dernieres($utilisateur, $nombre);
    }
}
