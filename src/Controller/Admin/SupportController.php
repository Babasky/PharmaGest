<?php

namespace App\Controller\Admin;

use App\Form\SupportClientType;
use App\Repository\SupportClientRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Service support client : le super admin choisit comment les pharmacies le joignent (WhatsApp, appel, email).
 * Chaque modification est tracée dans le journal de la plateforme.
 */
#[AdminRoute('/support', name: 'support')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class SupportController extends AbstractController
{
    #[AdminRoute('', name: 'index', options: ['methods' => ['GET', 'POST']])]
    public function index(
        Request $requete,
        SupportClientRepository $supports,
        EntityManagerInterface $em,
        AuditLogger $audit,
        ClockInterface $horloge,
    ): Response {
        $support = $supports->courant();
        $avant = null !== $support->getId() ? $support->valeursJournalisees() : [];
        $formulaire = $this->createForm(SupportClientType::class, $support);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $apres = $support->valeursJournalisees();
            $modifie = array_filter($apres, static fn ($valeur, string $champ) => !\array_key_exists($champ, $avant) || $avant[$champ] !== $valeur, \ARRAY_FILTER_USE_BOTH);

            if ([] !== $modifie) {
                $support->setModifieLe($horloge->now());
                $em->persist($support);
                $em->flush();
                $audit->journaliser(AuditLogger::SUPPORT_MODIFIE, null, $support, array_intersect_key($avant, $modifie) ?: null, $modifie);
                $em->flush();
            }
            $this->addFlash('success', 'Le service support client est enregistré.');

            return $this->redirectToRoute('admin_support_index');
        }

        return $this->render('admin/support.html.twig', ['formulaire' => $formulaire, 'support' => $support], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
