<?php

namespace App\Tests\Factory;

use App\Entity\Utilisateur;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Mot de passe de tous les comptes de test : {@see self::MOT_DE_PASSE}.
 *
 * @extends PersistentObjectFactory<Utilisateur>
 */
final class UtilisateurFactory extends PersistentObjectFactory
{
    public const MOT_DE_PASSE = 'motdepasse';

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    public static function class(): string
    {
        return Utilisateur::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'nom' => self::faker()->name(),
            'role' => Utilisateur::ROLE_VENDEUR,
            'motDePasse' => self::MOT_DE_PASSE,
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(Instantiator::withConstructor()->allowExtra('motDePasse'))
            ->afterInstantiate(function (Utilisateur $utilisateur, array $attributs): void {
                if (null !== ($attributs['motDePasse'] ?? null)) {
                    $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, $attributs['motDePasse']));
                }
            });
    }

    public function superAdmin(): self
    {
        return $this->with(['role' => Utilisateur::ROLE_SUPER_ADMIN]);
    }

    /** Compte créé mais pas encore activé (aucun mot de passe). */
    public function nonActive(): self
    {
        return $this->with(['motDePasse' => null]);
    }
}
