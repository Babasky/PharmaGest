<?php

namespace App\Controller;

use App\Entity\Entrepot;
use App\Entity\Utilisateur;
use App\Form\EntrepotType;
use App\Repository\EntrepotRepository;
use App\Repository\InventaireRepository;
use App\Repository\LotRepository;
use App\Repository\ProduitRepository;
use App\Repository\TransfertInterneRepository;
use App\Service\AuditLogger;
use App\Stock\StockException;
use App\Stock\StockService;
use App\Stock\TransfertInterneService;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Entrepôts (magasins de stockage) de la pharmacie et transferts entre l'officine et ses entrepôts, ou d'un entrepôt
 * à l'autre : propriétaire et adjoint, comme les entrées de stock.
 */
#[Route('/entrepots')]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class EntrepotController extends AbstractAppController
{
    /** Valeur des listes d'emplacements qui désigne l'officine. */
    private const OFFICINE = 'officine';
    private const CSRF_TRANSFERT = 'transfert-interne';

    public function __construct(
        private readonly EntrepotRepository $entrepots,
        private readonly LotRepository $lots,
        private readonly StockService $stock,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_entrepot_index', methods: ['GET', 'POST'])]
    public function index(Request $requete): Response
    {
        $entrepot = new Entrepot();
        $formulaire = $this->createForm(EntrepotType::class, $entrepot);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->persist($entrepot);
            $this->entityManager->flush();
            $this->audit->journaliser(AuditLogger::ENTREPOT_CREE, $this->pharmacie(), $entrepot, null, ['nom' => $entrepot->getNom(), 'adresse' => $entrepot->getAdresse()]);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Entrepôt %s créé. Rangez-y du stock par une entrée de stock, une réception de commande ou un transfert depuis l\'officine.', $entrepot->getNom()));

            return $this->redirectToRoute('app_entrepot_voir', ['id' => $entrepot->getId()]);
        }

        $valeurs = [];
        foreach ($this->lots->valorisationParEmplacement($this->stock->aujourdhui()) as $ligne) {
            $valeurs[(int) $ligne['entrepot']?->getId()] = $ligne;
        }

        return $this->render('entrepot/index.html.twig', [
            'entrepots' => $this->entrepots->tous(),
            'valeurs' => $valeurs,
            'formulaire' => $formulaire,
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}', name: 'app_entrepot_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Entrepot $entrepot, TransfertInterneRepository $transferts, #[MapQueryParameter] ?string $q = null): Response
    {
        $this->exigerMemePharmacie($entrepot);
        $contenu = $this->lots->contenu($entrepot, $this->stock->aujourdhui(), $q);
        $produits = array_map(static fn (array $l) => $l['produit'], $contenu);

        return $this->render('entrepot/voir.html.twig', [
            'entrepot' => $entrepot,
            'contenu' => $contenu,
            'synthese' => $this->lots->syntheseParProduit($produits, $this->stock->aujourdhui()),
            'transferts' => $transferts->liste($entrepot, 1),
            'q' => $q,
            'aujourdhui' => $this->stock->aujourdhui(),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_entrepot_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Entrepot $entrepot, Request $requete): Response
    {
        $this->exigerMemePharmacie($entrepot);
        $avant = ['nom' => $entrepot->getNom(), 'adresse' => $entrepot->getAdresse()];
        $formulaire = $this->createForm(EntrepotType::class, $entrepot);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->audit->journaliser(AuditLogger::ENTREPOT_MODIFIE, $this->pharmacie(), $entrepot, $avant, ['nom' => $entrepot->getNom(), 'adresse' => $entrepot->getAdresse()]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Entrepôt modifié.');

            return $this->redirectToRoute('app_entrepot_voir', ['id' => $entrepot->getId()]);
        }

        return $this->render('referentiel/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => 'Modifier l\'entrepôt '.$entrepot->getNom(),
            'retour' => $this->generateUrl('app_entrepot_voir', ['id' => $entrepot->getId()]),
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    /** Un entrepôt qui contient encore du stock ne s'archive pas : il faut d'abord le vider (transfert, destruction). */
    #[Route('/{id}/archiver', name: 'app_entrepot_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"archiver-entrepot-" ~ args["entrepot"].getId()'))]
    public function archiver(Entrepot $entrepot, InventaireRepository $inventaires): Response
    {
        $this->exigerMemePharmacie($entrepot);
        if ($entrepot->isActif() && [] !== $this->lots->contenu($entrepot, $this->stock->aujourdhui())) {
            $this->addFlash('error', \sprintf('L\'entrepôt %s contient encore du stock : transférez-le ou détruisez-le avant d\'archiver l\'entrepôt.', $entrepot->getNom()));

            return $this->redirectToRoute('app_entrepot_voir', ['id' => $entrepot->getId()]);
        }
        if ($entrepot->isActif() && null !== ($inventaire = $inventaires->enCours($entrepot))) {
            $this->addFlash('error', \sprintf('L\'inventaire %s de l\'entrepôt %s est en cours : validez-le ou annulez-le avant d\'archiver l\'entrepôt.', $inventaire->getNumero(), $entrepot->getNom()));

            return $this->redirectToRoute('app_entrepot_voir', ['id' => $entrepot->getId()]);
        }
        $avant = ['actif' => $entrepot->isActif()];
        $this->basculerArchivage($entrepot, \sprintf('L\'entrepôt %s', $entrepot->getNom()));
        $this->audit->journaliser(AuditLogger::ENTREPOT_MODIFIE, $this->pharmacie(), $entrepot, $avant, ['actif' => $entrepot->isActif()]);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_entrepot_index');
    }

    #[Route('/transferts', name: 'app_entrepot_transferts', methods: ['GET'])]
    public function transferts(TransfertInterneRepository $transferts, #[MapQueryParameter] int $page = 1): Response
    {
        return $this->render('entrepot/transferts.html.twig', [
            'transferts' => $transferts->liste(null, $page),
        ]);
    }

    /**
     * Transfert entre l'officine et un entrepôt, ou entre deux entrepôts. Les produits viennent d'une recherche (q)
     * ou sont proposés d'avance avec leur quantité (p[id]=quantité, depuis les suggestions de commande ou une fiche).
     * Sans origine choisie, l'origine proposée est l'entrepôt qui peut fournir le plus des quantités proposées.
     */
    #[Route('/transfert', name: 'app_entrepot_transfert', methods: ['GET', 'POST'])]
    public function transfert(Request $requete, ProduitRepository $produits, TransfertInterneService $service): Response
    {
        $actifs = $this->entrepots->actifs();
        if ([] === $actifs) {
            $this->addFlash('info', 'Créez d\'abord un entrepôt pour pouvoir y transférer du stock.');

            return $this->redirectToRoute('app_entrepot_index');
        }
        $parametres = $requete->isMethod('POST') ? $requete->request : $requete->query;
        $proposes = $this->quantitesSaisies($parametres->all()['p'] ?? []);
        $q = trim((string) $parametres->get('q', ''));

        $origine = $this->emplacement((string) $parametres->get('origine', ''), $actifs);
        $destination = $this->emplacement((string) $parametres->get('destination', ''), $actifs);
        if (false === $origine) {
            $origine = $this->origineProposee($proposes, $produits) ?? $actifs[0];
        }
        if (false === $destination) {
            $destination = null === $origine ? $actifs[0] : null;
        }

        $erreur = null;
        if ($requete->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_TRANSFERT, (string) $requete->request->get('_token'))) {
                $erreur = 'Le formulaire a expiré : merci de le saisir à nouveau.';
            } else {
                $lignes = [];
                foreach ($produits->findBy(['id' => array_keys($proposes)]) as $produit) {
                    $lignes[] = [$produit, $proposes[(int) $produit->getId()]];
                }
                try {
                    $transfert = $service->transferer($origine, $destination, $lignes, (string) $requete->request->get('note'));
                    $this->addFlash('success', \sprintf('Transfert %s enregistré : %d unité(s) de %s vers %s.', $transfert->getNumero(), $transfert->getQuantiteTotale(), $transfert->getLibelleOrigine(), $transfert->getLibelleDestination()));

                    return null === $destination && null !== $origine
                        ? $this->redirectToRoute('app_entrepot_voir', ['id' => $origine->getId()])
                        : (null !== $destination ? $this->redirectToRoute('app_entrepot_voir', ['id' => $destination->getId()]) : $this->redirectToRoute('app_entrepot_transferts'));
                } catch (StockException $e) {
                    $erreur = $e->getMessage();
                }
            }
        }

        // Produits affichés : ceux proposés d'avance, puis ceux trouvés par la recherche.
        $liste = [];
        foreach ($proposes ? $produits->findBy(['id' => array_keys($proposes)], ['nomCommercial' => 'ASC']) : [] as $produit) {
            $liste[(int) $produit->getId()] = $produit;
        }
        if ('' !== $q) {
            foreach ($produits->rechercher($q, null, false, 1) as $produit) {
                $liste[(int) $produit->getId()] ??= $produit;
            }
        }
        $jour = $this->stock->aujourdhui();
        $disponibles = [];
        foreach ($liste as $id => $produit) {
            $disponibles[$id] = [
                'origine' => $this->lots->stockDisponible($produit, $jour, $origine),
                'destination' => $this->lots->stockDisponible($produit, $jour, $destination),
            ];
        }

        return $this->render('entrepot/transfert.html.twig', [
            'entrepots' => $actifs,
            'origine' => $origine,
            'destination' => $destination,
            'produits' => $liste,
            'disponibles' => $disponibles,
            'quantites' => $proposes,
            'q' => $q,
            'note' => (string) $parametres->get('note'),
            'erreur' => $erreur,
            'officine' => self::OFFICINE,
            'csrf_transfert' => self::CSRF_TRANSFERT,
        ], null === $erreur ? null : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param list<Entrepot> $actifs
     *
     * @return Entrepot|false|null false : rien de choisi ; null : l'officine
     */
    private function emplacement(string $valeur, array $actifs): Entrepot|false|null
    {
        if (self::OFFICINE === $valeur) {
            return null;
        }
        foreach ($actifs as $entrepot) {
            if ((string) $entrepot->getId() === $valeur) {
                return $entrepot;
            }
        }

        return false;
    }

    /**
     * Entrepôt qui couvre le plus d'unités des quantités proposées (une quantité vide : tout son disponible).
     *
     * @param array<int, int> $proposes
     */
    private function origineProposee(array $proposes, ProduitRepository $produits): ?Entrepot
    {
        $scores = [];
        $entrepots = [];
        foreach ([] === $proposes ? [] : $produits->findBy(['id' => array_keys($proposes)]) as $produit) {
            $voulu = $proposes[(int) $produit->getId()];
            foreach ($this->lots->disponibleParEntrepot($produit, $this->stock->aujourdhui()) as ['entrepot' => $entrepot, 'quantite' => $quantite]) {
                $id = (int) $entrepot->getId();
                $entrepots[$id] = $entrepot;
                $scores[$id] = ($scores[$id] ?? 0) + ($voulu > 0 ? min($voulu, $quantite) : $quantite);
            }
        }
        if ([] === $scores) {
            return null;
        }
        arsort($scores);

        return $entrepots[array_key_first($scores)];
    }

    /**
     * @return array<int, int> quantité indexée par id de produit (vide ou invalide : zéro)
     */
    private function quantitesSaisies(mixed $saisies): array
    {
        $quantites = [];
        foreach (\is_array($saisies) ? $saisies : [] as $id => $saisie) {
            if (!ctype_digit((string) $id) || !\is_scalar($saisie)) {
                continue;
            }
            // Une quantité vide garde le produit dans la liste, sans rien transférer.
            $quantites[(int) $id] = ctype_digit(trim((string) $saisie)) ? min((int) $saisie, 1000000) : 0;
        }

        return $quantites;
    }
}
