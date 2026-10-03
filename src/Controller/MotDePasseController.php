<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\MotDePasseOublieType;
use App\Form\NouveauMotDePasseType;
use App\Mailer\PlateformeMailer;
use App\Repository\UtilisateurRepository;
use App\Service\LienCompte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mot de passe oublié (PH-04) et activation de compte (§ 3.2 étape 1).
 */
final class MotDePasseController extends AbstractController
{
    private const MESSAGE_ENVOI = 'Si un compte actif correspond à cette adresse, un email avec un lien de réinitialisation vient d\'être envoyé. Le lien est valable une heure.';

    #[Route('/mot-de-passe-oublie', name: 'app_mot_de_passe_oublie', methods: ['GET', 'POST'])]
    public function oublie(
        Request $requete,
        UtilisateurRepository $utilisateurs,
        PlateformeMailer $mailer,
        #[Target('mot_de_passe_oublie.limiter')] RateLimiterFactoryInterface $limiteur,
    ): Response {
        $formulaire = $this->createForm(MotDePasseOublieType::class);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var string $email */
            $email = $formulaire->get('email')->getData();

            if (!$limiteur->create($requete->getClientIp().'|'.mb_strtolower($email))->consume()->isAccepted()) {
                $this->addFlash('warning', 'Trop de demandes pour cette adresse. Réessayez dans un quart d\'heure.');

                return $this->redirectToRoute('app_mot_de_passe_oublie');
            }

            // Même message que le compte existe ou non : on ne révèle pas quels emails sont inscrits.
            $utilisateur = $utilisateurs->parEmail($email);
            if (null !== $utilisateur && $utilisateur->isActif()) {
                $mailer->reinitialisation($utilisateur);
            }
            $this->addFlash('success', self::MESSAGE_ENVOI);

            return $this->redirectToRoute('app_connexion');
        }

        return $this->render('securite/mot_de_passe_oublie.html.twig', ['formulaire' => $formulaire]);
    }

    #[Route('/compte/{usage}/{id}/{empreinte}', name: 'app_compte_mot_de_passe', requirements: ['usage' => 'activation|reinitialisation', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function definir(
        string $usage,
        Request $requete,
        LienCompte $liens,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $utilisateur = $liens->verifier($requete);
        if (!$utilisateur instanceof Utilisateur) {
            return $this->render('securite/lien_invalide.html.twig', ['raison' => $utilisateur], new Response(status: Response::HTTP_GONE));
        }

        $formulaire = $this->createForm(NouveauMotDePasseType::class);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var string $motDePasse */
            $motDePasse = $formulaire->get('motDePasse')->getData();
            $utilisateur->setPassword($hasher->hashPassword($utilisateur, $motDePasse));
            $em->flush();

            $this->addFlash('success', LienCompte::ACTIVATION === $usage
                ? 'Votre compte est activé. Connectez-vous avec votre nouveau mot de passe.'
                : 'Votre mot de passe a été modifié. Connectez-vous.');

            return $this->redirectToRoute('app_connexion');
        }

        return $this->render('securite/definir_mot_de_passe.html.twig', [
            'formulaire' => $formulaire,
            'utilisateur' => $utilisateur,
            'activation' => LienCompte::ACTIVATION === $usage,
        ]);
    }
}
