<?php

namespace App\Controller;

use App\Entity\OrganismeAmo;
use App\Entity\Utilisateur;
use App\Entity\VenteHorsLigne;
use App\Enum\ModePaiement;
use App\Repository\LotRepository;
use App\Repository\OrganismeAmoRepository;
use App\Repository\ProduitRepository;
use App\Repository\VenteHorsLigneRepository;
use App\Service\ParametresPharmacie;
use App\Stock\StockService;
use App\Vente\GestionCaisse;
use App\Vente\HorsLigneService;
use App\Vente\ImportInterrompuException;
use App\Vente\VenteException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Caisse hors ligne : l'écran qui reste utilisable pendant une coupure d'internet (gardé sur le poste par le
 * service worker), la copie du catalogue qu'il emporte, l'envoi des ventes au retour du réseau, et le suivi
 * des ventes reçues (anomalies à reprendre ou à rejeter).
 */
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class CaisseHorsLigneController extends AbstractAppController
{
    public const CSRF = 'caisse-hors-ligne';
    private const CSRF_SUIVI = 'vente-hors-ligne';

    public function __construct(
        private readonly HorsLigneService $horsLigne,
    ) {
    }

    /**
     * La page ne contient aucune donnée : elle lit la copie du catalogue gardée sur le poste, ce qui permet
     * de la servir depuis le cache pendant une coupure.
     */
    #[Route('/caisse/hors-ligne', name: 'app_caisse_hors_ligne', methods: ['GET'])]
    public function index(): Response
    {
        $reponse = $this->render('caisse/hors_ligne.html.twig');
        $reponse->headers->set('Cache-Control', 'no-cache, private');

        return $reponse;
    }

    /**
     * Copie du catalogue (prix, stock vendable, règles AMO) emportée par le poste, avec un jeton pour l'envoi.
     */
    #[Route('/caisse/hors-ligne/donnees', name: 'app_caisse_hors_ligne_donnees', methods: ['GET'])]
    public function donnees(
        ProduitRepository $produits,
        LotRepository $lots,
        OrganismeAmoRepository $organismes,
        ParametresPharmacie $parametres,
        GestionCaisse $caisse,
        StockService $stock,
        ClockInterface $horloge,
        CsrfTokenManagerInterface $jetons,
    ): JsonResponse {
        $pharmacie = $this->pharmacie();
        $utilisateur = $this->utilisateur();
        $stocks = $lots->stockVendableParProduit($stock->aujourdhui());

        $catalogue = [];
        foreach ($produits->catalogueHorsLigne() as $p) {
            $details = trim(implode(' ', array_filter([$p['dci'], $p['dosage']])));
            if (null !== $p['forme']) {
                $details = '' === $details ? $p['forme'] : $details.', '.mb_strtolower($p['forme']);
            }
            // Format compact : le catalogue peut compter des milliers de produits.
            $catalogue[] = [
                $p['id'],
                $p['nomCommercial'],
                $details,
                $p['codeBarres'],
                $p['prixVente'],
                $p['prixVenteAmo'],
                $p['remboursableAmo'] ? 1 : 0,
                $p['ordonnanceObligatoire'] ? 1 : 0,
                $stocks[$p['id']] ?? 0,
            ];
        }

        $reponse = $this->json([
            'genereLe' => $horloge->now()->format(\DATE_ATOM),
            'jeton' => $jetons->getToken(self::CSRF)->getValue(),
            'utilisateur' => ['id' => $utilisateur->getId(), 'nom' => $utilisateur->getNom(), 'proprietaire' => $utilisateur->isProprietaire()],
            'pharmacie' => [
                'id' => $pharmacie->getId(),
                'nom' => $pharmacie->getNom(),
                'adresse' => trim($pharmacie->getAdresse().', '.$pharmacie->getVille(), ', '),
                'telephone' => $pharmacie->getTelephone(),
            ],
            'sessionOuverte' => null !== $caisse->sessionOuverte($utilisateur),
            'politiqueSansOrdonnance' => $parametres->pour($pharmacie)->getPolitiqueSansOrdonnance()->value,
            'modes' => array_map(static fn (ModePaiement $m) => ['code' => $m->value, 'libelle' => $m->libelle()], ModePaiement::horsEspeces()),
            'organismes' => array_map(static fn (OrganismeAmo $o) => [
                'id' => $o->getId(),
                'nom' => $o->getNom(),
                'taux' => $parametres->tauxAmo($o),
                'tarifAmo' => $o->appliqueTarifAmo(),
            ], $organismes->actifs()),
            'produits' => $catalogue,
        ]);
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }

    /**
     * Ventes faites hors ligne, envoyées par le poste au retour du réseau. Le poste n'efface une vente
     * qu'une fois qu'elle figure dans la réponse (importée, en anomalie ou refusée).
     */
    #[Route('/caisse/hors-ligne/synchroniser', name: 'app_caisse_hors_ligne_synchroniser', methods: ['POST'])]
    public function synchroniser(Request $requete): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $requete->headers->get('X-CSRF-Token'))) {
            return $this->json(['erreur' => 'Session expirée : rechargez la page.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $corps = $requete->toArray();
        } catch (\Throwable) {
            return $this->json(['erreur' => 'Envoi illisible.'], Response::HTTP_BAD_REQUEST);
        }
        $ventes = \is_array($corps['ventes'] ?? null) ? array_values($corps['ventes']) : [];

        return $this->json(['resultats' => $this->horsLigne->recevoir($ventes, $this->utilisateur())]);
    }

    /** Ventes hors ligne reçues : toutes pour l'adjoint et le propriétaire, les siennes pour un vendeur. */
    #[Route('/ventes/hors-ligne', name: 'app_hors_ligne_index', methods: ['GET'])]
    public function suivi(VenteHorsLigneRepository $ventes, #[MapQueryParameter] int $page = 1): Response
    {
        $adjoint = $this->isGranted(Utilisateur::ROLE_ADJOINT);

        return $this->render('vente_hors_ligne/index.html.twig', [
            'ventes' => $ventes->liste($adjoint ? null : $this->utilisateur(), max(1, $page)),
            'adjoint' => $adjoint,
            'csrf' => self::CSRF_SUIVI,
        ]);
    }

    #[Route('/ventes/hors-ligne/{id}/reessayer', name: 'app_hors_ligne_reessayer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_SUIVI)]
    public function reessayer(VenteHorsLigne $vente): Response
    {
        $this->exigerAcces($vente);
        try {
            $importee = $this->horsLigne->importer($vente);
        } catch (ImportInterrompuException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_hors_ligne_index');
        }
        $importee
            ? $this->addFlash('success', \sprintf('Ticket %s importé : vente %s.', $vente->getNumeroProvisoire(), $vente->getVente()?->getNumero()))
            : $this->addFlash('error', \sprintf('Ticket %s toujours en anomalie : %s', $vente->getNumeroProvisoire(), $vente->getMessage()));

        return $this->redirectToRoute('app_hors_ligne_index');
    }

    #[Route('/ventes/hors-ligne/{id}/rejeter', name: 'app_hors_ligne_rejeter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(self::CSRF_SUIVI)]
    public function rejeter(VenteHorsLigne $vente, Request $requete): Response
    {
        $this->exigerMemePharmacie($vente);
        try {
            $this->horsLigne->rejeter($vente, (string) $requete->getPayload()->get('motif'));
            $this->addFlash('success', \sprintf('Ticket %s rejeté.', $vente->getNumeroProvisoire()));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_hors_ligne_index');
    }

    private function exigerAcces(VenteHorsLigne $vente): void
    {
        $this->exigerMemePharmacie($vente);
        if (!$this->isGranted(Utilisateur::ROLE_ADJOINT) && $vente->getVendeur()->getId() !== $this->utilisateur()->getId()) {
            throw $this->createAccessDeniedException();
        }
    }
}
