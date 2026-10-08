<?php

namespace App\Controller\Admin;

use App\Entity\ReferentielCommun;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Référentiels communs à toutes les pharmacies (SA-07) : organismes d'assurance, formes galéniques, catégories de dépenses
 * proposées par défaut. Rien n'est supprimé : une valeur se désactive et n'est plus proposée (RG-15).
 *
 * @template TEntity of ReferentielCommun
 *
 * @extends AbstractCrudController<TEntity>
 */
abstract class ReferentielCrudController extends AbstractCrudController
{
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setHelp(Crud::PAGE_INDEX, 'Ces listes sont proposées à toutes les pharmacies. Une valeur désactivée n\'est plus proposée mais reste sur les données existantes.')
            ->setDefaultSort(['actif' => 'DESC', 'nom' => 'ASC'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nom', 'Nom');
        yield BooleanField::new('actif', 'Proposé aux pharmacies')->hideWhenCreating();
    }
}
