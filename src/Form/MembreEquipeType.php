<?php

namespace App\Form;

use App\Entity\Utilisateur;
use App\Service\GestionEquipe;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Membre de l'équipe (adjoint ou vendeur). Aucun champ « pharmacie » : elle est imposée par le contexte.
 *
 * @extends AbstractType<Utilisateur>
 */
final class MembreEquipeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom complet'])
            ->add('email', EmailType::class, ['label' => 'Email', 'help' => 'Sert d\'identifiant de connexion.'])
            ->add('role', ChoiceType::class, [
                'label' => 'Rôle',
                'choices' => GestionEquipe::ROLES_ATTRIBUABLES,
                'expanded' => true,
                'getter' => static fn (Utilisateur $u) => $u->getRole(),
                'setter' => static fn (Utilisateur $u, ?string $role) => $u->setRole((string) $role),
                'constraints' => [new Assert\NotBlank(message: 'Choisissez un rôle.')],
            ]);

        if ($options['creation']) {
            $builder->add('motDePasseInitial', PasswordType::class, [
                'label' => 'Mot de passe initial (facultatif)',
                'help' => 'Laissez vide pour envoyer un lien d\'activation par email. Sinon, communiquez ce mot de passe à la personne.',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [new Assert\Length(min: NouveauMotDePasseType::LONGUEUR_MIN, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Utilisateur::class,
            'creation' => false,
        ]);
        $resolver->setAllowedTypes('creation', 'bool');
    }
}
