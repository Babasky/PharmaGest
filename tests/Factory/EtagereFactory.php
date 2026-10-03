<?php

namespace App\Tests\Factory;

use App\Entity\Etagere;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Etagere>
 */
final class EtagereFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Etagere::class;
    }

    protected function defaults(): array
    {
        return [
            'pharmacie' => PharmacieFactory::new(),
            'code' => 'E'.self::faker()->unique()->numberBetween(1, 9999), 'libelle' => self::faker()->word(),
        ];
    }
}
