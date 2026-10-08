<?php

namespace App\Controller;

use App\Security\PageAccueil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecuriteController extends AbstractController
{
    #[Route('/connexion', name: 'app_connexion', methods: ['GET', 'POST'])]
    public function connexion(AuthenticationUtils $authentification, PageAccueil $pageAccueil): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute($pageAccueil->route());
        }

        return $this->render('securite/connexion.html.twig', [
            'dernier_email' => $authentification->getLastUsername(),
            'erreur' => $authentification->getLastAuthenticationError(),
        ]);
    }

    #[Route('/deconnexion', name: 'app_deconnexion', methods: ['GET'])]
    public function deconnexion(): never
    {
        throw new \LogicException('Intercepté par le pare-feu.');
    }
}
