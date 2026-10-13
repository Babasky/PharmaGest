<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\SupportClientRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contacter le support de l'éditeur depuis la pharmacie : les canaux activés par le super admin (WhatsApp, appel, email).
 */
#[IsGranted(Utilisateur::ROLE_CAISSIER)]
final class SupportClientController extends AbstractAppController
{
    #[Route('/support', name: 'app_support', methods: ['GET'])]
    public function index(SupportClientRepository $supports): Response
    {
        return $this->render('support/index.html.twig', ['support' => $supports->courant()]);
    }
}
