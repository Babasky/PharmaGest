<?php

namespace App\Form;

use App\Entity\OrganismeAmo;
use App\Entity\ReferentielCommun;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Valeur d'un référentiel commun (SA-07). Le code n'existe que pour les organismes AMO.
 *
 * @extends AbstractType<ReferentielCommun>
 */
final class ReferentielCommunType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('nom', TextType::class, ['label' => 'Nom']);
        if ($options['data'] instanceof OrganismeAmo) {
            $builder->add('code', TextType::class, [
                'label' => 'Code',
                'attr' => [
                    'placeholder' => 'INPS',
                ],
            ]
            );
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReferentielCommun::class]);
    }
}
