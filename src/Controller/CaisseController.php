<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\LigneVente;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\TypeRemise;
use App\Enum\TypeVente;
use App\Form\ClientType;
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
use App\Vente\AccordProprietaire;
use App\Vente\GestionCaisse;
use App\Vente\PosteCaisse;
use App\Vente\VenteException;
use App\Vente\VenteService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran de caisse (VE-01) : recherche produit (nom, DCI, code-barres), panier, type de vente, client,
 * remises, encaissement ou envoi à la caisse, et mise en attente. Chaque action est un formulaire classique suivi d'une
 * redirection : l'écran reste utilisable au clavier et à la douchette, même sans JavaScript.
 *
 * Chaque vente est validée avec le code PIN de celui qui la fait, même s'il est déjà connecté : la vente est à son nom
 * et, si c'est un collègue vendeur, il prend la main sur le poste. On encaisse dans la caisse du poste, partagée par
 * toute l'équipe.
 */
#[Route('/caisse')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class CaisseController extends AbstractAppController
{
    private const CSRF = 'caisse';

    public function __construct(
        private readonly VenteService $ventes,
        private readonly GestionCaisse $caisse,
        private readonly PosteCaisse $poste,
        private readonly AffectationRepository $affectations,
    ) {
    }

    #[Route('', name: 'app_caisse', methods: ['GET'])]
    public function index(
        ProduitRepository $produits,
        ClientRepository $clients,
        LotRepository $lots,
        VenteRepository $ventesRepo,
        OrganismeAmoRepository $organismes,
        ParametresPharmacie $parametres,
        StockService $stock,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $client = null,
    ): Response {
        $utilisateur = $this->utilisateur();
        // Sans caisse ouverte, le vendeur prépare ses ventes et les envoie à la caisse.
        $caisse = $this->poste->caisse($this->pharmacie());
        $session = $this->caisse->sessionOuverte($caisse);

        $panier = $this->ventes->panier($utilisateur);
        $resultats = null !== $q && '' !== trim($q) ? $produits->rechercher($q, null, false, 1)->elements : [];
        $dansPanier = null !== $panier ? $panier->getLignes()->map(static fn (LigneVente $l) => $l->getProduit())->toArray() : [];
        $trouves = null !== $client && '' !== trim($client) ? $clients->rechercher($client, false, false, 1)->elements : [];

        // La fenêtre « Nouveau client » reprend la saisie de la recherche restée sans résultat.
        $nouveau = new Client();
        if (null !== $client && '' !== trim($client) && [] === $trouves) {
            preg_match('/^[\d\s+.-]+$/', trim($client)) ? $nouveau->setTelephone(trim($client)) : $nouveau->setNom(trim($client));
        }

        return $this->render('caisse/index.html.twig', [
            'session' => $session,
            'caisse' => $caisse,
            'caisses' => $this->caisse->caisses($this->pharmacie()),
            'vendeurs_vente' => $this->vendeursDeLaVente(),
            'panier' => $panier,
            'totaux' => $panier?->calculer(),
            'controle' => null !== $panier ? $this->ventes->controler($panier, $utilisateur, $this->pharmacie()) : null,
            'parametres' => $parametres->pour($this->pharmacie()),
            'q' => $q,
            'resultats' => $resultats,
            'stocks' => $lots->syntheseParProduit([...$resultats, ...$dansPanier], $stock->aujourdhui()),
            'recherche_client' => $client,
            'clients' => $trouves,
            'formulaire_client' => $this->formulaireClient($nouveau)->createView(),
            'en_attente' => $ventesRepo->enAttente(),
            'a_encaisser' => $ventesRepo->aEncaisser(),
            'types' => TypeVente::cases(),
            'organismes' => $organismes->actifs(),
            'aujourdhui' => $stock->aujourdhui(),
        ]);
    }

    /**
     * Saisie de la zone de recherche : un code-barres exact (douchette) ajoute directement le produit,
     * sinon on affiche les résultats.
     */
    #[Route('/scanner', name: 'app_caisse_scanner', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function scanner(Request $requete, ProduitRepository $produits): Response
    {
        $saisie = trim((string) $requete->getPayload()->get('q'));
        if ('' === $saisie) {
            return $this->redirectToRoute('app_caisse');
        }
        $produit = 1 === preg_match('/^[0-9A-Za-z\-\s]{4,}$/', $saisie) ? $produits->parCodeBarres($saisie) : null;
        if (null === $produit || !$produit->isActif()) {
            return $this->redirectToRoute('app_caisse', ['q' => $saisie]);
        }
        $this->ajouterAuPanier($produit, 1);

        return $this->redirectToRoute('app_caisse');
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
                if ($donnees->has('accord')) {
                    $this->ventes->accordLigne($ligne, (string) $donnees->get('accord'));
                }
                $copieAccord = self::fichier($requete, 'accord_copie');
                if (null !== $copieAccord) {
                    $this->ventes->copieAccordLigne($ligne, $copieAccord);
                }
                if ($donnees->has('prise_en_charge')) {
                    $this->ventes->priseEnChargeLigne($ligne, $donnees->getBoolean('prise_en_charge'));
                }
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
            if (self::identifiant($donnees->get('organisme')) > 0) {
                $organisme = $organismes->find(self::identifiant($donnees->get('organisme'))) ?? throw new VenteException('Organisme inconnu.');
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
        $id = self::identifiant($requete->getPayload()->get('client'));
        $client = null;
        if ($id > 0) {
            $client = $clients->find($id);
            if (!$client instanceof Client) {
                throw $this->createNotFoundException();
            }
            $this->exigerMemePharmacie($client);
        }
        try {
            $this->ventes->definirVente($vente, $vente->getType(), $client, self::ordonnance($vente), null, $vente->getOrganismeAmo(), $vente->getNumeroAssure());
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_caisse');
    }

    /**
     * Création d'un client depuis la fenêtre de la caisse : il est enregistré puis choisi pour la vente en cours,
     * sans perdre le type de vente ni l'ordonnance. En cas d'erreur, seul le formulaire est renvoyé (422) pour
     * être réaffiché dans la fenêtre.
     */
    #[Route('/client/nouveau', name: 'app_caisse_client_nouveau', methods: ['POST'])]
    public function nouveauClient(Request $requete): Response
    {
        $client = new Client();
        $formulaire = $this->formulaireClient($client);
        $formulaire->handleRequest($requete);
        if (!$formulaire->isSubmitted() || !$formulaire->isValid()) {
            return $this->render('caisse/_nouveau_client.html.twig', ['formulaire_client' => $formulaire->createView()], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->entityManager->persist($client);
        $this->entityManager->flush();
        $vente = $this->ventes->panierOuNouveau($this->utilisateur());
        try {
            $this->ventes->definirVente($vente, $vente->getType(), $client, self::ordonnance($vente), null, $vente->getOrganismeAmo(), $vente->getNumeroAssure());
            $this->addFlash('success', \sprintf('Client %s créé et choisi pour la vente.', $client->getNom()));
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

    /**
     * Demande l'accord du propriétaire pour le panier : il répond depuis son propre compte, sans donner son code PIN.
     */
    #[Route('/accord', name: 'app_caisse_accord', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function demanderAccord(AccordProprietaire $accords): Response
    {
        $vente = $this->ventes->panier($this->utilisateur());
        try {
            if (null === $vente) {
                throw new VenteException('Le panier est vide.');
            }
            $accords->demander($vente, $this->utilisateur(), $this->pharmacie());
            $this->addFlash('success', 'Demande envoyée au propriétaire : dès qu\'il a répondu depuis son compte, validez la vente (sans son code PIN).');
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
    public function encaisser(Request $requete, CodePin $codePin, Security $security): Response
    {
        $vente = $this->ventes->panier($this->utilisateur());
        $session = $this->poste->session($this->pharmacie());
        try {
            if (null === $vente) {
                throw new VenteException('Le panier est vide.');
            }
            if (null === $session) {
                throw new VenteException('Ouvrez la caisse avant d\'encaisser.');
            }
            $vendeur = $this->vendeurParPin($requete, $codePin);
            /** @var array<array-key, mixed> $paiements */
            $paiements = $requete->getPayload()->all('paiement');
            $this->ventes->encaisser($vente, $vendeur, $session, $paiements);
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_caisse');
        }
        $this->prendreLaMain($vendeur, $security);

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
    public function envoyer(Request $requete, CodePin $codePin, Security $security): Response
    {
        $vente = $this->ventes->panier($this->utilisateur());
        try {
            if (null === $vente) {
                throw new VenteException('Le panier est vide.');
            }
            $vendeur = $this->vendeurParPin($requete, $codePin);
            $this->ventes->envoyerEnCaisse($vente, $vendeur);
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_caisse');
        }
        $this->prendreLaMain($vendeur, $security);

        $this->addFlash('success', \sprintf('Vente %s envoyée à la caisse : le client règle %s auprès du caissier.', $vente->getNumero(), Fcfa::format($vente->getMontantEncaisse())));

        return $this->redirectToRoute('app_caisse');
    }

    /**
     * Qui peut valider une vente par son code PIN à ce poste : l'utilisateur connecté et les vendeurs de l'équipe.
     * Le propriétaire et l'adjoint ne sont proposés que s'ils sont eux-mêmes connectés (avec leur mot de passe) :
     * un code PIN à 4 chiffres ne doit pas ouvrir leurs droits (finances, équipe, export). Le code PIN demandé à chaque
     * vente remplace l'ancien bouton « Changer de vendeur ».
     *
     * @return list<Utilisateur>
     */
    private function vendeursDeLaVente(): array
    {
        $connecte = $this->utilisateur();
        $vendeurs = $connecte->aUnCodePin() ? [$connecte] : [];
        foreach ($this->equipeActive() as $membre) {
            if (Utilisateur::ROLE_VENDEUR === $membre->getRole() && $membre->aUnCodePin() && $membre->getId() !== $connecte->getId()) {
                $vendeurs[] = $membre;
            }
        }

        return $vendeurs;
    }

    /**
     * Le vendeur choisi pour la vente, s'il a saisi son bon code PIN.
     *
     * @throws VenteException
     */
    private function vendeurParPin(Request $requete, CodePin $codePin): Utilisateur
    {
        $vendeurs = $this->vendeursDeLaVente();
        if ([] === $vendeurs) {
            throw new VenteException('Chaque vente se valide avec le code PIN du vendeur : définissez d\'abord votre code PIN (menu de votre profil, « Mon code PIN »).');
        }
        $id = self::identifiant($requete->getPayload()->get('vendeur')) ?: $this->utilisateur()->getId();
        $vendeur = null;
        foreach ($vendeurs as $candidat) {
            $vendeur = $candidat->getId() === $id ? $candidat : $vendeur;
        }
        $code = trim((string) $requete->getPayload()->get('pin_vendeur'));
        if ('' === $code) {
            throw new VenteException('Saisissez le code PIN du vendeur pour valider la vente.');
        }
        try {
            if (null === $vendeur || !$codePin->verifier($vendeur, $code)) {
                throw new VenteException('Code PIN du vendeur incorrect : la vente n\'est pas validée.');
            }
        } catch (CodePinException $e) {
            throw new VenteException($e->getMessage(), 0, $e);
        }

        return $vendeur;
    }

    /** Le collègue qui vient de vendre avec son code PIN prend la main sur le poste. */
    private function prendreLaMain(Utilisateur $vendeur, Security $security): void
    {
        if ($vendeur->getId() !== $this->utilisateur()->getId()) {
            $security->login($vendeur, 'form_login', 'main');
            $this->addFlash('info', \sprintf('Bonjour %s : la caisse est à vous.', $vendeur->getNom()));
        }
    }

    /**
     * Membres actifs de l'équipe de la pharmacie.
     *
     * @return list<Utilisateur>
     */
    private function equipeActive(): array
    {
        $membres = [];
        foreach ($this->affectations->equipe($this->pharmacie()) as $affectation) {
            if ($affectation->isActif() && $affectation->getUtilisateur()->isActif()) {
                $membres[] = $affectation->getUtilisateur();
            }
        }

        return $membres;
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

    /**
     * @return FormInterface<Client>
     */
    private function formulaireClient(Client $client): FormInterface
    {
        return $this->createForm(ClientType::class, $client, [
            'peut_privilegier' => $this->isGranted(Utilisateur::ROLE_ADJOINT),
            'action' => $this->generateUrl('app_caisse_client_nouveau'),
        ]);
    }

    /**
     * Identifiant choisi dans une liste ; une valeur vide (« aucun ») vaut 0 au lieu d'une erreur 400.
     */
    private static function identifiant(mixed $valeur): int
    {
        return \is_scalar($valeur) ? (int) filter_var($valeur, \FILTER_VALIDATE_INT) : 0;
    }

    /**
     * L'ordonnance déjà saisie, reprise telle quelle quand seul le client change.
     *
     * @return array{numero: ?string, date: ?\DateTimeImmutable, prescripteur: ?string, structure: ?string}
     */
    private static function ordonnance(Vente $vente): array
    {
        return [
            'numero' => $vente->getOrdonnance()?->getNumero(),
            'date' => $vente->getOrdonnance()?->getDate(),
            'prescripteur' => $vente->getOrdonnance()?->getPrescripteur(),
            'structure' => $vente->getOrdonnance()?->getStructure(),
        ];
    }
}
