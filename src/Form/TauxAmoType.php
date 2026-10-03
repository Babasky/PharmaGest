<?php

namespace App\Form;

use App\Entity\OrganismeAmo;
use App\Entity\TauxAmo;
use App\Repository\OrganismeAmoRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TauxAmo>
 */
final class TauxAmoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organisme', EntityType::class, [
                'label' => 'Organisme',
                'class' => OrganismeAmo::class,
                'query_builder' => static fn (OrganismeAmoRepository $r) => $r->createQueryBuilder('o')->andWhere('o.actif = true')->orderBy('o.nom'),
                'placeholder' => 'Choisir…',
            ])
            ->add('taux', IntegerType::class, ['label' => 'Part prise en charge (%)', 'attr' => ['min' => 0, 'max' => 100]])
            ->add('dateEffet', DateType::class, ['label' => 'À partir du', 'widget' => 'single_text', 'input' => 'datetime_immutable']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TauxAmo::class]);
    }
}
