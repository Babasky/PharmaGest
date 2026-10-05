<?php

namespace App\Form;

use App\Form\Model\OperationLot;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OperationLot>
 */
final class OperationLotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('quantite', IntegerType::class, ['label' => $options['libelle_quantite'], 'attr' => ['min' => 0, 'inputmode' => 'numeric']])
            ->add('motif', TextType::class, ['label' => 'Motif']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OperationLot::class, 'libelle_quantite' => 'Quantité']);
        $resolver->setAllowedTypes('libelle_quantite', 'string');
    }
}
