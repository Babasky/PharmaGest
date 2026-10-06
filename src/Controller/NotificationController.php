<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Repository\NotificationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Centre de notifications de l'utilisateur dans la pharmacie courante (NO-01).
 */
#[Route('/notifications')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class NotificationController extends AbstractAppController
{
    public const CSRF_TOUT_LIRE = 'notifications-tout-lire';

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly ClockInterface $horloge,
    ) {
    }

    #[Route('', name: 'app_notification_index', methods: ['GET'])]
    public function index(#[MapQueryParameter] bool $nonLues = false, #[MapQueryParameter] int $page = 1): Response
    {
        $utilisateur = $this->utilisateur();

        return $this->render('notification/index.html.twig', [
            'notifications' => $this->notifications->liste($utilisateur, $nonLues, $page),
            'non_lues' => $nonLues,
            'nombre_non_lues' => $this->notifications->compterNonLues($utilisateur),
        ]);
    }

    /**
     * Marque la notification comme lue et ouvre la page concernée.
     */
    #[Route('/{id}', name: 'app_notification_ouvrir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ouvrir(Notification $notification): Response
    {
        $this->exigerMemePharmacie($notification);
        if ($notification->getUtilisateur()->getId() !== $this->utilisateur()->getId()) {
            throw $this->createNotFoundException();
        }
        $notification->marquerLue($this->horloge->now());
        $this->entityManager->flush();

        $lien = (string) $notification->getLien();
        // Seulement un chemin de l'application (jamais une autre adresse).
        if (!str_starts_with($lien, '/') || str_starts_with($lien, '//')) {
            return $this->redirectToRoute('app_notification_index');
        }

        return $this->redirect($lien);
    }

    #[Route('/tout-lire', name: 'app_notification_tout_lire', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_TOUT_LIRE)]
    public function toutLire(): Response
    {
        $nombre = $this->notifications->marquerToutesLues($this->utilisateur(), $this->pharmacie(), $this->horloge->now());
        $this->addFlash('success', 0 === $nombre ? 'Aucune notification non lue.' : \sprintf('%d notification(s) marquée(s) comme lue(s).', $nombre));

        return $this->redirectToRoute('app_notification_index');
    }
}
