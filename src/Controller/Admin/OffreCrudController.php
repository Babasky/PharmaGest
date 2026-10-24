<?php

namespace App\Controller\Admin;

use App\Entity\Offre;
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

/**
 * Offres d'abonnement (SA-04) : le super admin en modifie les limites et le tarif annuel ; ni création ni suppression.
 * Un abonnement déjà payé garde son montant : le nouveau tarif vaut pour les prochains paiements. Une baisse du
 * nombre d'utilisateurs ne désactive personne, elle bloque seulement les nouveaux comptes au-delà de la limite.
 *
 * @extends AbstractCrudController<Offre>
 */
#[AdminRoute(path: '/offres', name: 'offre')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OffreCrudController extends AbstractCrudController
{
    private const CHAMPS_JOURNALISES = ['maxUtilisateurs', 'maxPharmacies', 'tarifAnnuel'];

    public function __construct(private readonly AuditLogger $audit)
    {
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
            ->setHelp(Crud::PAGE_INDEX, 'Les limites sont appliquées automatiquement. Un nouveau tarif vaut pour les prochains paiements : les abonnements déjà payés gardent leur montant.')
            ->setDefaultSort(['ordre' => 'ASC'])
            ->setSearchFields(null)
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nom', 'Offre')->hideOnForm();
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
        yield ArrayField::new('fonctions', 'Fonctions incluses')->onlyOnDetail();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
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
}
