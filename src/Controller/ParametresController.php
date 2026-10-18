<?php

namespace App\Controller;

use App\Entity\Caisse;
use App\Entity\OrganismeAmo;
use App\Entity\TauxAmo;
use App\Entity\Utilisateur;
use App\Form\FichePharmacieType;
use App\Form\OrganismePharmacieType;
use App\Form\ParametresType;
use App\Form\TauxAmoType;
use App\Repository\CaisseRepository;
use App\Repository\OrganismeAmoRepository;
use App\Repository\TauxAmoRepository;
use App\Service\ParametresPharmacie;
use App\Stockage\StockageFichiers;
use App\Vente\GestionCaisse;
use App\Vente\VenteException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche et paramètres de la pharmacie courante (PH-01, PH-02), réservés au propriétaire.
 */
#[Route('/parametres')]
final class ParametresController extends AbstractAppController
{
    public const ONGLETS = ['fiche', 'regles', 'amo', 'caisses'];

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
        CaisseRepository $caisses,
        GestionCaisse $gestionCaisse,
        ClockInterface $horloge,
    ): Response {
        $pharmacie = $this->pharmacie();
        $parametres = $this->parametres->pour($pharmacie);

        $fiche = $this->createForm(FichePharmacieType::class, $pharmacie);
        $regles = $this->createForm(ParametresType::class, $parametres);
        $nouveauTaux = (new TauxAmo())->setDateEffet($horloge->now());
        $formTaux = $this->createForm(TauxAmoType::class, $nouveauTaux);
        $nouvelOrganisme = new OrganismeAmo();
        $formOrganisme = $this->createForm(OrganismePharmacieType::class, $nouvelOrganisme);

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

        $formOrganisme->handleRequest($requete);
        if ($formOrganisme->isSubmitted() && $formOrganisme->isValid()) {
            $nouvelOrganisme->setPharmacie($pharmacie);
            $this->em->persist($nouvelOrganisme);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Assurance %s ajoutée : choisissez maintenant son taux.', $nouvelOrganisme->getNom()));

            return $this->redirectToRoute('app_parametres', ['onglet' => 'amo']);
        }

        $ongletDemande = $requete->query->getString('onglet');
        $onglet = match (true) {
            $fiche->isSubmitted() => 'fiche',
            $regles->isSubmitted() => 'regles',
            $formTaux->isSubmitted(), $formOrganisme->isSubmitted() => 'amo',
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
            'form_organisme' => $formOrganisme,
            'organismes_pharmacie' => $organismes->findBy(['pharmacie' => $pharmacie], ['nom' => 'ASC']),
            'onglet' => $onglet,
            'caisses' => 'caisses' === $onglet ? $this->caissesEtSessions($caisses, $gestionCaisse) : [],
        ], new Response(status: ($fiche->isSubmitted() || $regles->isSubmitted() || $formTaux->isSubmitted() || $formOrganisme->isSubmitted()) ? 422 : 200));
    }

    /**
     * Archive ou réactive une assurance créée par la pharmacie (les organismes communs relèvent de l'éditeur).
     */
    #[Route('/assurances/{id}/archiver', name: 'app_parametres_organisme_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
    #[IsCsrfTokenValid(new Expression('"organisme-" ~ args["organisme"].getId()'))]
    public function archiverOrganisme(OrganismeAmo $organisme): Response
    {
        $this->basculerArchivage($organisme, \sprintf('L\'assurance %s', $organisme->getNom()));

        return $this->redirectToRoute('app_parametres', ['onglet' => 'amo']);
    }

    /**
     * Une officine a souvent une seule caisse, créée d'office ; le propriétaire peut en ajouter d'autres.
     */
    #[Route('/caisses', name: 'app_parametres_caisse_ajouter', methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
    #[IsCsrfTokenValid('caisses')]
    public function ajouterCaisse(Request $requete, GestionCaisse $gestionCaisse): Response
    {
        try {
            $caisse = $gestionCaisse->ajouter($this->pharmacie(), (string) $requete->getPayload()->get('libelle'));
            $this->addFlash('success', \sprintf('%s ajoutée : choisissez-la sur le poste qui l\'utilise (écran de caisse, « Ce poste »).', $caisse->getLibelle()));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_parametres', ['onglet' => 'caisses']);
    }

    #[Route('/caisses/{id}/renommer', name: 'app_parametres_caisse_renommer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
    #[IsCsrfTokenValid('caisses')]
    public function renommerCaisse(Caisse $caisse, Request $requete, CaisseRepository $caisses): Response
    {
        $this->exigerMemePharmacie($caisse);
        $libelle = mb_substr(trim((string) $requete->getPayload()->get('libelle')), 0, 60);
        $homonyme = $caisses->findOneBy(['libelle' => $libelle]);
        if ('' === $libelle || (null !== $homonyme && $homonyme !== $caisse)) {
            $this->addFlash('error', '' === $libelle ? 'Donnez un nom à la caisse.' : \sprintf('Une caisse s\'appelle déjà « %s ».', $libelle));
        } else {
            $caisse->setLibelle($libelle);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Caisse renommée « %s ».', $libelle));
        }

        return $this->redirectToRoute('app_parametres', ['onglet' => 'caisses']);
    }

    #[Route('/caisses/{id}/basculer', name: 'app_parametres_caisse_basculer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
    #[IsCsrfTokenValid('caisses')]
    public function basculerCaisse(Caisse $caisse, GestionCaisse $gestionCaisse): Response
    {
        $this->exigerMemePharmacie($caisse);
        try {
            $gestionCaisse->basculer($this->pharmacie(), $caisse);
            $this->addFlash('success', \sprintf($caisse->isActif() ? '%s est de nouveau en service.' : '%s est retirée du service.', $caisse->getLibelle()));
        } catch (VenteException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_parametres', ['onglet' => 'caisses']);
    }

    /**
     * @return list<array{caisse: Caisse, session: ?\App\Entity\SessionCaisse}>
     */
    private function caissesEtSessions(CaisseRepository $caisses, GestionCaisse $gestionCaisse): array
    {
        $gestionCaisse->caisses($this->pharmacie());

        return array_map(static fn (Caisse $c) => ['caisse' => $c, 'session' => $gestionCaisse->sessionOuverte($c)], $caisses->toutes());
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
