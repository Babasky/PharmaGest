<?php

namespace App\Form;

use App\Entity\Entrepot;
use App\Entity\Fournisseur;
use App\Form\Model\EntreeStock;
use App\Repository\EntrepotRepository;
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
            ->add('numero', TextType::class, ['label' => 'N° de lot', 'attr' => ['placeholder' => 'Ex. : L2026-001', 'autocomplete' => 'off']])
            ->add('datePeremption', DateType::class, ['label' => 'Date de péremption', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('quantite', IntegerType::class, ['label' => 'Quantité (unités)', 'attr' => ['placeholder' => 'Nombre d\'unités', 'min' => 1, 'inputmode' => 'numeric']])
            ->add('prixAchat', IntegerType::class, ['label' => 'Prix d\'achat unitaire (FCFA)', 'attr' => ['placeholder' => 'Ex. : 1000', 'min' => 0, 'inputmode' => 'numeric']])
            ->add('fournisseur', EntityType::class, [
                'label' => 'Fournisseur',
                'class' => Fournisseur::class,
                'query_builder' => static fn (FournisseurRepository $r) => $r->createQueryBuilder('f')->andWhere('f.actif = true')->orderBy('f.nom'),
                'required' => false,
                'placeholder' => '—',
            ])
            ->add('motif', TextType::class, ['attr' => ['placeholder' => 'Ex. : Stock initial'], 'label' => 'Motif', 'help' => 'Ex. : stock initial, don, échantillons, régularisation.']);
        if ($options['avec_entrepots']) {
            $builder->add('entrepot', EntityType::class, [
                'label' => 'Emplacement',
                'class' => Entrepot::class,
                'query_builder' => static fn (EntrepotRepository $r) => $r->createQueryBuilder('e')->andWhere('e.actif = true')->orderBy('e.nom'),
                'required' => false,
                'placeholder' => 'Officine (stock de vente)',
                'help' => 'Un lot rangé dans un entrepôt ne se vend pas : faites-le venir à l\'officine par un transfert.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EntreeStock::class, 'avec_entrepots' => false]);
        $resolver->setAllowedTypes('avec_entrepots', 'bool');
    }
}
