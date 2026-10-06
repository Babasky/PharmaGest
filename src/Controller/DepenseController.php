<?php

namespace App\Controller;

use App\Entity\CategorieDepense;
use App\Entity\Depense;
use App\Entity\Utilisateur;
use App\Finance\FinanceException;
use App\Finance\GestionDepenses;
use App\Finance\JustificatifDepense;
use App\Form\DepenseType;
use App\Form\Model\SaisieDepense;
use App\Reporting\Periode;
use App\Repository\CategorieDepenseRepository;
use App\Repository\DepenseRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dépenses (FI-01) et catégories de dépenses (FI-02). Réservé au propriétaire (matrice des droits).
 */
#[Route('/depenses')]
#[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
final class DepenseController extends AbstractAppController
{
    private const CSRF_CATEGORIES = 'depense-categories';

    public function __construct(
        private readonly GestionDepenses $gestion,
        private readonly ClockInterface $horloge,
    ) {
    }

    #[Route('', name: 'app_depense_index', methods: ['GET'])]
    public function index(
        Request $requete,
        DepenseRepository $depenses,
        CategorieDepenseRepository $categories,
        #[MapQueryParameter] ?string $categorie = null,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $periode = $this->periode($requete);
        $liste = $this->gestion->categories();
        $filtre = ctype_digit((string) $categorie) ? $categories->find((int) $categorie) : null;

        return $this->render('depense/index.html.twig', [
            'periode' => $periode,
            'depenses' => $depenses->liste($periode->debut, $periode->fin, $filtre, $q, $page),
            'total' => $depenses->total($periode->debut, $periode->fin),
            'par_categorie' => $depenses->parCategorie($periode->debut, $periode->fin),
            'categories' => $liste,
            'categorie' => $filtre?->getId(),
            'q' => $q,
            'csrf_categories' => self::CSRF_CATEGORIES,
        ]);
    }

    #[Route('/nouvelle', name: 'app_depense_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $requete): Response
    {
        $this->gestion->categories();
        $saisie = new SaisieDepense();
        $saisie->date = $this->horloge->now()->setTime(0, 0);
        $formulaire = $this->createForm(DepenseType::class, $saisie);
        $formulaire->handleRequest($requete);
        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $depense = $this->gestion->enregistrer($saisie);
                $this->addFlash('success', \sprintf('Dépense %s enregistrée.', $depense->getNumero()));

                return $this->redirectToRoute('app_depense_voir', ['id' => $depense->getId()]);
            } catch (FinanceException $e) {
                $formulaire->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('depense/formulaire.html.twig', ['formulaire' => $formulaire, 'depense' => null], self::reponseRefusSiSoumis($formulaire));
    }

    #[Route('/{id}', name: 'app_depense_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Depense $depense): Response
    {
        $this->exigerMemePharmacie($depense);

        return $this->render('depense/voir.html.twig', ['depense' => $depense]);
    }

    #[Route('/{id}/modifier', name: 'app_depense_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Depense $depense, Request $requete): Response
    {
        $this->exigerMemePharmacie($depense);
        if ($depense->estAnnulee()) {
            $this->addFlash('error', 'Cette dépense est annulée : elle ne se modifie plus.');

            return $this->redirectToRoute('app_depense_voir', ['id' => $depense->getId()]);
        }
        $saisie = SaisieDepense::depuis($depense);
        $formulaire = $this->createForm(DepenseType::class, $saisie);
        $formulaire->handleRequest($requete);
        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $this->gestion->modifier($depense, $saisie);
                $this->addFlash('success', \sprintf('Dépense %s modifiée.', $depense->getNumero()));

                return $this->redirectToRoute('app_depense_voir', ['id' => $depense->getId()]);
            } catch (FinanceException $e) {
                $formulaire->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('depense/formulaire.html.twig', ['formulaire' => $formulaire, 'depense' => $depense], self::reponseRefusSiSoumis($formulaire));
    }

    #[Route('/{id}/annuler', name: 'app_depense_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"depense-" ~ args["depense"].getId()'))]
    public function annuler(Depense $depense, Request $requete): Response
    {
        $this->exigerMemePharmacie($depense);
        try {
            $this->gestion->annuler($depense, (string) $requete->getPayload()->get('motif'));
            $this->addFlash('success', \sprintf('Dépense %s annulée : elle garde son numéro et sort des totaux.', $depense->getNumero()));
        } catch (FinanceException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_depense_voir', ['id' => $depense->getId()]);
    }

    #[Route('/{id}/justificatif', name: 'app_depense_justificatif', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function justificatif(Depense $depense, JustificatifDepense $justificatifs): Response
    {
        $this->exigerMemePharmacie($depense);
        $chemin = $justificatifs->chemin($this->pharmacie(), $depense);
        if (null === $chemin) {
            throw $this->createNotFoundException();
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, \sprintf('justificatif-%s.%s', $depense->getNumero(), pathinfo($chemin, \PATHINFO_EXTENSION)));
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');

        return $reponse;
    }

    #[Route('/categories', name: 'app_depense_categorie_ajouter', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_CATEGORIES)]
    public function ajouterCategorie(Request $requete): Response
    {
        try {
            $categorie = $this->gestion->ajouterCategorie((string) $requete->getPayload()->get('nom'));
            $this->addFlash('success', \sprintf('Catégorie « %s » ajoutée.', $categorie->getNom()));
        } catch (FinanceException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_depense_index');
    }

    #[Route('/categories/{id}/archiver', name: 'app_depense_categorie_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_CATEGORIES)]
    public function archiverCategorie(CategorieDepense $categorie): Response
    {
        $this->basculerArchivage($categorie, \sprintf('La catégorie « %s »', $categorie->getNom()));

        return $this->redirectToRoute('app_depense_index');
    }

    private function periode(Request $requete): Periode
    {
        return Periode::depuisRequete(
            $requete->query->getString('periode'),
            $requete->query->getString('date'),
            $requete->query->getString('du'),
            $requete->query->getString('au'),
            $this->horloge->now(),
        );
    }
}
