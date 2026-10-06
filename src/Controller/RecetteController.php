<?php

namespace App\Controller;

use App\Entity\Recette;
use App\Entity\Utilisateur;
use App\Enum\ModeReglement;
use App\Enum\OrigineRecette;
use App\Finance\FinanceException;
use App\Finance\RecetteService;
use App\Reporting\Periode;
use App\Repository\RecetteRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Journal des recettes (FI-04) et recettes manuelles (FI-05). Réservé au propriétaire.
 */
#[Route('/recettes')]
#[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
final class RecetteController extends AbstractAppController
{
    private const CSRF_NOUVELLE = 'recette-nouvelle';

    public function __construct(
        private readonly RecetteService $service,
        private readonly ClockInterface $horloge,
    ) {
    }

    #[Route('', name: 'app_recette_index', methods: ['GET'])]
    public function index(Request $requete, RecetteRepository $recettes, #[MapQueryParameter] ?string $origine = null, #[MapQueryParameter] int $page = 1): Response
    {
        $periode = Periode::depuisRequete(
            $requete->query->getString('periode'),
            $requete->query->getString('date'),
            $requete->query->getString('du'),
            $requete->query->getString('au'),
            $this->horloge->now(),
        );
        $filtre = OrigineRecette::tryFrom((string) $origine);

        return $this->render('recette/index.html.twig', [
            'periode' => $periode,
            'recettes' => $recettes->liste($periode->debut, $periode->fin, $filtre, $page),
            'total' => $recettes->total($periode->debut, $periode->fin),
            'par_origine' => $recettes->parOrigine($periode->debut, $periode->fin),
            'origines' => OrigineRecette::cases(),
            'origine' => $filtre?->value,
            'modes' => ModeReglement::cases(),
            'aujourdhui' => $this->horloge->now(),
            'csrf_nouvelle' => self::CSRF_NOUVELLE,
        ]);
    }

    #[Route('', name: 'app_recette_nouvelle', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_NOUVELLE)]
    public function nouvelle(Request $requete): Response
    {
        $donnees = $requete->getPayload();
        try {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $donnees->get('date'));
            $montant = str_replace([' ', "\u{00A0}"], '', (string) $donnees->get('montant'));
            $mode = ModeReglement::tryFrom((string) $donnees->get('mode'));
            if (false === $date) {
                throw new FinanceException('La date de la recette est obligatoire.');
            }
            if (!ctype_digit($montant)) {
                throw new FinanceException('Le montant de la recette doit être positif.');
            }
            if (null === $mode) {
                throw new FinanceException('Choisissez le mode de paiement.');
            }
            $recette = $this->service->enregistrerManuelle($date, (string) $donnees->get('libelle'), (int) $montant, $mode);
            $this->addFlash('success', \sprintf('Recette « %s » enregistrée.', $recette->getLibelle()));

            return $this->redirectToRoute('app_recette_index', ['periode' => Periode::MOIS, 'date' => $recette->getDate()->format('Y-m-d')]);
        } catch (FinanceException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_recette_index');
        }
    }

    #[Route('/{id}/annuler', name: 'app_recette_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"recette-" ~ args["recette"].getId()'))]
    public function annuler(Recette $recette, Request $requete): Response
    {
        $this->exigerMemePharmacie($recette);
        try {
            $this->service->annulerManuelle($recette, (string) $requete->getPayload()->get('motif'));
            $this->addFlash('success', \sprintf('Recette « %s » annulée par contre-passation.', $recette->getLibelle()));
        } catch (FinanceException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_recette_index', ['periode' => Periode::MOIS, 'date' => $recette->getDate()->format('Y-m-d')]);
    }
}
