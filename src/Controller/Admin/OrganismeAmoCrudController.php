<?php

namespace App\Controller\Admin;

use App\Entity\OrganismeAmo;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Organismes de prise en charge : gestionnaires de l'AMO et autres assurances (privées, ONG, mutuelles).
 *
 * @extends ReferentielCrudController<OrganismeAmo>
 */
#[AdminRoute(path: '/referentiels/organismes-amo', name: 'organisme_amo')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OrganismeAmoCrudController extends ReferentielCrudController
{
    public static function getEntityFqcn(): string
    {
        return OrganismeAmo::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('organisme d\'assurance')
            ->setEntityLabelInPlural('Organismes d\'assurance')
            ->setSearchFields(['nom', 'code']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code', 'Code')->setFormTypeOption('attr', ['placeholder' => 'INPS']);
        yield from parent::configureFields($pageName);
        yield ChoiceField::new('type', 'Nature')
            ->setHelp('AMO : le taux s\'applique au plus bas du prix AMO et du prix de la pharmacie. Autre assurance : au prix de vente de la pharmacie.');
        // Les organismes créés ici sont communs à toutes les pharmacies ; une pharmacie peut aussi créer les siens.
        yield TextField::new('createur', 'Créé par')->hideOnForm();
    }
}
