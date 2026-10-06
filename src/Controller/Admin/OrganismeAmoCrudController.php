<?php

namespace App\Controller\Admin;

use App\Entity\OrganismeAmo;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
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
            ->setEntityLabelInSingular('organisme AMO')
            ->setEntityLabelInPlural('Organismes AMO')
            ->setSearchFields(['nom', 'code']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code', 'Code')->setFormTypeOption('attr', ['placeholder' => 'INPS']);
        yield from parent::configureFields($pageName);
    }
}
