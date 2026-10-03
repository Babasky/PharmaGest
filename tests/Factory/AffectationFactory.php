<?php

namespace App\Tests\Factory;

use App\Entity\Affectation;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Affectation>
 */
final class AffectationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Affectation::class;
    }

    protected function defaults(): array
    {
        return [
            'utilisateur' => UtilisateurFactory::new(),
            'pharmacie' => PharmacieFactory::new(),
        ];
    }
}
