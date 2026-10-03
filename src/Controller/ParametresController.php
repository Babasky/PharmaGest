<?php

namespace App\Controller;

use App\Entity\TauxAmo;
use App\Entity\Utilisateur;
use App\Form\FichePharmacieType;
use App\Form\ParametresType;
use App\Form\TauxAmoType;
use App\Repository\OrganismeAmoRepository;
use App\Repository\TauxAmoRepository;
use App\Service\ParametresPharmacie;
use App\Stockage\StockageFichiers;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche et paramètres de la pharmacie courante (PH-01, PH-02), réservés au propriétaire.
 */
#[Route('/parametres')]
final class ParametresController extends AbstractAppController
{
    public const ONGLETS = ['fiche', 'regles', 'amo'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParametresPharmacie $parametres,
    ) {
    }

    #[Route('', name: 'app_parametres', methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
    public function index(
        Request $requete,
        StockageFichiers $stockage,
        TauxAmoRepository $taux,
        OrganismeAmoRepository $organismes,
        ClockInterface $horloge,
    ): Response {
        $pharmacie = $this->pharmacie();
        $parametres = $this->parametres->pour($pharmacie);

        $fiche = $this->createForm(FichePharmacieType::class, $pharmacie);
        $regles = $this->createForm(ParametresType::class, $parametres);
        $nouveauTaux = (new TauxAmo())->setDateEffet($horloge->now());
        $formTaux = $this->createForm(TauxAmoType::class, $nouveauTaux);

        $fiche->handleRequest($requete);
        if ($fiche->isSubmitted() && $fiche->isValid()) {
            /** @var UploadedFile|null $logo */
            $logo = $fiche->get('logo')->getData();
            if (null !== $logo) {
                $stockage->supprimer($pharmacie, 'logo', $pharmacie->getLogo());
                $pharmacie->setLogo($stockage->enregistrer($pharmacie, 'logo', $logo));
            }
            $this->em->flush();
            $this->addFlash('success', 'Fiche de la pharmacie enregistrée.');

            return $this->redirectToRoute('app_parametres', ['onglet' => 'fiche']);
        }

        $regles->handleRequest($requete);
        if ($regles->isSubmitted() && $regles->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Règles de gestion enregistrées.');

            return $this->redirectToRoute('app_parametres', ['onglet' => 'regles']);
        }

        $formTaux->handleRequest($requete);
        if ($formTaux->isSubmitted() && $formTaux->isValid()) {
            $this->em->persist($nouveauTaux);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Taux %s de %d %% enregistré à partir du %s.', $nouveauTaux->getOrganisme()?->getNom(), $nouveauTaux->getTaux(), $nouveauTaux->getDateEffet()?->format('d/m/Y')));

            return $this->redirectToRoute('app_parametres', ['onglet' => 'amo']);
        }

        $ongletDemande = $requete->query->getString('onglet');
        $onglet = match (true) {
            $fiche->isSubmitted() => 'fiche',
            $regles->isSubmitted() => 'regles',
            $formTaux->isSubmitted() => 'amo',
            \in_array($ongletDemande, self::ONGLETS, true) => $ongletDemande,
            default => 'fiche',
        };

        $tauxEnVigueur = [];
        foreach ($organismes->actifs() as $organisme) {
            $tauxEnVigueur[] = ['organisme' => $organisme, 'taux' => $this->parametres->tauxAmo($organisme)];
        }

        return $this->render('parametres/index.html.twig', [
            'pharmacie' => $pharmacie,
            'fiche' => $fiche,
            'regles' => $regles,
            'form_taux' => $formTaux,
            'taux_en_vigueur' => $tauxEnVigueur,
            'historique_taux' => $taux->historique(),
            'onglet' => $onglet,
        ], new Response(status: ($fiche->isSubmitted() || $regles->isSubmitted() || $formTaux->isSubmitted()) ? 422 : 200));
    }

    /**
     * Logo de la pharmacie courante, servi après contrôle d'accès (jamais depuis le dossier public).
     */
    #[Route('/logo', name: 'app_parametres_logo', methods: ['GET'])]
    #[IsGranted(Utilisateur::ROLE_VENDEUR)]
    public function logo(StockageFichiers $stockage): Response
    {
        $pharmacie = $this->pharmacie();
        $chemin = null === $pharmacie->getLogo() ? null : $stockage->chemin($pharmacie, 'logo', $pharmacie->getLogo());
        if (null === $chemin) {
            throw $this->createNotFoundException();
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->setPrivate();
        $reponse->setMaxAge(3600);

        return $reponse;
    }
}
