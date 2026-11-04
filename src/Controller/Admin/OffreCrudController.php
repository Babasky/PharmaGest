<?php

namespace App\Controller\Admin;

use App\Entity\Offre;
use App\Repository\OffreRepository;
use App\Service\AuditLogger;
use App\Util\Fcfa;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Offres d'abonnement (SA-04) : le super admin crée des offres et en modifie les limites et le tarif annuel ; une
 * offre ne se supprime pas (des abonnements et des factures y font référence).
 *
 * Un abonnement déjà payé garde son montant : le nouveau tarif vaut pour les prochains paiements. Une baisse du
 * nombre d'utilisateurs ne désactive personne, elle bloque seulement les nouveaux comptes au-delà de la limite.
 *
 * @extends AbstractCrudController<Offre>
 */
#[AdminRoute(path: '/offres', name: 'offre')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OffreCrudController extends AbstractCrudController
{
    private const CHAMPS_JOURNALISES = ['maxUtilisateurs', 'maxPharmacies', 'tarifAnnuel', 'fonctions'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OffreRepository $offres,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Offre::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('offre')
            ->setEntityLabelInPlural('Offres')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Offre $offre) => 'Offre '.$offre->getNom())
            ->setPageTitle(Crud::PAGE_EDIT, static fn (Offre $offre) => 'Modifier l\'offre '.$offre->getNom())
            ->setPageTitle(Crud::PAGE_NEW, 'Nouvelle offre')
            ->setHelp(Crud::PAGE_NEW, 'La nouvelle offre est aussitôt proposée à la création d\'une pharmacie et au paiement d\'un abonnement. Elle ne pourra pas être supprimée, seulement modifiée.')
            ->setHelp(Crud::PAGE_INDEX, 'Les limites sont appliquées automatiquement. Un nouveau tarif vaut pour les prochains paiements : les abonnements déjà payés gardent leur montant.')
            ->setDefaultSort(['ordre' => 'ASC'])
            ->setSearchFields(null)
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action) => $action->setLabel('Créer une offre'))
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nom', 'Offre')->hideWhenUpdating()
            ->setFormTypeOption('attr', ['placeholder' => 'Ex. : Premium Plus', 'maxlength' => 60]);
        yield IntegerField::new('maxUtilisateurs', 'Utilisateurs')
            ->setFormTypeOption('attr', ['placeholder' => 'Vide : illimité'])
            ->formatValue(static fn (?int $max) => null === $max ? 'Illimités' : (string) $max)
            ->setRequired(false)
            ->setHelp('Utilisateurs actifs par pharmacie, propriétaire compris ; vide pour illimité. Une baisse ne désactive aucun compte : elle empêche seulement d\'en créer au-delà de la limite.');
        yield IntegerField::new('maxPharmacies', 'Pharmacies par propriétaire')->setFormTypeOption('attr', ['placeholder' => 'Ex. : 1']);
        yield IntegerField::new('tarifAnnuel', 'Tarif annuel')->setTextAlign('right')
            ->setFormTypeOption('attr', ['placeholder' => 'Vide : à fixer'])
            ->formatValue(static fn (?int $tarif) => null === $tarif ? 'À fixer' : Fcfa::format($tarif))
            ->setRequired(false)
            ->setHelp('En FCFA ; vide tant qu\'il n\'est pas fixé. Vaut pour les prochains paiements : les abonnements déjà payés gardent leur montant.');
        yield ArrayField::new('fonctions', 'Fonctions incluses')->hideOnIndex()
            ->setFormTypeOption('entry_options', ['attr' => ['placeholder' => 'Ex. : Caisse et stock', 'maxlength' => 120]])
            ->setRequired(false)
            ->setHelp('Ce qui est présenté au client : une ligne par fonction (ex. : « Caisse et stock », « Plusieurs pharmacies »).');
    }

    public function createEntity(string $entityFqcn): Offre
    {
        return new Offre('', '', null, 1);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $entityInstance->attribuerCode($this->codeLibre($entityInstance->getNom()));
        self::nettoyerFonctions($entityInstance);
        $entityInstance->setOrdre($this->offres->ordreSuivant());

        parent::persistEntity($entityManager, $entityInstance);

        $this->audit->journaliser(AuditLogger::OFFRE_CREEE, null, $entityInstance, null, [
            'offre' => $entityInstance->getNom(),
            'maxUtilisateurs' => $entityInstance->getMaxUtilisateurs(),
            'maxPharmacies' => $entityInstance->getMaxPharmacies(),
            'tarifAnnuel' => $entityInstance->getTarifAnnuel(),
        ]);
        $entityManager->flush();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        self::nettoyerFonctions($entityInstance);
        $origine = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $avant = $apres = [];
        foreach (self::CHAMPS_JOURNALISES as $champ) {
            $nouveau = $entityInstance->{'get'.ucfirst($champ)}();
            if (($origine[$champ] ?? null) !== $nouveau) {
                $avant[$champ] = $origine[$champ] ?? null;
                $apres[$champ] = $nouveau;
            }
        }
        if ([] !== $apres) {
            $this->audit->journaliser(AuditLogger::OFFRE_MODIFIEE, null, $entityInstance, ['offre' => $entityInstance->getNom()] + $avant, $apres);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /** Lignes vides retirées, espaces superflus ôtés. */
    private static function nettoyerFonctions(Offre $offre): void
    {
        $fonctions = array_map(static fn ($f) => trim(\is_scalar($f) ? (string) $f : ''), $offre->getFonctions());
        $offre->setFonctions(array_values(array_filter($fonctions, static fn (string $f) => '' !== $f)));
    }

    /** Code tiré du nom, rendu unique au besoin (« pro », « pro-2 »…). */
    private function codeLibre(string $nom): string
    {
        $base = mb_substr(strtolower((new AsciiSlugger('fr'))->slug($nom)->toString()), 0, 26) ?: 'offre';
        $code = $base;
        for ($i = 2; null !== $this->offres->findOneBy(['code' => $code]); ++$i) {
            $code = $base.'-'.$i;
        }

        return $code;
    }
}
