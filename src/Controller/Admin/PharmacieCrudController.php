<?php

namespace App\Controller\Admin;

use App\Entity\Affectation;
use App\Entity\Offre;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Form\AssocieType;
use App\Form\Model\NouveauPaiement;
use App\Form\Model\NouvelAssocie;
use App\Form\Model\NouvellePharmacie;
use App\Form\NouvellePharmacieType;
use App\Form\PaiementType;
use App\Mailer\PlateformeMailer;
use App\Repository\AbonnementRepository;
use App\Repository\AffectationRepository;
use App\Repository\OffreRepository;
use App\Repository\UtilisateurRepository;
use App\Service\AbonnementService;
use App\Service\CreationPharmacie;
use App\Service\CreationPharmacieException;
use App\Service\GestionEquipeException;
use App\Service\GestionProprietaires;
use App\Tenant\TenantContext;
use App\Util\Telephone;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

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
        private readonly GestionProprietaires $proprietaires,
        private readonly UtilisateurRepository $utilisateurs,
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
        yield TextField::new('nom', 'Nom de la pharmacie')->setFormTypeOption('attr', ['placeholder' => 'Ex. : Pharmacie du Fleuve']);
        yield TextField::new('numeroAutorisation', 'N° d\'autorisation d\'exploitation')->hideOnIndex()
            ->setFormTypeOption('attr', ['placeholder' => 'N° de l\'autorisation d\'ouverture']);
        yield TextField::new('ville', 'Ville')->setFormTypeOption('attr', ['placeholder' => 'Ex. : Bamako']);
        yield TextField::new('adresse', 'Adresse')->setHelp('Quartier, rue, porte.')->hideOnIndex()
            ->setFormTypeOption('attr', ['placeholder' => 'Quartier, rue, repère']);
        yield TelephoneField::new('telephone', 'Téléphone')
            ->formatValue(static fn (?string $numero) => htmlspecialchars(Telephone::format($numero)))
            ->setFormTypeOption('attr', ['placeholder' => '+223 XX XX XX XX']);
        yield EmailField::new('email', 'Email de la pharmacie')->hideOnIndex()->setRequired(false)
            ->setFormTypeOption('attr', ['placeholder' => 'contact@pharmacie.ml']);
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

        // Propriétaire titulaire : nom et email éditables depuis le formulaire de modification. Champs non
        // mappés sur la pharmacie (ils portent sur le compte du propriétaire), pré-remplis et enregistrés
        // dans createEditFormBuilder(). Affichés seulement si la pharmacie a déjà un propriétaire.
        $instance = $this->getContext()?->getEntity()->getInstance();
        if (Crud::PAGE_EDIT === $pageName && $instance instanceof Pharmacie && null !== $this->titulaire($instance)) {
            yield FormField::addFieldset('Propriétaire titulaire');
            yield TextField::new('proprietaireNom', 'Prénom et nom du titulaire')
                ->setFormTypeOption('mapped', false)
                ->setFormTypeOption('constraints', [new NotBlank(message: 'Le nom du propriétaire est obligatoire.'), new Length(max: 120)])
                ->setFormTypeOption('attr', ['placeholder' => 'Entrez le prénom et le nom du titulaire']);
            yield EmailField::new('proprietaireEmail', 'Email du titulaire')
                ->setFormTypeOption('mapped', false)
                ->setFormTypeOption('constraints', [new NotBlank(message: 'L\'email du propriétaire est obligatoire.'), new Email()])
                ->setHelp('C\'est son identifiant de connexion. Le changer met à jour son compte pour toutes ses pharmacies.');
        }
    }

    /**
     * Ajoute au formulaire de modification le nom et l'email du propriétaire titulaire : pré-remplis à
     * l'ouverture, enregistrés sur son compte à la soumission (la pharmacie elle-même est enregistrée par
     * EasyAdmin dans la même transaction).
     *
     * @return FormBuilderInterface<Pharmacie>
     */
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $builder = parent::createEditFormBuilder($entityDto, $formOptions, $context);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $form = $event->getForm();
            $pharmacie = $event->getData();
            if (!$pharmacie instanceof Pharmacie || !$form->has('proprietaireNom')) {
                return;
            }
            $titulaire = $this->titulaire($pharmacie);
            $form->get('proprietaireNom')->setData($titulaire?->getNom());
            $form->get('proprietaireEmail')->setData($titulaire?->getEmail());
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $pharmacie = $event->getData();
            if (!$pharmacie instanceof Pharmacie || !$form->has('proprietaireNom')) {
                return;
            }
            $nom = trim((string) $form->get('proprietaireNom')->getData());
            $email = mb_strtolower(trim((string) $form->get('proprietaireEmail')->getData()));
            if ('' === $nom || '' === $email) {
                return; // Champs vides : les contraintes NotBlank s'en chargent.
            }
            $titulaire = $this->titulaire($pharmacie);
            if (null === $titulaire) {
                $form->get('proprietaireNom')->addError(new FormError('Cette pharmacie n\'a pas de propriétaire à modifier.'));

                return;
            }
            $autre = $this->utilisateurs->parEmail($email);
            if (null !== $autre && $autre->getId() !== $titulaire->getId()) {
                $form->get('proprietaireEmail')->addError(new FormError('Cet email est déjà utilisé par un autre compte.'));

                return;
            }
            // Entité gérée : la modification est enregistrée par le flush d'EasyAdmin si le formulaire est valide.
            $titulaire->setNom($nom)->setEmail($email);
        });

        return $builder;
    }

    /** Propriétaire titulaire (premier propriétaire actif) d'une pharmacie, hors filtre tenant. */
    private function titulaire(Pharmacie $pharmacie): ?Utilisateur
    {
        return $this->tenantContext->sansFiltre(fn () => $this->affectations->proprietaires($pharmacie))[0] ?? null;
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            /** @var Pharmacie $pharmacie */
            $pharmacie = $responseParameters->get('entity')->getInstance();
            foreach ($this->fiche($pharmacie, $this->formulairePaiement($pharmacie), $this->formulaireAssocie($pharmacie)) as $cle => $valeur) {
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

        return $this->render('admin/pharmacie/voir.html.twig', $this->fiche($pharmacie, $formulaire, $this->formulaireAssocie($pharmacie)), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Ajoute un propriétaire associé : nouveau compte, propriétaire existant ou membre de l'équipe promu. */
    #[AdminRoute('/{id}/proprietaires', name: 'ajouter_proprietaire', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function ajouterProprietaire(Pharmacie $pharmacie, Request $requete): Response
    {
        $formulaire = $this->formulaireAssocie($pharmacie);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var NouvelAssocie $associe */
            $associe = $formulaire->getData();
            try {
                $compte = $this->proprietaires->ajouter($pharmacie, (string) $associe->email, (string) $associe->nom)->getUtilisateur();
                $this->addFlash('success', htmlspecialchars($compte->isActive()
                    ? \sprintf('%s est maintenant propriétaire de la pharmacie.', $compte->getNom())
                    : \sprintf('%s est propriétaire de la pharmacie. Un lien d\'activation a été envoyé à %s.', $compte->getNom(), $compte->getEmail())));

                return $this->redirectToFiche($pharmacie);
            } catch (GestionEquipeException $e) {
                $this->addFlash('danger', htmlspecialchars($e->getMessage()));
            }
        }

        return $this->render('admin/pharmacie/voir.html.twig', $this->fiche($pharmacie, $this->formulairePaiement($pharmacie), $formulaire), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[AdminRoute('/{id}/proprietaires/retirer', name: 'retirer_proprietaire', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(new Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function retirerProprietaire(Pharmacie $pharmacie, Request $requete): Response
    {
        $affectation = $this->affectationDe($pharmacie, $requete);
        try {
            $this->proprietaires->retirer($affectation);
            $this->addFlash('success', htmlspecialchars(\sprintf('%s n\'a plus accès à la pharmacie.', $affectation->getUtilisateur()->getNom())));
        } catch (GestionEquipeException $e) {
            $this->addFlash('danger', htmlspecialchars($e->getMessage()));
        }

        return $this->redirectToFiche($pharmacie);
    }

    #[AdminRoute('/{id}/proprietaires/reactiver', name: 'reactiver_proprietaire', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(new Expression('"pharmacie-" ~ args["pharmacie"].getId()'))]
    public function reactiverProprietaire(Pharmacie $pharmacie, Request $requete): Response
    {
        $affectation = $this->affectationDe($pharmacie, $requete);
        try {
            $this->proprietaires->reactiver($affectation);
            $this->addFlash('success', htmlspecialchars(\sprintf('%s a de nouveau accès à la pharmacie.', $affectation->getUtilisateur()->getNom())));
        } catch (GestionEquipeException $e) {
            $this->addFlash('danger', htmlspecialchars($e->getMessage()));
        }

        return $this->redirectToFiche($pharmacie);
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
        $this->addFlash($envoyes > 0 ? 'success' : 'info', match (true) {
            $envoyes > 1 => \sprintf('Lien d\'activation renvoyé à %d propriétaires.', $envoyes),
            1 === $envoyes => 'Lien d\'activation renvoyé au propriétaire.',
            default => 'Les comptes des propriétaires sont déjà activés.',
        });

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
     * @return FormInterface<NouvelAssocie>
     */
    private function formulaireAssocie(Pharmacie $pharmacie): FormInterface
    {
        return $this->createForm(AssocieType::class, new NouvelAssocie(), [
            'action' => $this->generateUrl('admin_pharmacie_ajouter_proprietaire', ['id' => $pharmacie->getId()]),
            'mot_de_passe' => false,
        ]);
    }

    /** Affectation désignée dans le formulaire, refusée si elle n'appartient pas à la pharmacie. */
    private function affectationDe(Pharmacie $pharmacie, Request $requete): Affectation
    {
        $id = $requete->request->getInt('affectation');
        $affectation = $this->tenantContext->sansFiltre(fn () => $this->affectations->find($id));
        if (null === $affectation || $affectation->getPharmacie()?->getId() !== $pharmacie->getId()) {
            throw $this->createNotFoundException();
        }

        return $affectation;
    }

    /**
     * Données de la fiche : état de l'abonnement, prochaine période, paiements, équipe, formulaires de paiement et d'associé.
     *
     * @param FormInterface<NouveauPaiement> $formulaire
     * @param FormInterface<NouvelAssocie>   $formulaireAssocie
     *
     * @return array<string, mixed>
     */
    private function fiche(Pharmacie $pharmacie, FormInterface $formulaire, FormInterface $formulaireAssocie): array
    {
        return [
            'pharmacie' => $pharmacie,
            'etat' => $this->abonnements->etat($pharmacie),
            'periode' => $this->abonnements->prochainePeriode($pharmacie),
            'paiements' => $this->paiements->historique($pharmacie),
            'equipe' => $this->affectations->equipe($pharmacie),
            'formulaire' => $formulaire->createView(),
            'formulaire_associe' => $formulaireAssocie->createView(),
            'proprietaires_actifs' => \count($this->tenantContext->sansFiltre(fn () => $this->affectations->proprietaires($pharmacie))),
        ];
    }

    private function redirectToFiche(Pharmacie $pharmacie): Response
    {
        return $this->redirectToRoute('admin_pharmacie_detail', ['entityId' => $pharmacie->getId()]);
    }
}
