<?php

namespace App\Form;

use App\Entity\ZoneRangement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ZoneRangement>
 */
final class ZoneRangementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('nom', TextType::class, [
            'label' => 'Nom de la zone',
            'attr' => ['placeholder' => 'Ex. : Vitrine, Pharmacie vétérinaire, Arrière-boutique', 'maxlength' => 60],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ZoneRangement::class]);
    }
}
