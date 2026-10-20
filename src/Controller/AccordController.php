<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\VenteRepository;
use App\Vente\AccordProprietaire;
use App\Vente\VenteException;
use App\Vente\VenteService;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accords demandés depuis la caisse (vente sans ordonnance d'un produit qui l'exige, remise hors plafond) :
 * le propriétaire voit le panier et l'accepte ou le refuse depuis son compte, sans communiquer son code PIN.
 */
#[Route('/accords')]
#[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
final class AccordController extends AbstractAppController
{
    #[Route('', name: 'app_accord_index', methods: ['GET'])]
    public function index(VenteRepository $ventes): Response
    {
        return $this->render('accord/index.html.twig', ['ventes' => $ventes->accordsEnAttente()]);
    }

    #[Route('/{id}', name: 'app_accord_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Vente $vente, VenteService $ventes): Response
    {
        $this->exigerMemePharmacie($vente);
        if (null === $vente->getAccordDemandeLe()) {
            throw $this->createNotFoundException();
        }
        $demandeur = $vente->getAccordDemandePar() ?? $vente->getVendeur();

        return $this->render('accord/voir.html.twig', [
            'vente' => $vente,
            'totaux' => $vente->calculer(),
            'controle' => $vente->accordEnAttente() ? $ventes->controler($vente, $demandeur, $this->pharmacie()) : null,
        ]);
    }

    #[Route('/{id}', name: 'app_accord_repondre', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"accord-" ~ args["vente"].getId()'))]
    public function repondre(Vente $vente, Request $requete, AccordProprietaire $accords): Response
    {
        $this->exigerMemePharmacie($vente);
        $donne = 'donner' === $requete->getPayload()->get('reponse');
        try {
            $accords->repondre($vente, $this->utilisateur(), $donne);
            $this->addFlash($donne ? 'success' : 'info', $donne
                ? \sprintf('Accord donné à %s : la vente peut être validée.', $vente->getAccordDemandePar()?->getNom())
                : \sprintf('Accord refusé : %s en est informé à la caisse.', $vente->getAccordDemandePar()?->getNom()));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_accord_index');
    }
}
