<?php

namespace App\Controller\Admin;

use App\Entity\Offre;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Form\Model\NouveauPaiement;
use App\Form\Model\NouvellePharmacie;
use App\Form\NouvellePharmacieType;
use App\Form\PaiementType;
use App\Mailer\PlateformeMailer;
use App\Repository\AbonnementRepository;
use App\Repository\AffectationRepository;
use App\Repository\OffreRepository;
use App\Service\AbonnementService;
use App\Service\CreationPharmacie;
use App\Service\CreationPharmacieException;
use App\Tenant\TenantContext;
use App\Util\Telephone;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pharmacies clientes : création avec le propriétaire, fiche, paiements, suspension, archivage (SA-01 à SA-03).
 * Rien n'est supprimé : une pharmacie se suspend ou s'archive.
 *
 * @extends AbstractCrudController<Pharmacie>
 */
#[AdminRoute(path: '/pharmacies', name: 'pharmacie')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class PharmacieCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AbonnementService $abonnements,
        private readonly AbonnementRepository $paiements,
        private readonly AffectationRepository $affectations,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Pharmacie::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('pharmacie')
            ->setEntityLabelInPlural('Pharmacies')
            ->setPageTitle(Crud::PAGE_INDEX, 'Pharmacies')
            ->setPageTitle(Crud::PAGE_EDIT, static fn (Pharmacie $p) => 'Modifier « '.$p->getNom().' »')
            ->setSearchFields(['nom', 'ville', 'numeroAutorisation'])
            ->setDefaultSort(['nom' => 'ASC'])
            ->setDefaultRowAction(Action::DETAIL)
            ->overrideTemplate('crud/detail', 'admin/pharmacie/voir.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $nouvelle = Action::new('nouvelle', 'Nouvelle pharmacie', 'fa fa-plus')
            ->linkToCrudAction('nouvelle')
            ->createAsGlobalAction()
            ->asPrimaryAction();

        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $nouvelle)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Officine');
        yield TextField::new('nom', 'Nom de la pharmacie');
        yield TextField::new('numeroAutorisation', 'N° d\'autorisation d\'exploitation')->hideOnIndex();
        yield TextField::new('ville', 'Ville');
        yield TextField::new('adresse', 'Adresse')->setHelp('Quartier, rue, porte.')->hideOnIndex();
        yield TelephoneField::new('telephone', 'Téléphone')
            ->formatValue(static fn (?string $numero) => htmlspecialchars(Telephone::format($numero)))
            ->setFormTypeOption('attr', ['placeholder' => '+223 XX XX XX XX']);
        yield EmailField::new('email', 'Email de la pharmacie')->hideOnIndex()->setRequired(false);
        yield AssociationField::new('offre', 'Offre')->onlyOnIndex()->setSortable(false)
            ->formatValue(static fn (?Offre $offre) => htmlspecialchars((string) $offre?->getNom()));
        // Échéance et statut viennent de l'état de l'abonnement (essai, grâce, suspension…) ; les propriétés
        // indiquées ne servent que de support aux colonnes.
        yield DateField::new('finAbonnement', 'Échéance')->onlyOnIndex()->setSortable(false)
            ->formatValue(fn ($valeur, Pharmacie $p) => $this->abonnements->etat($p)->echeance?->format('d/m/Y') ?? '—');
        yield TextField::new('motifSuspension', 'Statut')->onlyOnIndex()->setSortable(false)
            ->formatValue(function ($valeur, Pharmacie $p) {
                $etat = $this->abonnements->etat($p);

                return \sprintf('<span class="badge text-bg-%s">%s</span>', $etat->statut->couleur(), htmlspecialchars($etat->statut->libelle()));
            });
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            /** @var Pharmacie $pharmacie */
            $pharmacie = $responseParameters->get('entity')->getInstance();
            foreach ($this->fiche($pharmacie, $this->formulairePaiement($pharmacie)) as $cle => $valeur) {
                $responseParameters->set($cle, $valeur);
            }
        }

        return $responseParameters;
    }

    #[AdminRoute('/nouvelle', name: 'nouvelle')]
    public function nouvelle(Request $requete, OffreRepository $offres, CreationPharmacie $creation): Response
    {
        $donnees = new NouvellePharmacie(new Pharmacie($offres->parCode(Offre::STANDARD)));
        $formulaire = $this->createForm(NouvellePharmacieType::class, $donnees);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $proprietaire = $creation->creer($donnees->pharmacie, (string) $donnees->emailProprietaire, (string) $donnees->nomProprietaire, (int) $donnees->joursEssai);
                $this->addFlash('success', htmlspecialchars($proprietaire->isActive()
                    ? \sprintf('Pharmacie créée et ajoutée aux pharmacies de %s.', $proprietaire->getNom())
                    : \sprintf('Pharmacie créée. Un lien d\'activation a été envoyé à %s.', $proprietaire->getEmail())));

                return $this->redirectToRoute('admin_pharmacie_detail', ['entityId' => $donnees->pharmacie->getId()]);
            } catch (CreationPharmacieException $e) {
                $this->addFlash('danger', htmlspecialchars($e->getMessage()));
            }
        }

        return $this->render('admin/pharmacie/nouvelle.html.twig', ['formulaire' => $formulaire], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[AdminRoute('/{id}/paiement', name: 'paiement', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function paiement(Pharmacie $pharmacie, Request $requete): Response
    {
        $formulaire = $this->formulairePaiement($pharmacie);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var NouveauPaiement $paiement */
            $paiement = $formulaire->getData();
            /** @var Utilisateur $admin */
            $admin = $this->getUser();
            $abonnement = $this->abonnements->enregistrerPaiement(
                $pharmacie,
                $paiement->offre ?? throw new \LogicException(),
                (int) $paiement->montant,
                $paiement->moyen ?? throw new \LogicException(),
                $paiement->reference,
                $paiement->datePaiement ?? throw new \LogicException(),
                $admin,
            );
            $this->addFlash('success', \sprintf('Paiement enregistré (facture %s). Abonnement valable jusqu\'au %s.', $abonnement->getNumeroFacture(), $abonnement->getDateFin()->format('d/m/Y')));

            return $this->redirectToFiche($pharmacie);
        }

        return $this->render('admin/pharmacie/voir.html.twig', $this->fiche($pharmacie, $formulaire), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[AdminRoute('/{id}/suspendre', name: 'suspendre', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(new Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function suspendre(Pharmacie $pharmacie, Request $requete): Response
    {
        $motif = trim($requete->request->getString('motif'));
        if ('' === $motif) {
            $this->addFlash('danger', 'Indiquez le motif de la suspension.');
        } else {
            $this->abonnements->suspendre($pharmacie, $motif);
            $this->addFlash('success', 'Pharmacie suspendue : plus aucun utilisateur n\'y a accès.');
        }

        return $this->redirectToFiche($pharmacie);
    }

    #[AdminRoute('/{id}/reactiver', name: 'reactiver', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(new Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function reactiver(Pharmacie $pharmacie): Response
    {
        $this->abonnements->reactiver($pharmacie);
        $this->addFlash('success', 'Pharmacie réactivée.');

        return $this->redirectToFiche($pharmacie);
    }

    #[AdminRoute('/{id}/archiver', name: 'archiver', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(new Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function archiver(Pharmacie $pharmacie): Response
    {
        $this->abonnements->archiver($pharmacie);
        $this->addFlash('success', 'Pharmacie archivée.');

        return $this->redirectToFiche($pharmacie);
    }

    #[AdminRoute('/{id}/renvoyer-activation', name: 'renvoyer_activation', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(new Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function renvoyerActivation(Pharmacie $pharmacie, PlateformeMailer $mailer): Response
    {
        $envoyes = 0;
        foreach ($this->tenantContext->sansFiltre(fn () => $this->affectations->proprietaires($pharmacie)) as $proprietaire) {
            if (!$proprietaire->isActive()) {
                $mailer->activation($proprietaire, $pharmacie);
                ++$envoyes;
            }
        }
        $this->addFlash($envoyes > 0 ? 'success' : 'info', $envoyes > 0 ? 'Lien d\'activation renvoyé au propriétaire.' : 'Le compte du propriétaire est déjà activé.');

        return $this->redirectToFiche($pharmacie);
    }

    /**
     * @param AdminContext<Pharmacie> $context
     */
    #[AdminRoute('/{entityId}', name: 'detail', options: ['requirements' => ['entityId' => '\d+']])]
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        return parent::detail($context);
    }

    /**
     * @return FormInterface<NouveauPaiement>
     */
    private function formulairePaiement(Pharmacie $pharmacie): FormInterface
    {
        $paiement = new NouveauPaiement();
        $paiement->offre = $pharmacie->getOffre();
        $paiement->montant = $pharmacie->getOffre()->getTarifAnnuel();
        $paiement->datePaiement = $this->abonnements->aujourdhui();

        return $this->createForm(PaiementType::class, $paiement, [
            'action' => $this->generateUrl('admin_pharmacie_paiement', ['id' => $pharmacie->getId()]),
        ]);
    }

    /**
     * Données de la fiche : état de l'abonnement, prochaine période, paiements, équipe et formulaire de paiement.
     *
     * @param FormInterface<NouveauPaiement> $formulaire
     *
     * @return array<string, mixed>
     */
    private function fiche(Pharmacie $pharmacie, FormInterface $formulaire): array
    {
        return [
            'pharmacie' => $pharmacie,
            'etat' => $this->abonnements->etat($pharmacie),
            'periode' => $this->abonnements->prochainePeriode($pharmacie),
            'paiements' => $this->paiements->historique($pharmacie),
            'equipe' => $this->affectations->equipe($pharmacie),
            'formulaire' => $formulaire->createView(),
        ];
    }

    private function redirectToFiche(Pharmacie $pharmacie): Response
    {
        return $this->redirectToRoute('admin_pharmacie_detail', ['entityId' => $pharmacie->getId()]);
    }
}
