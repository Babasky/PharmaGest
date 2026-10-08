<?php

namespace App\Controller;

use App\Achat\AchatException;
use App\Achat\CommandeService;
use App\Achat\ExportCommandeExcel;
use App\Achat\ReceptionService;
use App\Achat\SuggestionsCommande;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Enum\StatutCommande;
use App\Pdf\CommandePdf;
use App\Repository\CommandeRepository;
use App\Repository\FournisseurRepository;
use App\Repository\LotRepository;
use App\Repository\ProduitRepository;
use App\Stock\StockService;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Commandes fournisseurs (CO-01 à CO-06). Tout le monde prépare des brouillons ; passer ou envoyer une commande,
 * l'annuler et la réceptionner sont réservés au propriétaire et à l'adjoint, qui seuls voient les prix d'achat.
 */
#[Route('/commandes')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class CommandeController extends AbstractAppController
{
    private const CSRF_NOUVELLE = 'commande-nouvelle';
    private const CSRF_SUGGESTIONS = 'commande-suggestions';
    private const JETON = '"commande-" ~ args["commande"].getId()';

    public function __construct(
        private readonly CommandeService $commandes,
        private readonly FournisseurRepository $fournisseurs,
    ) {
    }

    #[Route('', name: 'app_commande_index', methods: ['GET'])]
    public function index(
        CommandeRepository $repository,
        SuggestionsCommande $suggestions,
        #[MapQueryParameter] ?string $fournisseur = null,
        #[MapQueryParameter] ?string $statut = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $filtreFournisseur = ctype_digit((string) $fournisseur) ? $this->fournisseurs->find((int) $fournisseur) : null;
        $filtreStatut = StatutCommande::tryFrom((string) $statut);

        return $this->render('commande/index.html.twig', [
            'commandes' => $repository->liste($filtreFournisseur, $filtreStatut, $page),
            'nombres' => $repository->nombresParStatut(),
            'a_commander' => array_sum(array_column($suggestions->parFournisseur(), 'nombre')),
            'fournisseurs' => $this->fournisseurs->choixActifs()->getQuery()->getResult(),
            'tous_fournisseurs' => $this->fournisseurs->findBy([], ['nom' => 'ASC']),
            'statuts' => StatutCommande::cases(),
            'fournisseur' => $filtreFournisseur?->getId(),
            'statut' => $filtreStatut?->value,
            'csrf_nouvelle' => self::CSRF_NOUVELLE,
        ]);
    }

    #[Route('/nouvelle', name: 'app_commande_nouvelle', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_NOUVELLE)]
    public function nouvelle(Request $requete): Response
    {
        $fournisseur = $this->fournisseurs->find((int) $requete->getPayload()->get('fournisseur'));
        if (!$fournisseur instanceof Fournisseur) {
            $this->addFlash('error', 'Choisissez le fournisseur de la commande.');

            return $this->redirectToRoute('app_commande_index');
        }
        try {
            $commande = $this->commandes->creer($fournisseur);
            $this->addFlash('success', \sprintf('Brouillon de commande créé pour %s : ajoutez les produits.', $fournisseur->getNom()));

            return $this->redirectToRoute('app_commande_voir', ['id' => $commande->getId()]);
        } catch (AchatException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_commande_index');
        }
    }

    #[Route('/suggestions', name: 'app_commande_suggestions', methods: ['GET'])]
    public function suggestions(SuggestionsCommande $suggestions, #[MapQueryParameter] ?string $fournisseur = null): Response
    {
        $groupes = $suggestions->parFournisseur();
        $choisi = null;
        $sansFournisseur = '0' === $fournisseur;
        if (ctype_digit((string) $fournisseur) && !$sansFournisseur) {
            $choisi = $groupes[(int) $fournisseur]['fournisseur'] ?? null;
        } elseif (null === $fournisseur) {
            // Par défaut, le premier fournisseur qui a des produits à commander.
            foreach ($groupes as $groupe) {
                if (null !== $groupe['fournisseur']) {
                    $choisi = $groupe['fournisseur'];
                    break;
                }
            }
        }

        return $this->render('commande/suggestions.html.twig', [
            'groupes' => $groupes,
            'fournisseur' => $choisi,
            'sans_fournisseur' => $sansFournisseur,
            'suggestions' => null !== $choisi || $sansFournisseur ? $suggestions->pour($choisi) : [],
            'csrf_suggestions' => self::CSRF_SUGGESTIONS,
        ]);
    }

    #[Route('/suggestions', name: 'app_commande_depuis_suggestions', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_SUGGESTIONS)]
    public function depuisSuggestions(Request $requete, ProduitRepository $produits): Response
    {
        $donnees = $requete->getPayload();
        $fournisseur = $this->fournisseurs->find((int) $donnees->get('fournisseur'));
        $retour = $this->redirectToRoute('app_commande_suggestions', ['fournisseur' => $fournisseur?->getId()]);
        if (!$fournisseur instanceof Fournisseur) {
            $this->addFlash('error', 'Choisissez le fournisseur de la commande.');

            return $retour;
        }

        $quantites = $donnees->all('quantite');
        $lignes = [];
        foreach ($donnees->all('produits') as $id) {
            $produit = $produits->find((int) (\is_scalar($id) ? $id : 0));
            if (!$produit instanceof Produit) {
                continue;
            }
            $quantite = CommandeService::entier($quantites[(int) $produit->getId()] ?? null);
            if (null === $quantite || 0 === $quantite) {
                continue;
            }
            $lignes[] = [$produit, $quantite];
        }
        if ([] === $lignes) {
            $this->addFlash('error', 'Cochez au moins un produit avec une quantité à commander.');

            return $retour;
        }

        try {
            $commande = $this->commandes->creer($fournisseur, $lignes);
            $this->addFlash('success', \sprintf('Brouillon créé avec %d produit(s) suggéré(s). Vérifiez-le puis envoyez-le à %s.', \count($lignes), $fournisseur->getNom()));

            return $this->redirectToRoute('app_commande_voir', ['id' => $commande->getId()]);
        } catch (AchatException $e) {
            $this->addFlash('error', $e->getMessage());

            return $retour;
        }
    }

    #[Route('/{id}', name: 'app_commande_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Commande $commande, ProduitRepository $produits, LotRepository $lots, StockService $stock, #[MapQueryParameter] ?string $q = null): Response
    {
        $this->exigerMemePharmacie($commande);
        $resultats = [];
        if ($commande->estBrouillon() && null !== $q && '' !== trim($q)) {
            $resultats = \array_slice($produits->rechercher($q, null, false, 1)->elements, 0, 10);
        }
        $listes = [...$commande->getLignes()->map(static fn (LigneCommande $l) => $l->getProduit())->toArray(), ...$resultats];

        return $this->render('commande/voir.html.twig', [
            'commande' => $commande,
            'q' => $q,
            'resultats' => $resultats,
            'synthese' => $lots->syntheseParProduit($listes, $stock->aujourdhui()),
        ]);
    }

    #[Route('/{id}/ajouter', name: 'app_commande_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::JETON))]
    public function ajouter(Commande $commande, Request $requete, ProduitRepository $produits): Response
    {
        $this->exigerMemePharmacie($commande);
        $donnees = $requete->getPayload();

        return $this->agir($commande, function () use ($commande, $donnees, $produits): string {
            $produit = $produits->find((int) $donnees->get('produit'));
            $quantite = CommandeService::entier($donnees->get('quantite'));
            if (!$produit instanceof Produit || null === $quantite) {
                throw new AchatException('Choisissez un produit et une quantité.');
            }
            $ligne = $this->commandes->ajouter($commande, $produit, $quantite);

            return \sprintf('%s : %d à commander.', $produit->getNomCommercial(), $ligne->getQuantite());
        });
    }

    #[Route('/{id}/lignes', name: 'app_commande_lignes', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::JETON))]
    public function lignes(Commande $commande, Request $requete): Response
    {
        $this->exigerMemePharmacie($commande);
        $donnees = $requete->getPayload();

        return $this->agir($commande, function () use ($commande, $donnees): string {
            // Seuls le propriétaire et l'adjoint voient et modifient les prix d'achat.
            $prix = $this->isGranted(Utilisateur::ROLE_ADJOINT) ? $donnees->all('prix') : null;
            $this->commandes->modifierLignes($commande, $donnees->all('quantite'), $prix);

            return 'Commande mise à jour.';
        });
    }

    #[Route('/{id}/retirer/{ligne}', name: 'app_commande_retirer', requirements: ['id' => '\d+', 'ligne' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::JETON))]
    public function retirer(Commande $commande, LigneCommande $ligne): Response
    {
        $this->exigerMemePharmacie($commande);

        return $this->agir($commande, function () use ($commande, $ligne): string {
            $this->commandes->retirer($commande, $ligne);

            return \sprintf('%s retiré de la commande.', $ligne->getProduit()->getNomCommercial());
        });
    }

    #[Route('/{id}/supprimer', name: 'app_commande_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::JETON))]
    public function supprimer(Commande $commande): Response
    {
        $this->exigerMemePharmacie($commande);
        try {
            $this->commandes->supprimer($commande);
            $this->addFlash('success', 'Brouillon supprimé.');

            return $this->redirectToRoute('app_commande_index');
        } catch (AchatException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_commande_voir', ['id' => $commande->getId()]);
        }
    }

    #[Route('/{id}/envoyer', name: 'app_commande_envoyer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(new Expression(self::JETON))]
    public function envoyer(Commande $commande): Response
    {
        $this->exigerMemePharmacie($commande);
        $renvoi = !$commande->estBrouillon();

        return $this->agir($commande, function () use ($commande, $renvoi): string {
            $envoi = $this->commandes->envoyer($commande);

            return \sprintf($renvoi ? 'Commande %s renvoyée à %s.' : 'Commande %s envoyée à %s avec le bon de commande Excel.', $commande->getNumero(), $envoi->getDestinataire());
        });
    }

    #[Route('/{id}/passer', name: 'app_commande_passer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(new Expression(self::JETON))]
    public function passer(Commande $commande): Response
    {
        $this->exigerMemePharmacie($commande);

        return $this->agir($commande, function () use ($commande): string {
            $this->commandes->passer($commande);

            return \sprintf('Commande %s passée sans email : téléchargez le bon de commande Excel pour le transmettre au fournisseur.', $commande->getNumero());
        });
    }

    #[Route('/{id}/annuler', name: 'app_commande_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(new Expression(self::JETON))]
    public function annuler(Commande $commande, Request $requete): Response
    {
        $this->exigerMemePharmacie($commande);

        return $this->agir($commande, function () use ($commande, $requete): string {
            $this->commandes->annuler($commande, (string) $requete->getPayload()->get('motif'));

            return \sprintf('Commande %s annulée.', $commande->getNumero());
        });
    }

    #[Route('/{id}/solder', name: 'app_commande_solder', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(new Expression(self::JETON))]
    public function solder(Commande $commande, Request $requete): Response
    {
        $this->exigerMemePharmacie($commande);

        return $this->agir($commande, function () use ($commande, $requete): string {
            $this->commandes->solder($commande, (string) $requete->getPayload()->get('motif'));

            return \sprintf('Commande %s soldée : le reste n\'est plus attendu.', $commande->getNumero());
        });
    }

    #[Route('/{id}/excel', name: 'app_commande_excel', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function excel(Commande $commande, ExportCommandeExcel $export): Response
    {
        $this->exigerMemePharmacie($commande);

        return $export->reponse($commande);
    }

    /** Bon de commande PDF, dès que la commande est passée (un brouillon n'a pas de numéro). */
    #[Route('/{id}/pdf', name: 'app_commande_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function pdf(Commande $commande, CommandePdf $pdf): Response
    {
        $this->exigerMemePharmacie($commande);
        if ($commande->estBrouillon()) {
            throw $this->createNotFoundException('Passez la commande pour obtenir son bon de commande PDF.');
        }

        return $pdf->reponse($commande);
    }

    #[Route('/{id}/reception', name: 'app_commande_reception', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function reception(Commande $commande, Request $requete, ReceptionService $receptions, StockService $stock): Response
    {
        $this->exigerMemePharmacie($commande);
        if (!$commande->estReceptionnable()) {
            $this->addFlash('error', \sprintf('La commande %s est %s : il n\'y a rien à réceptionner.', $commande->getLibelle(), mb_strtolower($commande->getStatut()->libelle())));

            return $this->redirectToRoute('app_commande_voir', ['id' => $commande->getId()]);
        }

        $donnees = $requete->getPayload();
        $erreur = null;
        if ($requete->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('commande-'.$commande->getId(), (string) $donnees->get('_token'))) {
                $erreur = 'Le formulaire a expiré : merci de le saisir à nouveau.';
            } else {
                try {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $donnees->get('date')) ?: throw new AchatException('Date de réception invalide.');
                    $saisies = array_values(array_filter($donnees->all('lignes'), 'is_array'));
                    $avertissements = $receptions->receptionner($commande, $date, (string) $donnees->get('bon_livraison'), $saisies);
                    $this->addFlash('success', \sprintf('Réception enregistrée : lots créés et stock mis à jour. Commande %s.', mb_strtolower($commande->getStatut()->libelle())));
                    foreach ($avertissements as $avertissement) {
                        $this->addFlash('warning', $avertissement);
                    }

                    return $this->redirectToRoute('app_commande_voir', ['id' => $commande->getId()]);
                } catch (AchatException $e) {
                    $erreur = $e->getMessage();
                }
            }
        }

        return $this->render('commande/reception.html.twig', [
            'commande' => $commande,
            'erreur' => $erreur,
            'saisie' => $requete->isMethod('POST') ? $donnees->all() : null,
            'aujourdhui' => $stock->aujourdhui(),
        ], null === $erreur ? null : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param callable(): string $action renvoie le message de succès
     */
    private function agir(Commande $commande, callable $action): Response
    {
        try {
            $this->addFlash('success', $action());
        } catch (AchatException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_commande_voir', ['id' => $commande->getId()]);
    }
}
