<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de sécurité de toutes les réponses (revue OWASP, docs/securite.md).
 *
 * La politique de contenu reste compatible avec l'importmap d'AssetMapper (script en ligne) : elle interdit
 * l'affichage dans un cadre d'un autre site, les plugins et l'envoi de formulaires vers un autre site.
 * HSTS n'est envoyé que sur une connexion HTTPS (la production est servie exclusivement en HTTPS).
 */
final class EnTetesSecuriteListener
{
    private const EN_TETES = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=()',
        'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'",
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $reponse = $event->getResponse();
        foreach (self::EN_TETES as $nom => $valeur) {
            if (!$reponse->headers->has($nom)) {
                $reponse->headers->set($nom, $valeur);
            }
        }
        if ($event->getRequest()->isSecure()) {
            $reponse->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
