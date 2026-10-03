<?php

namespace App\Form;

use App\Entity\Pharmacie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Fiche d'identité d'une pharmacie (SA-01).
 *
 * @extends AbstractType<Pharmacie>
 */
final class PharmacieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom de la pharmacie'])
            ->add('numeroAutorisation', TextType::class, ['label' => 'N° d\'autorisation d\'exploitation'])
            ->add('ville', TextType::class, ['label' => 'Ville'])
            ->add('adresse', TextType::class, ['label' => 'Adresse', 'help' => 'Quartier, rue, porte.'])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'attr' => ['placeholder' => '+223 XX XX XX XX']])
            ->add('email', EmailType::class, ['label' => 'Email de la pharmacie', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Pharmacie::class]);
    }
}
