<?php

namespace App\Form;

use App\Form\Model\NouvelAssocie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ajout d'un propriétaire associé. Le mot de passe initial n'est proposé qu'au propriétaire (pas au super admin).
 *
 * @extends AbstractType<NouvelAssocie>
 */
final class AssocieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Prénom et nom',
                'help' => 'Ignoré si l\'email correspond déjà à un compte.',
                'attr' => ['placeholder' => 'Entrez le prénom et le nom de l\'associé'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'help' => 'Sert d\'identifiant de connexion. S\'il correspond déjà à un propriétaire, la pharmacie s\'ajoute à ses pharmacies.',
                'attr' => ['placeholder' => 'Entrez l\'adresse email de l\'associé'],
            ]);

        if ($options['mot_de_passe']) {
            $builder->add('motDePasseInitial', PasswordType::class, [
                'label' => 'Mot de passe initial (facultatif)',
                'help' => 'Pour un nouveau compte uniquement. Laissez vide pour envoyer un lien d\'activation par email.',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'Entrez le mot de passe',
                ],
                'constraints' => [new Assert\Length(min: NouveauMotDePasseType::LONGUEUR_MIN, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NouvelAssocie::class,
            'mot_de_passe' => true,
        ]);
        $resolver->setAllowedTypes('mot_de_passe', 'bool');
    }
}
