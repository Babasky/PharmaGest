<?php

namespace App\Controller;

use App\Amo\AmoException;
use App\Amo\CopieOrdonnance;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\StatutVente;
use App\Pdf\VentePdf;
use App\Repository\CreanceAmoRepository;
use App\Repository\VenteRepository;
use App\Vente\VenteException;
use App\Vente\VenteService;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ventes validées et annulées : consultation, ticket et facture (VE-06), annulation (VE-09),
 * copie de l'ordonnance (AM-01).
 */
#[Route('/ventes')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class VenteController extends AbstractAppController
{
    #[Route('', name: 'app_vente_index', methods: ['GET'])]
    public function index(
        VenteRepository $ventes,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $jour = null,
        #[MapQueryParameter] ?string $statut = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $date = null !== $jour && '' !== $jour ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $jour) ?: null) : null;
        $filtreStatut = null !== $statut ? StatutVente::tryFrom($statut) : null;
        if (null !== $filtreStatut && !\in_array($filtreStatut, [StatutVente::Validee, StatutVente::Annulee], true)) {
            $filtreStatut = null;
        }

        return $this->render('vente/index.html.twig', [
            'ventes' => $ventes->liste($q, $date, $filtreStatut, $page),
            'q' => $q,
            'jour' => $date?->format('Y-m-d'),
            'statut' => $filtreStatut?->value,
        ]);
    }

    #[Route('/{id}', name: 'app_vente_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Vente $vente, CreanceAmoRepository $creances, #[MapQueryParameter] bool $caisse = false): Response
    {
        $this->exigerVenteEnregistree($vente);
        $creance = $creances->pourVente($vente);

        return $this->render('vente/voir.html.twig', [
            'vente' => $vente,
            'depuis_caisse' => $caisse,
            'creance' => $creance,
            // La copie jointe à un bordereau transmis ne change plus (RG-11).
            'copie_modifiable' => null !== $vente->getOrdonnance() && !($creance?->getBordereau()?->estTransmis() ?? false),
        ]);
    }

    #[Route('/{id}/ordonnance', name: 'app_vente_ordonnance', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ordonnance(Vente $vente, CopieOrdonnance $copies): Response
    {
        $this->exigerVenteEnregistree($vente);
        $ordonnance = $vente->getOrdonnance();
        $chemin = null === $ordonnance ? null : $copies->chemin($this->pharmacie(), $ordonnance);
        if (null === $chemin) {
            throw $this->createNotFoundException();
        }
        $reponse = new BinaryFileResponse($chemin, headers: ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
        $reponse->setContentDisposition('inline', 'ordonnance-'.$vente->getNumero().'.jpg');

        return $reponse;
    }

    #[Route('/{id}/ordonnance', name: 'app_vente_ordonnance_copie', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"copie-ordonnance-" ~ args["vente"].getId()'))]
    public function copieOrdonnance(Vente $vente, Request $requete, CopieOrdonnance $copies, CreanceAmoRepository $creances): Response
    {
        $this->exigerVenteEnregistree($vente);
        $ordonnance = $vente->getOrdonnance() ?? throw $this->createNotFoundException();
        $fichier = $requete->files->get('copie');
        try {
            if ($creances->pourVente($vente)?->getBordereau()?->estTransmis() ?? false) {
                throw new AmoException('Le bordereau de cette vente est transmis : la copie de l\'ordonnance ne change plus.');
            }
            if (!$fichier instanceof UploadedFile) {
                throw new AmoException('Choisissez la photo de l\'ordonnance.');
            }
            $copies->enregistrer($this->pharmacie(), $ordonnance, $fichier);
            $this->entityManager->flush();
            $this->addFlash('success', 'Copie de l\'ordonnance enregistrée.');
        } catch (AmoException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_vente_voir', ['id' => $vente->getId()]);
    }

    #[Route('/{id}/ticket', name: 'app_vente_ticket', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ticket(Vente $vente, VentePdf $pdf): Response
    {
        $this->exigerVenteEnregistree($vente);

        return $pdf->reponseTicket($vente);
    }

    #[Route('/{id}/facture', name: 'app_vente_facture', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function facture(Vente $vente, VentePdf $pdf): Response
    {
        $this->exigerVenteEnregistree($vente);

        return $pdf->reponseFacture($vente);
    }

    #[Route('/{id}/annuler', name: 'app_vente_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(new Expression('"annuler-vente-" ~ args["vente"].getId()'))]
    public function annuler(Vente $vente, Request $requete, VenteService $service): Response
    {
        $this->exigerVenteEnregistree($vente);
        try {
            $service->annuler($vente, (string) $requete->getPayload()->get('motif'));
            $this->addFlash('success', \sprintf('Vente %s annulée : les produits sont revenus dans leurs lots d\'origine.', $vente->getNumero()));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_vente_voir', ['id' => $vente->getId()]);
    }

    /** Un panier ou une vente en attente n'est pas une vente : introuvable ici. */
    private function exigerVenteEnregistree(Vente $vente): void
    {
        $this->exigerMemePharmacie($vente);
        if (null === $vente->getNumero()) {
            throw $this->createNotFoundException();
        }
    }
}
