<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{motDePasse: string}>
 */
final class NouveauMotDePasseType extends AbstractType
{
    public const LONGUEUR_MIN = 8;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motDePasse', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
            'first_options' => [
                'label' => 'Nouveau mot de passe',
                'help' => \sprintf('Au moins %d caractères.', self::LONGUEUR_MIN),
                'attr' => ['autocomplete' => 'new-password', 'autofocus' => true],
            ],
            'second_options' => ['label' => 'Confirmez le mot de passe', 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => self::contraintes(),
        ]);
    }

    /**
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    public static function contraintes(): array
    {
        return [
            new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
            new Assert\Length(min: self::LONGUEUR_MIN, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
        ];
    }
}
