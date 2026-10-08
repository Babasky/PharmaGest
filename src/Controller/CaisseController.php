<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\LigneVente;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\TypeRemise;
use App\Enum\TypeVente;
use App\Repository\AffectationRepository;
use App\Repository\ClientRepository;
use App\Repository\LotRepository;
use App\Repository\OrganismeAmoRepository;
use App\Repository\ProduitRepository;
use App\Repository\VenteRepository;
use App\Security\CodePin;
use App\Security\CodePinException;
use App\Service\ParametresPharmacie;
use App\Stock\StockService;
use App\Util\Fcfa;
use App\Vente\GestionCaisse;
use App\Vente\VenteException;
use App\Vente\VenteService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran de caisse (VE-01) : recherche produit (nom, DCI), panier, type de vente, client,
 * remises, encaissement ou envoi à la caisse, et mise en attente. Chaque action est un formulaire classique suivi d'une
 * redirection : l'écran reste utilisable au clavier, même sans JavaScript.
 */
#[Route('/caisse')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class CaisseController extends AbstractAppController
{
    private const CSRF = 'caisse';

    public function __construct(
        private readonly VenteService $ventes,
        private readonly GestionCaisse $caisse,
    ) {
    }

    #[Route('', name: 'app_caisse', methods: ['GET'])]
    public function index(
        ProduitRepository $produits,
        ClientRepository $clients,
        LotRepository $lots,
        VenteRepository $ventesRepo,
        AffectationRepository $affectations,
        OrganismeAmoRepository $organismes,
        ParametresPharmacie $parametres,
        StockService $stock,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $client = null,
    ): Response {
        $utilisateur = $this->utilisateur();
        // Sans caisse ouverte, le vendeur prépare ses ventes et les envoie à la caisse.
        $session = $this->caisse->sessionOuverte($utilisateur);

        $panier = $this->ventes->panier($utilisateur);
        $resultats = null !== $q && '' !== trim($q) ? $produits->rechercher($q, null, false, 1)->elements : [];
        $dansPanier = null !== $panier ? $panier->getLignes()->map(static fn (LigneVente $l) => $l->getProduit())->toArray() : [];

        return $this->render('caisse/index.html.twig', [
            'session' => $session,
            'panier' => $panier,
            'totaux' => $panier?->calculer(),
            'controle' => null !== $panier ? $this->ventes->controler($panier, $utilisateur, $this->pharmacie()) : null,
            'parametres' => $parametres->pour($this->pharmacie()),
            'q' => $q,
            'resultats' => $resultats,
            'stocks' => $lots->syntheseParProduit([...$resultats, ...$dansPanier], $stock->aujourdhui()),
            'recherche_client' => $client,
            'clients' => null !== $client && '' !== trim($client) ? $clients->rechercher($client, false, false, 1)->elements : [],
            'en_attente' => $ventesRepo->enAttente(),
            'a_encaisser' => $ventesRepo->aEncaisser(),
            'types' => TypeVente::cases(),
            'organismes' => $organismes->actifs(),
            'vendeurs' => array_filter(
                array_map(static fn ($a) => $a->getUtilisateur(), $affectations->equipe($this->pharmacie())),
                static fn (Utilisateur $u) => $u->aUnCodePin() && $u->isActif() && $u->getId() !== $utilisateur->getId(),
            ),
        ]);
    }

    #[Route('/ajouter/{id}', name: 'app_caisse_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function ajouter(Produit $produit, Request $requete): Response
    {
        $this->exigerMemePharmacie($produit);
        $quantite = $requete->getPayload()->getInt('quantite', 1);
        $this->ajouterAuPanier($produit, max(1, $quantite));

        return $this->redirectToRoute('app_caisse');
    }

    #[Route('/ligne/{id}', name: 'app_caisse_ligne', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function ligne(LigneVente $ligne, Request $requete): Response
    {
        $this->exigerMonPanier($ligne->getVente());
        $donnees = $requete->getPayload();
        try {
            if ($donnees->has('retirer')) {
                $this->ventes->retirer($ligne);
            } else {
                $quantite = $donnees->getInt('quantite', $ligne->getQuantite());
                if ($donnees->has('remise_valeur')) {
                    $this->ventes->remiseLigne($ligne, TypeRemise::tryFrom((string) $donnees->get('remise_type')), $this->montant($donnees->get('remise_valeur'), 'Remise') ?? 0);
                }
                $this->ventes->modifierQuantite($ligne, $quantite);
            }
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_caisse');
    }

    /**
     * Type de vente, ordonnance (avec sa copie), organisme d'une vente AMO sans client assuré et remise sur le total.
     */
    #[Route('/vente', name: 'app_caisse_vente', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function vente(Request $requete, OrganismeAmoRepository $organismes): Response
    {
        $vente = $this->ventes->panierOuNouveau($this->utilisateur());
        $donnees = $requete->getPayload();
        try {
            $type = TypeVente::tryFrom((string) $donnees->get('type')) ?? TypeVente::SansOrdonnance;
            $date = trim((string) $donnees->get('ordonnance_date'));
            $organisme = null;
            if ($donnees->getInt('organisme') > 0) {
                $organisme = $organismes->find($donnees->getInt('organisme')) ?? throw new VenteException('Organisme inconnu.');
            }
            $this->ventes->definirVente($vente, $type, $vente->getClient(), [
                'numero' => (string) $donnees->get('ordonnance_numero'),
                'date' => '' === $date ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: throw new VenteException('Date d\'ordonnance invalide.')),
                'prescripteur' => (string) $donnees->get('ordonnance_prescripteur'),
                'structure' => (string) $donnees->get('ordonnance_structure'),
            ], self::fichier($requete, 'ordonnance_copie'), $organisme, (string) $donnees->get('numero_assure'));
            if ($donnees->has('remise_valeur')) {
                $this->ventes->remiseGlobale($vente, TypeRemise::tryFrom((string) $donnees->get('remise_type')), $this->montant($donnees->get('remise_valeur'), 'Remise') ?? 0);
            }
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_caisse');
    }

    #[Route('/client', name: 'app_caisse_client', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function client(Request $requete, ClientRepository $clients): Response
    {
        $vente = $this->ventes->panierOuNouveau($this->utilisateur());
        $id = $requete->getPayload()->getInt('client');
        $client = null;
        if ($id > 0) {
            $client = $clients->find($id);
            if (!$client instanceof Client) {
                throw $this->createNotFoundException();
            }
            $this->exigerMemePharmacie($client);
        }
        try {
            $this->ventes->definirVente($vente, $vente->getType(), $client, [
                'numero' => $vente->getOrdonnance()?->getNumero(),
                'date' => $vente->getOrdonnance()?->getDate(),
                'prescripteur' => $vente->getOrdonnance()?->getPrescripteur(),
                'structure' => $vente->getOrdonnance()?->getStructure(),
            ], null, $vente->getOrganismeAmo(), $vente->getNumeroAssure());
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_caisse');
    }

    #[Route('/attente', name: 'app_caisse_attente', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function attente(Request $requete): Response
    {
        $vente = $this->ventes->panier($this->utilisateur());
        try {
            if (null === $vente) {
                throw new VenteException('Le panier est vide : rien à mettre en attente.');
            }
            $this->ventes->mettreEnAttente($vente, (string) $requete->getPayload()->get('repere'));
            $this->addFlash('success', 'Vente mise en attente : vous pouvez servir le client suivant.');
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_caisse');
    }

    #[Route('/reprendre/{id}', name: 'app_caisse_reprendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function reprendre(Vente $vente): Response
    {
        $this->exigerMemePharmacie($vente);
        try {
            $this->ventes->reprendre($vente, $this->utilisateur());
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_caisse');
    }

    #[Route('/abandonner', name: 'app_caisse_abandonner', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function abandonner(): Response
    {
        $vente = $this->ventes->panier($this->utilisateur());
        if (null !== $vente) {
            $this->ventes->abandonner($vente);
            $this->addFlash('info', 'Panier vidé.');
        }

        return $this->redirectToRoute('app_caisse');
    }

    #[Route('/encaisser', name: 'app_caisse_encaisser', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function encaisser(Request $requete): Response
    {
        $utilisateur = $this->utilisateur();
        $vente = $this->ventes->panier($utilisateur);
        $session = $this->caisse->sessionOuverte($utilisateur);
        try {
            if (null === $vente) {
                throw new VenteException('Le panier est vide.');
            }
            if (null === $session) {
                throw new VenteException('Ouvrez votre session de caisse avant d\'encaisser.');
            }
            /** @var array<array-key, mixed> $paiements */
            $paiements = $requete->getPayload()->all('paiement');
            $this->ventes->encaisser($vente, $utilisateur, $session, $paiements, (string) $requete->getPayload()->get('code_pin'));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_caisse');
        }

        $monnaie = $vente->getMonnaieRendue();
        $this->addFlash('success', \sprintf('Vente %s encaissée : %s.%s', $vente->getNumero(), Fcfa::format($vente->getMontantEncaisse()), $monnaie > 0 ? ' Monnaie à rendre : '.Fcfa::format($monnaie).'.' : ''));

        return $this->redirectToRoute('app_vente_voir', ['id' => $vente->getId(), 'caisse' => 1]);
    }

    /**
     * Le vendeur valide la vente sans l'encaisser : elle part dans la file du caissier, qui encaisse
     * pendant que le vendeur sert le client suivant.
     */
    #[Route('/envoyer', name: 'app_caisse_envoyer', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function envoyer(Request $requete): Response
    {
        $vente = $this->ventes->panier($this->utilisateur());
        try {
            if (null === $vente) {
                throw new VenteException('Le panier est vide.');
            }
            $this->ventes->envoyerEnCaisse($vente, $this->utilisateur(), (string) $requete->getPayload()->get('code_pin'));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_caisse');
        }

        $this->addFlash('success', \sprintf('Vente %s envoyée à la caisse : le client règle %s auprès du caissier.', $vente->getNumero(), Fcfa::format($vente->getMontantEncaisse())));

        return $this->redirectToRoute('app_caisse');
    }

    /**
     * Changement rapide de vendeur au comptoir par code PIN (PH-04).
     */
    #[Route('/changer-vendeur', name: 'app_caisse_changer_vendeur', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function changerVendeur(Request $requete, AffectationRepository $affectations, CodePin $codePin, Security $security): Response
    {
        $id = $requete->getPayload()->getInt('utilisateur');
        $cible = null;
        foreach ($affectations->equipe($this->pharmacie()) as $affectation) {
            if ($affectation->getUtilisateur()->getId() === $id && $affectation->isActif() && $affectation->getUtilisateur()->isActif()) {
                $cible = $affectation->getUtilisateur();
            }
        }
        try {
            if (null === $cible || !$codePin->verifier($cible, (string) $requete->getPayload()->get('code_pin'))) {
                throw new CodePinException('Code PIN incorrect.');
            }
        } catch (CodePinException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_caisse');
        }

        $security->login($cible, 'form_login', 'main');
        $this->addFlash('success', \sprintf('Bonjour %s : la caisse est à vous.', $cible->getNom()));

        return $this->redirectToRoute('app_caisse');
    }

    private static function fichier(Request $requete, string $champ): ?UploadedFile
    {
        $fichier = $requete->files->get($champ);

        return $fichier instanceof UploadedFile ? $fichier : null;
    }

    private function ajouterAuPanier(Produit $produit, int $quantite): void
    {
        try {
            $vente = $this->ventes->panierOuNouveau($this->utilisateur());
            $this->ventes->ajouter($vente, $produit, $quantite);
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }
    }

    private function exigerMonPanier(Vente $vente): void
    {
        $this->exigerMemePharmacie($vente);
        if ($vente->getVendeur()->getId() !== $this->utilisateur()->getId() || !$vente->estModifiable()) {
            throw $this->createNotFoundException();
        }
    }

    private function montant(mixed $valeur, string $libelle): ?int
    {
        $texte = str_replace([' ', "\u{00A0}"], '', trim(\is_scalar($valeur) ? (string) $valeur : ''));
        if ('' === $texte) {
            return null;
        }
        if (!ctype_digit($texte) || \strlen($texte) > 9) {
            throw new VenteException(\sprintf('%s : saisissez un nombre entier positif.', $libelle));
        }

        return (int) $texte;
    }
}
