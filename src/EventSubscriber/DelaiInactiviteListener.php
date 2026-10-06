<?php

namespace App\EventSubscriber;

use App\Repository\SessionCaisseRepository;
use App\Service\ParametresPharmacie;
use App\Tenant\TenantContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Choisit le délai d'inactivité de l'utilisateur connecté pour les requêtes suivantes : celui de la caisse, réglé
 * par le propriétaire, tant qu'il a une session de caisse ouverte ; sinon le délai général
 * ({@see InactiviteSessionListener}).
 */
final class DelaiInactiviteListener
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SessionCaisseRepository $sessionsCaisse,
        private readonly ParametresPharmacie $parametres,
    ) {
    }

    // Après le pare-feu (8) et l'activation du filtre tenant (6).
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function __invoke(RequestEvent $event): void
    {
        $requete = $event->getRequest();
        if (!$event->isMainRequest() || !$requete->hasSession()) {
            return;
        }
        $utilisateur = $this->tenantContext->getUtilisateur();
        $pharmacie = $this->tenantContext->getPharmacie();
        $session = $requete->getSession();

        if (null !== $utilisateur && null !== $pharmacie && null !== $this->sessionsCaisse->ouverteDe($utilisateur)) {
            $session->set(InactiviteSessionListener::CLE_DELAI, $this->parametres->pour($pharmacie)->getInactiviteCaisse() * 60);
        } elseif ($session->has(InactiviteSessionListener::CLE_DELAI)) {
            $session->remove(InactiviteSessionListener::CLE_DELAI);
        }
    }
}
