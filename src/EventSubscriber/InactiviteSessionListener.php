<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Déconnecte après une période d'inactivité (30 min par défaut, § 6 Sécurité ; délai de la caisse réglé par le
 * propriétaire, voir {@see DelaiInactiviteListener}).
 *
 * S'exécute avant le pare-feu : la session expirée est vidée, l'utilisateur redevient anonyme
 * et le contrôle d'accès le renvoie vers la page de connexion.
 */
final class InactiviteSessionListener
{
    /** Délai propre à l'utilisateur (secondes), mémorisé en session par {@see DelaiInactiviteListener}. */
    public const CLE_DELAI = '_inactivite_delai';

    public function __construct(
        #[Autowire('%app.session_inactivite%')]
        private readonly int $delaiSecondes,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function __invoke(RequestEvent $event): void
    {
        $requete = $event->getRequest();
        if (!$event->isMainRequest() || !$requete->hasPreviousSession()) {
            return;
        }

        $session = $requete->getSession();
        $session->start();
        $derniereUtilisation = $session->getMetadataBag()->getLastUsed();

        $delai = (int) $session->get(self::CLE_DELAI, $this->delaiSecondes);

        if ($derniereUtilisation > 0 && time() - $derniereUtilisation > $delai && $session->has('_security_main')) {
            $session->invalidate();
            if ($session instanceof \Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('warning', 'Votre session a expiré après une période d\'inactivité. Reconnectez-vous.');
            }
        }
    }
}
