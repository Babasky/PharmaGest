<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Mémorise la date de dernière connexion.
 */
final class DerniereConnexionListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $horloge,
    ) {
    }

    #[AsEventListener]
    public function __invoke(LoginSuccessEvent $event): void
    {
        $utilisateur = $event->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $utilisateur->setDerniereConnexion($this->horloge->now());
            $this->em->flush();
        }
    }
}
