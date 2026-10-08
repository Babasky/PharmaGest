<?php

namespace App\Form;

use App\Entity\Etagere;
use App\Enum\ZoneEtagere;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Etagere>
 */
final class EtagereType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'attr' => [
                    'placeholder' => "Entrez le code de l'étagère(E1-R1)",
                ],
            ]
            )
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr' => [
                    'placeholder' => 'Antalgiques, Antibiotique...',
                ],
            ]
            )
            ->add('zone', EnumType::class, [
                'label' => 'Zone',
                'class' => ZoneEtagere::class,
                'choice_label' => static fn (ZoneEtagere $z) => $z->libelle(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Etagere::class]);
    }
}
