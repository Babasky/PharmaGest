<?php

namespace App\Controller\Admin;

use App\Entity\FormeGalenique;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends ReferentielCrudController<FormeGalenique>
 */
#[AdminRoute(path: '/referentiels/formes-galeniques', name: 'forme_galenique')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class FormeGaleniqueCrudController extends ReferentielCrudController
{
    public static function getEntityFqcn(): string
    {
        return FormeGalenique::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('forme galénique')
            ->setEntityLabelInPlural('Formes galéniques');
    }
}
