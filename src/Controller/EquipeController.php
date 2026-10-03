<?php

namespace App\Controller;

use App\Entity\Affectation;
use App\Entity\Utilisateur;
use App\Form\MembreEquipeType;
use App\Repository\AffectationRepository;
use App\Security\Voter\EquipeVoter;
use App\Service\GestionEquipe;
use App\Service\GestionEquipeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Équipe de la pharmacie courante (PH-03). Les {id} sont ceux des affectations :
 * le filtre tenant les rend introuvables (404) pour une autre pharmacie.
 */
#[Route('/equipe')]
#[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
final class EquipeController extends AbstractAppController
{
    public function __construct(private readonly GestionEquipe $equipe)
    {
    }

    #[Route('', name: 'app_equipe_index', methods: ['GET'])]
    public function index(AffectationRepository $affectations): Response
    {
        $pharmacie = $this->pharmacie();

        return $this->render('equipe/index.html.twig', [
            'membres' => $affectations->equipe($pharmacie),
            'places_restantes' => $this->equipe->placesRestantes($pharmacie),
            'offre' => $pharmacie->getOffre(),
        ]);
    }

    #[Route('/nouveau', name: 'app_equipe_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $requete): Response
    {
        $membre = (new Utilisateur())->setRole(Utilisateur::ROLE_VENDEUR);
        $formulaire = $this->createForm(MembreEquipeType::class, $membre, ['creation' => true]);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                /** @var string|null $motDePasse */
                $motDePasse = $formulaire->get('motDePasseInitial')->getData();
                $this->equipe->ajouter($this->pharmacie(), $membre, $motDePasse);
                $this->addFlash('success', $membre->isActive()
                    ? \sprintf('%s peut se connecter avec le mot de passe que vous avez choisi.', $membre->getNom())
                    : \sprintf('Un lien d\'activation a été envoyé à %s.', $membre->getEmail()));

                return $this->redirectToRoute('app_equipe_index');
            } catch (GestionEquipeException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('equipe/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => 'Ajouter un membre',
            'places_restantes' => $this->equipe->placesRestantes($this->pharmacie()),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_equipe_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Affectation $affectation, Request $requete): Response
    {
        $this->exigerMemePharmacie($affectation);
        $this->denyAccessUnlessGranted(EquipeVoter::GERER, $affectation);

        $membre = $affectation->getUtilisateur();
        $avant = ['nom' => $membre->getNom(), 'email' => $membre->getEmail(), 'role' => $membre->getRole()];
        $formulaire = $this->createForm(MembreEquipeType::class, $membre);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $this->equipe->modifier($affectation, $avant);
                $this->addFlash('success', 'Modifications enregistrées.');

                return $this->redirectToRoute('app_equipe_index');
            } catch (GestionEquipeException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('equipe/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => 'Modifier '.$avant['nom'],
            'affectation' => $affectation,
            'places_restantes' => null,
        ]);
    }

    #[Route('/{id}/desactiver', name: 'app_equipe_desactiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new \Symfony\Component\ExpressionLanguage\Expression('"equipe-" ~ args["affectation"].getId()'))]
    public function desactiver(Affectation $affectation): Response
    {
        $this->exigerMemePharmacie($affectation);
        $this->denyAccessUnlessGranted(EquipeVoter::GERER, $affectation);

        $this->equipe->desactiver($affectation);
        $this->addFlash('success', \sprintf('%s ne peut plus se connecter à la pharmacie.', $affectation->getUtilisateur()->getNom()));

        return $this->redirectToRoute('app_equipe_index');
    }

    #[Route('/{id}/reactiver', name: 'app_equipe_reactiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new \Symfony\Component\ExpressionLanguage\Expression('"equipe-" ~ args["affectation"].getId()'))]
    public function reactiver(Affectation $affectation): Response
    {
        $this->exigerMemePharmacie($affectation);
        $this->denyAccessUnlessGranted(EquipeVoter::GERER, $affectation);

        try {
            $this->equipe->reactiver($affectation);
            $this->addFlash('success', \sprintf('%s a de nouveau accès à la pharmacie.', $affectation->getUtilisateur()->getNom()));
        } catch (GestionEquipeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_equipe_index');
    }

    #[Route('/{id}/renvoyer-activation', name: 'app_equipe_renvoyer_activation', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new \Symfony\Component\ExpressionLanguage\Expression('"equipe-" ~ args["affectation"].getId()'))]
    public function renvoyerActivation(Affectation $affectation): Response
    {
        $this->exigerMemePharmacie($affectation);
        $this->denyAccessUnlessGranted(EquipeVoter::GERER, $affectation);

        $this->equipe->renvoyerLienActivation($affectation);
        $this->addFlash('success', \sprintf('Nouveau lien d\'activation envoyé à %s.', $affectation->getUtilisateur()->getEmail()));

        return $this->redirectToRoute('app_equipe_index');
    }
}
