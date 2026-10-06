<?php

namespace App\EventSubscriber;

use App\Service\AbonnementService;
use App\Tenant\TenantContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Applique l'état de l'abonnement de la pharmacie courante (§ 3.2) :
 *  - suspendu ou archivé : plus aucun accès, page d'information ;
 *  - expiré : lecture seule, toute écriture est refusée (R-02).
 */
final class AccesAbonnementListener
{
    /** Routes toujours accessibles, même en lecture seule ou suspendu. */
    private const ROUTES_LIBRES = ['app_deconnexion', 'app_compte_suspendu', 'app_pharmacie_basculer', 'app_notification_tout_lire'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AbonnementService $abonnements,
        private readonly UrlGeneratorInterface $routeur,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
    public function __invoke(RequestEvent $event): void
    {
        $requete = $event->getRequest();
        $route = (string) $requete->attributes->get('_route');
        if (!$event->isMainRequest() || \in_array($route, self::ROUTES_LIBRES, true) || str_starts_with($route, '_')) {
            return;
        }

        $pharmacie = $this->tenantContext->getPharmacie();
        if (null === $pharmacie) {
            return;
        }

        $etat = $this->abonnements->etat($pharmacie);

        if (!$etat->statut->permetAcces()) {
            $event->setResponse(new RedirectResponse($this->routeur->generate('app_compte_suspendu')));

            return;
        }

        if (!$etat->statut->permetEcriture() && !$requete->isMethodSafe()) {
            $session = $requete->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('danger', 'Votre abonnement a expiré : PharmaGest est en lecture seule. Vous pouvez consulter et exporter vos données ; contactez l\'éditeur pour renouveler.');
            }
            $retour = $requete->headers->get('referer');
            $event->setResponse(new RedirectResponse(
                null !== $retour && str_starts_with($retour, $requete->getSchemeAndHttpHost()) ? $retour : $this->routeur->generate('app_tableau_de_bord'),
            ));
        }
    }
}
