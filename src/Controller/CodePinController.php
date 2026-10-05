<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\CodePinType;
use App\Security\CodePin;
use App\Security\CodePinException;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Code PIN personnel (PH-04) : changement rapide de vendeur à la caisse et, pour le propriétaire,
 * autorisation des remises hors plafond et des ventes sans ordonnance.
 */
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class CodePinController extends AbstractAppController
{
    #[Route('/mon-code-pin', name: 'app_code_pin', methods: ['GET', 'POST'])]
    public function definir(Request $requete, CodePin $codePin): Response
    {
        $formulaire = $this->createForm(CodePinType::class);
        $formulaire->handleRequest($requete);
        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $codePin->definir($this->utilisateur(), (string) $formulaire->get('code')->getData());
                $this->addFlash('success', 'Votre code PIN est enregistré.');

                return $this->redirectToRoute('app_caisse');
            } catch (CodePinException $e) {
                $formulaire->get('code')->get('first')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('code_pin/index.html.twig', [
            'formulaire' => $formulaire,
            'a_un_code' => $this->utilisateur()->aUnCodePin(),
        ], self::reponseRefusSiSoumis($formulaire));
    }
}
