<?php

namespace App\Tenant;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Active le filtre tenant au début de chaque requête, juste après le pare-feu.
 * Seul l'espace super admin (/admin) travaille sans filtre.
 */
final class TenantRequestListener
{
    public const PREFIXE_ADMIN = '/admin';

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $chemin = $event->getRequest()->getPathInfo();
        $utilisateur = $this->tenantContext->getUtilisateur();

        if ((self::PREFIXE_ADMIN === $chemin || str_starts_with($chemin, self::PREFIXE_ADMIN.'/')) && true === $utilisateur?->isSuperAdmin()) {
            $this->tenantContext->desactiverFiltre();

            return;
        }

        $this->tenantContext->activerFiltre($this->tenantContext->getPharmacie());
    }
}
