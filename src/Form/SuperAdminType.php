<?php

namespace App\Form;

use App\Entity\Utilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nouveau super admin créé par un super admin connecté. Pas de mot de passe : la personne le choisit
 * elle-même avec le lien d'activation reçu par email.
 *
 * @extends AbstractType<Utilisateur>
 */
final class SuperAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Prénom et nom',
                'attr' => ['placeholder' => 'Entrez le prénom et le nom'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'help' => 'Sert d\'identifiant de connexion. La personne y reçoit un lien pour choisir son mot de passe (valable 72 heures).',
                'attr' => ['placeholder' => 'Entrez l\'adresse email'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Utilisateur::class]);
    }
}
