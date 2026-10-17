<?php

namespace App\Form;

use App\Entity\OrganismeAmo;
use App\Enum\TypeOrganisme;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Assurance (AMO ou autre) ajoutée par une pharmacie, visible d'elle seule.
 *
 * @extends AbstractType<OrganismeAmo>
 */
final class OrganismePharmacieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom', 'empty_data' => '', 'attr' => ['placeholder' => 'Mutuelle des enseignants']])
            ->add('code', TextType::class, ['label' => 'Code', 'empty_data' => '', 'attr' => ['placeholder' => 'MUTEC', 'maxlength' => 20]])
            ->add('type', EnumType::class, [
                'label' => 'Nature',
                'class' => TypeOrganisme::class,
                'help' => 'AMO : le taux s\'applique au plus bas du prix AMO et du prix de la pharmacie. Autre assurance : au prix de la pharmacie.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OrganismeAmo::class]);
    }
}
