<?php

namespace App\Controller\Admin;

use App\Entity\CategorieDepenseModele;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catégories de dépenses proposées par défaut : chaque pharmacie en reçoit une copie qu'elle gère ensuite.
 *
 * @extends ReferentielCrudController<CategorieDepenseModele>
 */
#[AdminRoute(path: '/referentiels/categories-depenses', name: 'categorie_depense')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class CategorieDepenseCrudController extends ReferentielCrudController
{
    public static function getEntityFqcn(): string
    {
        return CategorieDepenseModele::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('catégorie de dépense')
            ->setEntityLabelInPlural('Catégories de dépenses par défaut');
    }
}
