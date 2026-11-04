<?php

namespace App\Form;

use App\Entity\Etagere;
use App\Entity\ZoneRangement;
use App\Repository\ZoneRangementRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
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
            ->add('zone', EntityType::class, [
                'label' => 'Zone',
                'class' => ZoneRangement::class,
                'query_builder' => static fn (ZoneRangementRepository $zones) => $zones->choix($options['data']?->getZone()),
                'choice_label' => 'nom',
                'required' => false,
                'placeholder' => 'Sans zone',
                'help' => 'Les zones (comptoir, réserve, vitrine…) se gèrent depuis « Zones de rangement ».',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Etagere::class]);
    }
}
