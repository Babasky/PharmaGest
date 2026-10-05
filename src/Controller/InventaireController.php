<?php

namespace App\Controller;

use App\Entity\Inventaire;
use App\Entity\Utilisateur;
use App\Enum\PerimetreInventaire;
use App\Form\NouvelInventaireType;
use App\Repository\InventaireRepository;
use App\Stock\GestionInventaire;
use App\Stock\StockException;
use App\Stock\StockService;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Inventaires complets ou tournants (ST-06), par le propriétaire ou l'adjoint.
 */
#[Route('/inventaires')]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class InventaireController extends AbstractAppController
{
    public function __construct(private readonly GestionInventaire $gestion)
    {
    }

    #[Route('', name: 'app_inventaire_index', methods: ['GET', 'POST'])]
    public function index(
        Request $requete,
        InventaireRepository $inventaires,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $formulaire = $this->createForm(NouvelInventaireType::class, ['perimetre' => PerimetreInventaire::Complet]);
        $formulaire->handleRequest($requete);
        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var array{perimetre: PerimetreInventaire, etagere: \App\Entity\Etagere|null, categorie: \App\Entity\Categorie|null} $choix */
            $choix = $formulaire->getData();
            try {
                $inventaire = $this->gestion->ouvrir($this->pharmacie(), $choix['perimetre'], $choix['etagere'], $choix['categorie']);
                $this->addFlash('success', \sprintf('Inventaire %s ouvert : %d lot(s) à compter.', $inventaire->getNumero(), $inventaire->getLignes()->count()));

                return $this->redirectToRoute('app_inventaire_voir', ['id' => $inventaire->getId()]);
            } catch (StockException $e) {
                $formulaire->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('inventaire/index.html.twig', [
            'inventaires' => $inventaires->liste($page),
            'en_cours' => $inventaires->enCours(),
            'formulaire' => $formulaire,
        ], self::reponseRefusSiSoumis($formulaire));
    }

    #[Route('/{id}', name: 'app_inventaire_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Inventaire $inventaire, StockService $stock): Response
    {
        $this->exigerMemePharmacie($inventaire);

        return $this->render('inventaire/voir.html.twig', [
            'inventaire' => $inventaire,
            'aujourdhui' => $stock->aujourdhui(),
        ]);
    }

    /**
     * Enregistre les quantités saisies ; avec le bouton « Valider », valide ensuite l'inventaire.
     */
    #[Route('/{id}/comptage', name: 'app_inventaire_comptage', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"inventaire-" ~ args["inventaire"].getId()'))]
    public function comptage(Inventaire $inventaire, Request $requete): Response
    {
        $this->exigerMemePharmacie($inventaire);
        /** @var array<int|string, mixed> $quantites */
        $quantites = $requete->getPayload()->all('comptage');

        try {
            $this->gestion->enregistrerComptage($inventaire, $quantites);
            if ($requete->getPayload()->has('valider')) {
                $this->gestion->valider($inventaire);
                $this->addFlash('success', \sprintf('Inventaire %s validé : %d écart(s) appliqué(s) au stock.', $inventaire->getNumero(), $inventaire->nombreEcarts()));
            } else {
                $this->addFlash('success', \sprintf('Comptage enregistré (%d / %d lots comptés).', $inventaire->nombreComptees(), $inventaire->getLignes()->count()));
            }
        } catch (StockException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_inventaire_voir', ['id' => $inventaire->getId()]);
    }

    #[Route('/{id}/annuler', name: 'app_inventaire_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"inventaire-" ~ args["inventaire"].getId()'))]
    public function annuler(Inventaire $inventaire): Response
    {
        $this->exigerMemePharmacie($inventaire);
        try {
            $this->gestion->annuler($inventaire);
            $this->addFlash('success', \sprintf('Inventaire %s annulé : le stock n\'a pas été modifié.', $inventaire->getNumero()));
        } catch (StockException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_inventaire_index');
    }
}
