<?php

namespace App\Form;

use App\Entity\Fournisseur;
use App\Form\Model\EntreeStock;
use App\Repository\FournisseurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EntreeStock>
 */
final class EntreeStockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('numero', TextType::class, ['label' => 'N° de lot', 'attr' => ['autocomplete' => 'off']])
            ->add('datePeremption', DateType::class, ['label' => 'Date de péremption', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('quantite', IntegerType::class, ['label' => 'Quantité (unités)', 'attr' => ['min' => 1, 'inputmode' => 'numeric']])
            ->add('prixAchat', IntegerType::class, ['label' => 'Prix d\'achat unitaire (FCFA)', 'attr' => ['min' => 0, 'inputmode' => 'numeric']])
            ->add('fournisseur', EntityType::class, [
                'label' => 'Fournisseur',
                'class' => Fournisseur::class,
                'query_builder' => static fn (FournisseurRepository $r) => $r->createQueryBuilder('f')->andWhere('f.actif = true')->orderBy('f.nom'),
                'required' => false,
                'placeholder' => '—',
            ])
            ->add('motif', TextType::class, ['label' => 'Motif', 'required' => false, 'help' => 'Ex. : stock initial, livraison sans commande.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EntreeStock::class]);
    }
}
