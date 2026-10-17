<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Form\SuperAdminType;
use App\Mailer\PlateformeMailer;
use App\Repository\UtilisateurRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Comptes de l'éditeur de la plateforme : un super admin connecté en crée un autre, après confirmation.
 * Le nouveau compte reçoit un lien d'activation (il choisit lui-même son mot de passe) et la création
 * est tracée dans le journal de la plateforme.
 */
#[AdminRoute('/super-admins', name: 'super_admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class SuperAdminController extends AbstractController
{
    public function __construct(private readonly UtilisateurRepository $utilisateurs)
    {
    }

    #[AdminRoute('', name: 'index', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        return $this->render('admin/super_admin/index.html.twig', ['super_admins' => $this->utilisateurs->superAdmins()]);
    }

    #[AdminRoute('/nouveau', name: 'nouveau', options: ['methods' => ['GET', 'POST']])]
    public function nouveau(Request $requete, EntityManagerInterface $em, AuditLogger $audit, PlateformeMailer $mailer): Response
    {
        $admin = (new Utilisateur())->setRole(Utilisateur::ROLE_SUPER_ADMIN);
        $formulaire = $this->createForm(SuperAdminType::class, $admin);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $em->wrapInTransaction(static function () use ($em, $audit, $admin): void {
                $em->persist($admin);
                $em->flush();
                $audit->journaliser(AuditLogger::SUPER_ADMIN_CREE, null, $admin, null, [
                    'nom' => $admin->getNom(),
                    'email' => $admin->getEmail(),
                ]);
                $em->flush();
            });
            $mailer->activation($admin, null);
            $this->addFlash('success', htmlspecialchars(\sprintf('Super admin créé. Un lien d\'activation a été envoyé à %s.', $admin->getEmail())));

            return $this->redirectToRoute('admin_super_admin_index');
        }

        return $this->render('admin/super_admin/nouveau.html.twig', ['formulaire' => $formulaire], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[AdminRoute('/{id}/renvoyer-activation', name: 'renvoyer_activation', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(new Expression('"super-admin-" ~ args["admin"].getId()'))]
    public function renvoyerActivation(Utilisateur $admin, PlateformeMailer $mailer): Response
    {
        if (!$admin->isSuperAdmin()) {
            throw $this->createNotFoundException();
        }
        if ($admin->isActive()) {
            $this->addFlash('info', 'Ce compte est déjà activé.');
        } else {
            $mailer->activation($admin, null);
            $this->addFlash('success', htmlspecialchars(\sprintf('Lien d\'activation renvoyé à %s.', $admin->getEmail())));
        }

        return $this->redirectToRoute('admin_super_admin_index');
    }
}
