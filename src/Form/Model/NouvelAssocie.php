<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pharmacien qui devient propriétaire associé d'une pharmacie ({@see \App\Service\GestionProprietaires}).
 */
final class NouvelAssocie
{
    #[Assert\NotBlank(message: 'Ce champ ne doit pas être vide')]
    #[Assert\Length(max: 120)]
    public ?string $nom = null;

    #[Assert\NotBlank(message: 'Ce champ ne doit pas être vide')]
    #[Assert\Email(message: 'Email invalide')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;
}
