<?php

namespace App\Controller;

use App\Amo\AmoException;
use App\Amo\ExportBordereau;
use App\Amo\GestionBordereaux;
use App\Amo\SuiviAmo;
use App\Entity\BordereauAmo;
use App\Entity\CreanceAmo;
use App\Entity\OrganismeAmo;
use App\Entity\Utilisateur;
use App\Enum\StatutBordereau;
use App\Enum\StatutCreance;
use App\Repository\BordereauAmoRepository;
use App\Repository\CreanceAmoRepository;
use App\Repository\OrganismeAmoRepository;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Créance AMO : suivi (AM-10), créances (AM-05), bordereaux et exports (AM-06, AM-07), règlements (AM-08).
 * Réservé au propriétaire et à l'adjoint.
 */
#[Route('/amo')]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class AmoController extends AbstractAppController
{
    private const CSRF_NOUVEAU = 'bordereau-nouveau';

    public function __construct(
        private readonly GestionBordereaux $gestion,
        private readonly OrganismeAmoRepository $organismes,
    ) {
    }

    #[Route('', name: 'app_amo_index', methods: ['GET'])]
    public function index(SuiviAmo $suivi): Response
    {
        return $this->render('amo/index.html.twig', [
            'suivi' => $suivi->tableau(),
            'tranches' => array_keys(SuiviAmo::TRANCHES),
        ]);
    }

    #[Route('/creances', name: 'app_amo_creances', methods: ['GET'])]
    public function creances(
        CreanceAmoRepository $creances,
        #[MapQueryParameter] ?string $organisme = null,
        #[MapQueryParameter] ?string $statut = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $filtreOrganisme = ctype_digit((string) $organisme) ? $this->organismes->find((int) $organisme) : null;
        $filtreStatut = StatutCreance::tryFrom((string) $statut);

        return $this->render('amo/creances.html.twig', [
            'creances' => $creances->liste($filtreOrganisme, $filtreStatut, $page),
            'organismes' => $this->organismes->findBy([], ['nom' => 'ASC']),
            'statuts' => StatutCreance::cases(),
            'organisme' => $filtreOrganisme?->getId(),
            'statut' => $filtreStatut?->value,
        ]);
    }

    #[Route('/bordereaux', name: 'app_amo_bordereaux', methods: ['GET'])]
    public function bordereaux(
        BordereauAmoRepository $bordereaux,
        #[MapQueryParameter] ?string $organisme = null,
        #[MapQueryParameter] ?string $statut = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $filtreOrganisme = ctype_digit((string) $organisme) ? $this->organismes->find((int) $organisme) : null;
        $filtreStatut = StatutBordereau::tryFrom((string) $statut);
        $aujourdhui = new \DateTimeImmutable('today');

        return $this->render('amo/bordereaux.html.twig', [
            'bordereaux' => $bordereaux->liste($filtreOrganisme, $filtreStatut, $page),
            'organismes' => $this->organismes->actifs(),
            'tous_organismes' => $this->organismes->findBy([], ['nom' => 'ASC']),
            'statuts' => StatutBordereau::cases(),
            'organisme' => $filtreOrganisme?->getId(),
            'statut' => $filtreStatut?->value,
            'debut_defaut' => $aujourdhui->modify('first day of last month')->format('Y-m-d'),
            'fin_defaut' => $aujourdhui->modify('last day of last month')->format('Y-m-d'),
            'csrf_nouveau' => self::CSRF_NOUVEAU,
        ]);
    }

    #[Route('/bordereaux/nouveau', name: 'app_amo_bordereau_nouveau', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_NOUVEAU)]
    public function nouveau(Request $requete): Response
    {
        $donnees = $requete->getPayload();
        $organisme = $this->organismes->find((int) $donnees->get('organisme'));
        $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $donnees->get('debut')) ?: null;
        $fin = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $donnees->get('fin')) ?: null;
        if (!$organisme instanceof OrganismeAmo || null === $debut || null === $fin) {
            $this->addFlash('error', 'Choisissez l\'organisme et la période du bordereau.');

            return $this->redirectToRoute('app_amo_bordereaux');
        }
        try {
            $bordereau = $this->gestion->creer($organisme, $debut, $fin);
            $this->addFlash('success', \sprintf('Bordereau brouillon créé avec %d créance(s). Vérifiez-le puis transmettez-le.', $bordereau->getCreances()->count()));

            return $this->redirectToRoute('app_amo_bordereau_voir', ['id' => $bordereau->getId()]);
        } catch (AmoException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_amo_bordereaux');
    }

    #[Route('/bordereaux/{id}', name: 'app_amo_bordereau_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(BordereauAmo $bordereau): Response
    {
        $this->exigerMemePharmacie($bordereau);
        $sansCopie = $bordereau->getCreances()->filter(static fn (CreanceAmo $c) => null === $c->getVente()->getOrdonnance()?->getCopie())->count();

        return $this->render('amo/bordereau.html.twig', [
            'bordereau' => $bordereau,
            'sans_copie' => $sansCopie,
            'aujourdhui' => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route('/bordereaux/{id}/actualiser', name: 'app_amo_bordereau_actualiser', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"bordereau-" ~ args["bordereau"].getId()'))]
    public function actualiser(BordereauAmo $bordereau): Response
    {
        $this->exigerMemePharmacie($bordereau);

        return $this->agir($bordereau, function () use ($bordereau): string {
            $ajoutees = $this->gestion->actualiser($bordereau);

            return 0 === $ajoutees ? 'Aucune nouvelle créance pour cette période.' : \sprintf('%d créance(s) ajoutée(s).', $ajoutees);
        });
    }

    #[Route('/bordereaux/{id}/retirer/{creance}', name: 'app_amo_bordereau_retirer', requirements: ['id' => '\d+', 'creance' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"bordereau-" ~ args["bordereau"].getId()'))]
    public function retirer(BordereauAmo $bordereau, CreanceAmo $creance): Response
    {
        $this->exigerMemePharmacie($bordereau);
        $this->exigerMemePharmacie($creance);

        return $this->agir($bordereau, function () use ($bordereau, $creance): string {
            $this->gestion->retirer($bordereau, $creance);

            return \sprintf('Créance de la vente %s retirée : elle reste en attente.', $creance->getVente()->getNumero());
        });
    }

    #[Route('/bordereaux/{id}/supprimer', name: 'app_amo_bordereau_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"bordereau-" ~ args["bordereau"].getId()'))]
    public function supprimer(BordereauAmo $bordereau): Response
    {
        $this->exigerMemePharmacie($bordereau);
        try {
            $this->gestion->supprimer($bordereau);
            $this->addFlash('success', 'Brouillon supprimé : ses créances sont de nouveau en attente.');

            return $this->redirectToRoute('app_amo_bordereaux');
        } catch (AmoException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_amo_bordereau_voir', ['id' => $bordereau->getId()]);
        }
    }

    #[Route('/bordereaux/{id}/transmettre', name: 'app_amo_bordereau_transmettre', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"bordereau-" ~ args["bordereau"].getId()'))]
    public function transmettre(BordereauAmo $bordereau): Response
    {
        $this->exigerMemePharmacie($bordereau);

        return $this->agir($bordereau, function () use ($bordereau): string {
            $this->gestion->transmettre($bordereau);

            return \sprintf('Bordereau %s transmis : il n\'est plus modifiable.', $bordereau->getNumero());
        });
    }

    #[Route('/bordereaux/{id}/reglement', name: 'app_amo_bordereau_reglement', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"bordereau-" ~ args["bordereau"].getId()'))]
    public function reglement(BordereauAmo $bordereau, Request $requete): Response
    {
        $this->exigerMemePharmacie($bordereau);
        $donnees = $requete->getPayload();

        return $this->agir($bordereau, function () use ($bordereau, $donnees): string {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $donnees->get('date')) ?: throw new AmoException('Date du règlement invalide.');
            $montant = str_replace([' ', "\u{00A0}", "\u{202F}"], '', (string) $donnees->get('montant'));
            if (1 !== preg_match('/^\d{1,10}$/', '' === $montant ? '0' : $montant)) {
                throw new AmoException('Montant reçu invalide.');
            }
            $reglement = $this->gestion->enregistrerReglement($bordereau, $date, (int) $montant, (string) $donnees->get('reference'), $donnees->all('regle'), $donnees->all('rejet'));

            return null === $reglement
                ? 'Rejet enregistré.'
                : \sprintf('Règlement de %s enregistré. Bordereau %s.', \App\Util\Fcfa::format($reglement->getMontant()), mb_strtolower($bordereau->getStatut()->libelle()));
        });
    }

    #[Route('/bordereaux/{id}/rejeter', name: 'app_amo_bordereau_rejeter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"bordereau-" ~ args["bordereau"].getId()'))]
    public function rejeter(BordereauAmo $bordereau, Request $requete): Response
    {
        $this->exigerMemePharmacie($bordereau);

        return $this->agir($bordereau, function () use ($bordereau, $requete): string {
            $this->gestion->rejeter($bordereau, (string) $requete->getPayload()->get('motif'));

            return 'Reste du bordereau rejeté.';
        });
    }

    #[Route('/bordereaux/{id}/excel', name: 'app_amo_bordereau_excel', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function excel(BordereauAmo $bordereau, ExportBordereau $export): Response
    {
        $this->exigerMemePharmacie($bordereau);

        return $export->reponseExcel($bordereau);
    }

    #[Route('/bordereaux/{id}/pdf', name: 'app_amo_bordereau_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(BordereauAmo $bordereau, ExportBordereau $export): Response
    {
        $this->exigerMemePharmacie($bordereau);

        return $export->reponsePdf($bordereau);
    }

    /**
     * @param callable(): string $action renvoie le message de succès
     */
    private function agir(BordereauAmo $bordereau, callable $action): Response
    {
        try {
            $this->addFlash('success', $action());
        } catch (AmoException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_amo_bordereau_voir', ['id' => $bordereau->getId()]);
    }
}
