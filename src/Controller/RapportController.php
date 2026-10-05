<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Reporting\ExportRapport;
use App\Reporting\Periode;
use App\Reporting\Rapport;
use App\Reporting\Rapports;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rapports par période (RA-01, RA-05, RA-06, RE-05, RA-02 à RA-04) et leurs exports Excel et PDF (RA-11).
 * Ventes et remises : propriétaire et adjoint ; finances : propriétaire seul (matrice des droits).
 */
#[Route('/rapports')]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class RapportController extends AbstractAppController
{
    private const CODES = Rapports::VENTES.'|'.Rapports::REMISES.'|'.Rapports::FINANCES;

    public function __construct(
        private readonly Rapports $rapports,
        private readonly ExportRapport $export,
        private readonly ClockInterface $horloge,
    ) {
    }

    #[Route('', name: 'app_rapport_index', methods: ['GET'])]
    public function index(Request $requete): Response
    {
        return $this->redirectToRoute('app_rapport_voir', ['code' => Rapports::VENTES, ...$requete->query->all()]);
    }

    #[Route('/{code}', name: 'app_rapport_voir', requirements: ['code' => self::CODES], methods: ['GET'])]
    public function voir(string $code, Request $requete): Response
    {
        $rapport = $this->rapport($code, $requete);

        return $this->render('rapport/voir.html.twig', [
            'rapport' => $rapport,
            'onglets' => array_filter(Rapports::TITRES, fn (string $c) => !\in_array($c, Rapports::FINANCIERS, true) || $this->isGranted(Utilisateur::ROLE_PROPRIETAIRE), \ARRAY_FILTER_USE_KEY),
        ]);
    }

    #[Route('/{code}/excel', name: 'app_rapport_excel', requirements: ['code' => self::CODES], methods: ['GET'])]
    public function excel(string $code, Request $requete): Response
    {
        return $this->export->reponseExcel($this->rapport($code, $requete), $this->pharmacie());
    }

    #[Route('/{code}/pdf', name: 'app_rapport_pdf', requirements: ['code' => self::CODES], methods: ['GET'])]
    public function pdf(string $code, Request $requete): Response
    {
        return $this->export->reponsePdf($this->rapport($code, $requete), $this->pharmacie());
    }

    private function rapport(string $code, Request $requete): Rapport
    {
        if (\in_array($code, Rapports::FINANCIERS, true)) {
            $this->denyAccessUnlessGranted(Utilisateur::ROLE_PROPRIETAIRE);
        }
        $periode = Periode::depuisRequete(
            $requete->query->getString('periode'),
            $requete->query->getString('date'),
            $requete->query->getString('du'),
            $requete->query->getString('au'),
            $this->horloge->now(),
        );

        return $this->rapports->construire($code, $periode);
    }
}
