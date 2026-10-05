<?php

namespace App\Form;

use App\Security\CodePin;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Choix de son code PIN (PH-04), confirmé par le mot de passe du compte.
 *
 * @extends AbstractType<array{motDePasse: string, code: string}>
 */
final class CodePinType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $chiffres = ['inputmode' => 'numeric', 'maxlength' => 4, 'pattern' => '[0-9]{4}', 'autocomplete' => 'off'];
        $builder
            ->add('motDePasse', PasswordType::class, [
                'label' => 'Mot de passe du compte',
                'attr' => ['autocomplete' => 'current-password', 'autofocus' => true],
                'constraints' => [new UserPassword(message: 'Mot de passe incorrect.')],
            ])
            ->add('code', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux codes ne correspondent pas.',
                'first_options' => ['label' => 'Nouveau code PIN (4 chiffres)', 'attr' => $chiffres],
                'second_options' => ['label' => 'Confirmez le code PIN', 'attr' => $chiffres],
                'constraints' => [
                    new Assert\NotBlank(message: 'Choisissez un code PIN.'),
                    new Assert\Regex(CodePin::FORMAT, message: 'Le code PIN doit comporter exactement 4 chiffres.'),
                ],
            ]);
    }
}
