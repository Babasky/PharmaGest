<?php

namespace App\Form;

use App\Entity\SupportClient;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SupportClient>
 */
final class SupportClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('whatsappActif', CheckboxType::class, ['label' => 'Proposer WhatsApp', 'required' => false])
            ->add('whatsapp', TelType::class, [
                'label' => 'Numéro WhatsApp',
                'required' => false,
                'help' => 'Les pharmacies ouvrent la conversation avec un message déjà rempli au nom de leur officine.',
                'attr' => ['placeholder' => '+223 XX XX XX XX'],
            ])
            ->add('telephoneActif', CheckboxType::class, ['label' => 'Proposer l\'appel téléphonique', 'required' => false])
            ->add('telephone', TelType::class, [
                'label' => 'Numéro à appeler',
                'required' => false,
                'attr' => ['placeholder' => '+223 XX XX XX XX'],
            ])
            ->add('emailActif', CheckboxType::class, ['label' => 'Proposer l\'email', 'required' => false])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'required' => false,
                'attr' => ['placeholder' => 'support@exemple.ml'],
            ])
            ->add('horaires', TextType::class, [
                'label' => 'Horaires du support',
                'required' => false,
                'help' => 'Affichés aux pharmacies avec les moyens de contact.',
                'attr' => ['maxlength' => 255, 'placeholder' => 'Ex. : du lundi au samedi, de 8 h à 18 h'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SupportClient::class]);
    }
}
