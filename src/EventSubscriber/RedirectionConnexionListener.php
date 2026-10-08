<?php

namespace App\EventSubscriber;

use App\Security\PageAccueil;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * À la connexion, le vendeur part sur l'écran de vente et le caissier sur l'encaissement, au lieu du
 * tableau de bord. Une page protégée demandée avant la connexion reste la destination, sauf l'accueil « / ».
 */
final class RedirectionConnexionListener
{
    public function __construct(
        private readonly PageAccueil $pageAccueil,
        private readonly UrlGeneratorInterface $routeur,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function __invoke(LoginSuccessEvent $event): void
    {
        $reponse = $event->getResponse();
        if (!$reponse instanceof RedirectResponse || 'main' !== $event->getFirewallName()) {
            return;
        }
        $tableauDeBord = $this->routeur->generate('app_tableau_de_bord');
        $route = $this->pageAccueil->route();
        if (\in_array($route, ['app_tableau_de_bord', 'admin'], true) || parse_url($reponse->getTargetUrl(), \PHP_URL_PATH) !== $tableauDeBord) {
            return;
        }
        $reponse->setTargetUrl($this->routeur->generate($route));
    }
}
