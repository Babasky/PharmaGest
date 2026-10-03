<?php

namespace App\Controller\Admin;

use App\Entity\Pharmacie;
use App\Form\Model\NouveauPaiement;
use App\Form\Model\NouvellePharmacie;
use App\Form\NouvellePharmacieType;
use App\Form\PaiementType;
use App\Form\PharmacieType;
use App\Mailer\PlateformeMailer;
use App\Repository\AbonnementRepository;
use App\Repository\AffectationRepository;
use App\Repository\OffreRepository;
use App\Repository\PharmacieRepository;
use App\Service\AbonnementService;
use App\Service\CreationPharmacie;
use App\Service\CreationPharmacieException;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Pharmacies clientes : création, paiements, suspension, archivage (SA-01 à SA-03).
 */
#[Route('/admin/pharmacies')]
final class PharmacieController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbonnementService $abonnements,
    ) {
    }

    #[Route('', name: 'admin_pharmacie_index', methods: ['GET'])]
    public function index(PharmacieRepository $pharmacies, #[MapQueryParameter] ?string $q = null, #[MapQueryParameter] int $page = 1): Response
    {
        $resultats = $pharmacies->rechercher($q, $page);

        return $this->render('admin/pharmacie/index.html.twig', [
            'pharmacies' => $resultats,
            'etats' => array_combine(
                array_map(static fn (Pharmacie $p) => (int) $p->getId(), $resultats->elements),
                array_map(fn (Pharmacie $p) => $this->abonnements->etat($p), $resultats->elements),
            ),
            'q' => $q,
        ]);
    }

    #[Route('/nouvelle', name: 'admin_pharmacie_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $requete, OffreRepository $offres, CreationPharmacie $creation): Response
    {
        $donnees = new NouvellePharmacie(new Pharmacie($offres->parCode(\App\Entity\Offre::STANDARD)));
        $formulaire = $this->createForm(NouvellePharmacieType::class, $donnees);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $proprietaire = $creation->creer($donnees->pharmacie, (string) $donnees->emailProprietaire, (string) $donnees->nomProprietaire, (int) $donnees->joursEssai);
                $this->addFlash('success', $proprietaire->isActive()
                    ? \sprintf('Pharmacie créée et ajoutée aux pharmacies de %s.', $proprietaire->getNom())
                    : \sprintf('Pharmacie créée. Un lien d\'activation a été envoyé à %s.', $proprietaire->getEmail()));

                return $this->redirectToRoute('admin_pharmacie_voir', ['id' => $donnees->pharmacie->getId()]);
            } catch (CreationPharmacieException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('admin/pharmacie/nouvelle.html.twig', ['formulaire' => $formulaire], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'admin_pharmacie_voir', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function voir(Pharmacie $pharmacie, Request $requete, AbonnementRepository $paiements, AffectationRepository $affectations): Response
    {
        $paiement = new NouveauPaiement();
        $paiement->offre = $pharmacie->getOffre();
        $paiement->montant = $pharmacie->getOffre()->getTarifAnnuel();
        $paiement->datePaiement = $this->abonnements->aujourdhui();

        $formulaire = $this->createForm(PaiementType::class, $paiement, [
            'action' => $this->generateUrl('admin_pharmacie_voir', ['id' => $pharmacie->getId()]).'#paiement',
        ]);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var \App\Entity\Utilisateur $admin */
            $admin = $this->getUser();
            $abonnement = $this->abonnements->enregistrerPaiement(
                $pharmacie,
                $paiement->offre ?? throw new \LogicException(),
                (int) $paiement->montant,
                $paiement->moyen ?? throw new \LogicException(),
                $paiement->reference,
                $paiement->datePaiement ?? throw new \LogicException(),
                $admin,
            );
            $this->addFlash('success', \sprintf('Paiement enregistré (facture %s). Abonnement valable jusqu\'au %s.', $abonnement->getNumeroFacture(), $abonnement->getDateFin()->format('d/m/Y')));

            return $this->redirectToRoute('admin_pharmacie_voir', ['id' => $pharmacie->getId()]);
        }

        return $this->render('admin/pharmacie/voir.html.twig', [
            'pharmacie' => $pharmacie,
            'etat' => $this->abonnements->etat($pharmacie),
            'periode' => $this->abonnements->prochainePeriode($pharmacie),
            'paiements' => $paiements->historique($pharmacie),
            'equipe' => $affectations->equipe($pharmacie),
            'formulaire' => $formulaire,
        ], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/modifier', name: 'admin_pharmacie_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Pharmacie $pharmacie, Request $requete): Response
    {
        $formulaire = $this->createForm(PharmacieType::class, $pharmacie);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Fiche de la pharmacie mise à jour.');

            return $this->redirectToRoute('admin_pharmacie_voir', ['id' => $pharmacie->getId()]);
        }

        return $this->render('admin/pharmacie/modifier.html.twig', ['pharmacie' => $pharmacie, 'formulaire' => $formulaire]);
    }

    #[Route('/{id}/suspendre', name: 'admin_pharmacie_suspendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new \Symfony\Component\ExpressionLanguage\Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function suspendre(Pharmacie $pharmacie, Request $requete): Response
    {
        $motif = trim($requete->request->getString('motif'));
        if ('' === $motif) {
            $this->addFlash('danger', 'Indiquez le motif de la suspension.');
        } else {
            $this->abonnements->suspendre($pharmacie, $motif);
            $this->addFlash('success', 'Pharmacie suspendue : plus aucun utilisateur n\'y a accès.');
        }

        return $this->redirectToRoute('admin_pharmacie_voir', ['id' => $pharmacie->getId()]);
    }

    #[Route('/{id}/reactiver', name: 'admin_pharmacie_reactiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new \Symfony\Component\ExpressionLanguage\Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function reactiver(Pharmacie $pharmacie): Response
    {
        $this->abonnements->reactiver($pharmacie);
        $this->addFlash('success', 'Pharmacie réactivée.');

        return $this->redirectToRoute('admin_pharmacie_voir', ['id' => $pharmacie->getId()]);
    }

    #[Route('/{id}/archiver', name: 'admin_pharmacie_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new \Symfony\Component\ExpressionLanguage\Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function archiver(Pharmacie $pharmacie): Response
    {
        $this->abonnements->archiver($pharmacie);
        $this->addFlash('success', 'Pharmacie archivée.');

        return $this->redirectToRoute('admin_pharmacie_voir', ['id' => $pharmacie->getId()]);
    }

    #[Route('/{id}/renvoyer-activation', name: 'admin_pharmacie_renvoyer_activation', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new \Symfony\Component\ExpressionLanguage\Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function renvoyerActivation(Pharmacie $pharmacie, AffectationRepository $affectations, PlateformeMailer $mailer, TenantContext $tenantContext): Response
    {
        $envoyes = 0;
        foreach ($tenantContext->sansFiltre(static fn () => $affectations->proprietaires($pharmacie)) as $proprietaire) {
            if (!$proprietaire->isActive()) {
                $mailer->activation($proprietaire, $pharmacie);
                ++$envoyes;
            }
        }
        $this->addFlash($envoyes > 0 ? 'success' : 'info', $envoyes > 0 ? 'Lien d\'activation renvoyé au propriétaire.' : 'Le compte du propriétaire est déjà activé.');

        return $this->redirectToRoute('admin_pharmacie_voir', ['id' => $pharmacie->getId()]);
    }
}
