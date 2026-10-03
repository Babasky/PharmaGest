<?php

namespace App\Form;

use App\Entity\Pharmacie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fiche de la pharmacie modifiable par le propriétaire (PH-01) : identité + logo.
 *
 * @extends AbstractType<Pharmacie>
 */
final class FichePharmacieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('logo', FileType::class, [
            'label' => 'Logo (PNG ou JPEG, 500 Ko maximum)',
            'mapped' => false,
            'required' => false,
            'attr' => ['accept' => 'image/png,image/jpeg'],
            'constraints' => [new Assert\Image(maxSize: '500k', mimeTypes: ['image/png', 'image/jpeg'], mimeTypesMessage: 'Le logo doit être une image PNG ou JPEG.')],
        ]);
    }

    public function getParent(): string
    {
        return PharmacieType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Pharmacie::class]);
    }
}
