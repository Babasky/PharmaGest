<?php

namespace App\Controller;

use App\Service\AbonnementService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class PharmacieCouranteController extends AbstractAppController
{
    /**
     * Bascule vers une autre pharmacie du même propriétaire (offre Premium, § 2).
     */
    #[Route('/pharmacie/basculer', name: 'app_pharmacie_basculer', methods: ['POST'])]
    #[IsCsrfTokenValid('basculer-pharmacie')]
    public function basculer(Request $requete): Response
    {
        if (!$this->tenantContext->basculer($requete->request->getInt('pharmacie'))) {
            throw $this->createNotFoundException();
        }
        $this->addFlash('success', \sprintf('Vous travaillez maintenant sur %s.', $this->pharmacie()->getNom()));

        return $this->redirectToRoute('app_tableau_de_bord');
    }

    /**
     * Page d'information quand l'accès est coupé (§ 3.2 étapes 7 et 8).
     *
     * @param array{nom: string, adresse: string, telephone: string, email: string} $editeur
     */
    #[Route('/compte-suspendu', name: 'app_compte_suspendu', methods: ['GET'])]
    public function compteSuspendu(AbonnementService $abonnements, #[Autowire('%app.editeur%')] array $editeur): Response
    {
        $pharmacie = $this->tenantContext->getPharmacie();
        if (null === $pharmacie || $abonnements->etat($pharmacie)->statut->permetAcces()) {
            return $this->redirectToRoute('app_tableau_de_bord');
        }

        return $this->render('securite/compte_suspendu.html.twig', [
            'pharmacie' => $pharmacie,
            'etat' => $abonnements->etat($pharmacie),
            'editeur' => $editeur,
        ], new Response(status: Response::HTTP_FORBIDDEN));
    }
}
