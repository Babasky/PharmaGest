<?php

namespace App\Tests\Factory;

use App\Entity\Fournisseur;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Fournisseur>
 */
final class FournisseurFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Fournisseur::class;
    }

    protected function defaults(): array
    {
        return [
            'pharmacie' => PharmacieFactory::new(),
            'nom' => 'Fournisseur '.self::faker()->unique()->company(),
        ];
    }
}
