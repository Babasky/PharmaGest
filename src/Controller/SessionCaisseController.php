<?php

namespace App\Controller;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Pdf\VentePdf;
use App\Repository\SessionCaisseRepository;
use App\Vente\GestionCaisse;
use App\Vente\VenteException;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sessions de caisse : clôture avec comptage des espèces (FI-06) et rapport Z (FI-07).
 * Chacun voit et clôture sa session ; le propriétaire et l'adjoint voient et peuvent clôturer toutes les sessions.
 */
#[Route('/caisse/sessions')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class SessionCaisseController extends AbstractAppController
{
    public function __construct(private readonly GestionCaisse $caisse)
    {
    }

    #[Route('', name: 'app_session_caisse_index', methods: ['GET'])]
    public function index(SessionCaisseRepository $sessions, #[MapQueryParameter] int $page = 1): Response
    {
        $tout = $this->isGranted(Utilisateur::ROLE_ADJOINT);

        return $this->render('session_caisse/index.html.twig', [
            'sessions' => $sessions->liste($tout ? null : $this->utilisateur(), $page),
            'tout' => $tout,
        ]);
    }

    /** Clôture de sa propre session ouverte. */
    #[Route('/cloture', name: 'app_caisse_cloture', methods: ['GET'])]
    public function maCloture(): Response
    {
        $session = $this->caisse->sessionOuverte($this->utilisateur());
        if (null === $session) {
            $this->addFlash('info', 'Votre caisse n\'est pas ouverte.');

            return $this->redirectToRoute('app_caisse');
        }

        return $this->redirectToRoute('app_session_caisse_voir', ['id' => $session->getId()]);
    }

    #[Route('/{id}', name: 'app_session_caisse_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(SessionCaisse $session): Response
    {
        $this->exigerAcces($session);

        return $this->render('session_caisse/voir.html.twig', [
            'session' => $session,
            'synthese' => $this->caisse->synthese($session),
            'coupures' => SessionCaisse::COUPURES,
        ]);
    }

    #[Route('/{id}/cloturer', name: 'app_session_caisse_cloturer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"cloture-" ~ args["session"].getId()'))]
    public function cloturer(SessionCaisse $session, Request $requete): Response
    {
        $this->exigerAcces($session);
        try {
            /** @var array<array-key, mixed> $comptage */
            $comptage = $requete->getPayload()->all('comptage');
            $this->caisse->cloturer($session, $comptage, (string) $requete->getPayload()->get('justification'));
            $this->addFlash(0 === $session->getEcart() ? 'success' : 'warning', 0 === $session->getEcart()
                ? \sprintf('Session %s clôturée, sans écart.', $session->getNumero())
                : \sprintf('Session %s clôturée avec un écart de %s.', $session->getNumero(), \App\Util\Fcfa::format((int) $session->getEcart())));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_session_caisse_voir', ['id' => $session->getId()]);
    }

    #[Route('/{id}/rapport-z', name: 'app_session_caisse_rapport_z', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function rapportZ(SessionCaisse $session, VentePdf $pdf): Response
    {
        $this->exigerAcces($session);
        if ($session->estOuverte()) {
            throw $this->createNotFoundException();
        }

        return $pdf->reponseRapportZ($this->caisse->synthese($session));
    }

    private function exigerAcces(SessionCaisse $session): void
    {
        $this->exigerMemePharmacie($session);
        if (!$this->isGranted(Utilisateur::ROLE_ADJOINT) && $session->getUtilisateur()->getId() !== $this->utilisateur()->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
