<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\VenteRepository;
use App\Util\Fcfa;
use App\Vente\GestionCaisse;
use App\Vente\VenteException;
use App\Vente\VenteService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran du caissier : ouverture de sa caisse, file des ventes que les vendeurs ont validées et envoyées
 * à la caisse, encaissement et annulation d'une vente que le client n'a pas payée. Le vendeur, qui hérite
 * du droit d'encaisser, peut aussi s'en servir.
 */
#[IsGranted(Utilisateur::ROLE_CAISSIER)]
final class EncaissementController extends AbstractAppController
{
    private const CSRF = 'caisse';

    public function __construct(
        private readonly VenteService $ventes,
        private readonly GestionCaisse $caisse,
    ) {
    }

    #[Route('/encaissement', name: 'app_encaissement_index', methods: ['GET'])]
    public function index(VenteRepository $ventesRepo): Response
    {
        $session = $this->caisse->sessionOuverte($this->utilisateur());
        if (null === $session) {
            return $this->render('caisse/ouvrir.html.twig', ['retour' => 'encaissement']);
        }

        return $this->render('encaissement/index.html.twig', [
            'session' => $session,
            'ventes' => $ventesRepo->aEncaisser(),
        ]);
    }

    #[Route('/encaissement/{id}', name: 'app_encaissement_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Vente $vente): Response
    {
        $this->exigerMemePharmacie($vente);
        if (!$vente->estAEncaisser()) {
            // Déjà encaissée (par un autre caissier) ou annulée : sa fiche le dit.
            if (null === $vente->getNumero()) {
                throw $this->createNotFoundException();
            }
            $this->addFlash('info', \sprintf('La vente %s n\'est plus à encaisser.', $vente->getNumero()));

            return $this->redirectToRoute('app_vente_voir', ['id' => $vente->getId()]);
        }
        $session = $this->caisse->sessionOuverte($this->utilisateur());
        if (null === $session) {
            return $this->render('caisse/ouvrir.html.twig', ['retour' => 'encaissement']);
        }

        return $this->render('encaissement/voir.html.twig', ['vente' => $vente, 'session' => $session]);
    }

    #[Route('/encaissement/{id}', name: 'app_encaissement_encaisser', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function encaisser(Vente $vente, Request $requete): Response
    {
        $this->exigerMemePharmacie($vente);
        $utilisateur = $this->utilisateur();
        $session = $this->caisse->sessionOuverte($utilisateur);
        try {
            if (null === $session) {
                throw new VenteException('Ouvrez votre session de caisse avant d\'encaisser.');
            }
            /** @var array<array-key, mixed> $paiements */
            $paiements = $requete->getPayload()->all('paiement');
            $this->ventes->encaisserEnCaisse($vente, $utilisateur, $session, $paiements);
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());

            return $vente->estAEncaisser()
                ? $this->redirectToRoute('app_encaissement_voir', ['id' => $vente->getId()])
                : $this->redirectToRoute('app_encaissement_index');
        }

        $monnaie = $vente->getMonnaieRendue();
        $this->addFlash('success', \sprintf('Vente %s encaissée : %s.%s', $vente->getNumero(), Fcfa::format($vente->getMontantEncaisse()), $monnaie > 0 ? ' Monnaie à rendre : '.Fcfa::format($monnaie).'.' : ''));

        return $this->redirectToRoute('app_vente_voir', ['id' => $vente->getId(), 'caisse' => 1]);
    }

    /** Le client est reparti sans payer : la vente est annulée et ses produits reviennent en stock. */
    #[Route('/encaissement/{id}/annuler', name: 'app_encaissement_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function annuler(Vente $vente, Request $requete): Response
    {
        $this->exigerAEncaisser($vente);
        try {
            $this->ventes->annuler($vente, (string) $requete->getPayload()->get('motif'));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_encaissement_voir', ['id' => $vente->getId()]);
        }
        $this->addFlash('success', \sprintf('Vente %s annulée : les produits sont revenus en stock.', $vente->getNumero()));

        return $this->redirectToRoute('app_encaissement_index');
    }

    /**
     * Ouverture de sa caisse avec le fond de caisse, depuis l'écran de vente ou celui d'encaissement.
     */
    #[Route('/caisse/ouvrir', name: 'app_caisse_ouvrir', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF)]
    public function ouvrir(Request $requete): Response
    {
        try {
            $fond = $this->montant($requete->getPayload()->get('fond'));
            $session = $this->caisse->ouvrir($this->pharmacie(), $this->utilisateur(), $fond);
            $this->addFlash('success', \sprintf('Caisse ouverte (session %s, fond de caisse %s).', $session->getNumero(), Fcfa::format($fond)));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        $versVente = 'encaissement' !== $requete->getPayload()->get('retour') && $this->isGranted(Utilisateur::ROLE_VENDEUR);

        return $this->redirectToRoute($versVente ? 'app_caisse' : 'app_encaissement_index');
    }

    private function exigerAEncaisser(Vente $vente): void
    {
        $this->exigerMemePharmacie($vente);
        if (!$vente->estAEncaisser()) {
            throw $this->createNotFoundException();
        }
    }

    private function montant(mixed $valeur): int
    {
        $texte = str_replace([' ', "\u{00A0}"], '', trim(\is_scalar($valeur) ? (string) $valeur : ''));
        if ('' === $texte) {
            return 0;
        }
        if (!ctype_digit($texte) || \strlen($texte) > 9) {
            throw new VenteException('Fond de caisse : saisissez un nombre entier positif.');
        }

        return (int) $texte;
    }
}
