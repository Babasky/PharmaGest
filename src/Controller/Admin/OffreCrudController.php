<?php

namespace App\Controller\Admin;

use App\Entity\Offre;
use App\Util\Fcfa;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Offres d'abonnement, en consultation : leurs limites sont appliquées automatiquement. Le paramétrage depuis
 * l'écran arrive en V1 (SA-04) ; en attendant, il se fait par migration.
 *
 * @extends AbstractCrudController<Offre>
 */
#[AdminRoute(path: '/offres', name: 'offre')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OffreCrudController extends AbstractCrudController
{
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
            ->setHelp(Crud::PAGE_INDEX, 'Les limites sont appliquées automatiquement. Leur modification depuis cet écran arrive en V1 (SA-04) ; en attendant, elle se fait par migration.')
            ->setDefaultSort(['ordre' => 'ASC'])
            ->setSearchFields(null)
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nom', 'Offre');
        yield IntegerField::new('maxUtilisateurs', 'Utilisateurs')
            ->formatValue(static fn (?int $max) => null === $max ? 'Illimités' : (string) $max);
        yield IntegerField::new('maxPharmacies', 'Pharmacies par propriétaire');
        yield IntegerField::new('tarifAnnuel', 'Tarif annuel')->setTextAlign('right')
            ->formatValue(static fn (?int $tarif) => null === $tarif ? 'À fixer' : Fcfa::format($tarif));
        yield ArrayField::new('fonctions', 'Fonctions incluses')->onlyOnDetail();
    }
}
