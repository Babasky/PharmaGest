<?php

namespace App\Tests\Factory;

use App\Entity\Entrepot;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Entrepot>
 */
final class EntrepotFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Entrepot::class;
    }

    protected function defaults(): array
    {
        return [
            'pharmacie' => PharmacieFactory::new(),
            'nom' => 'Magasin '.self::faker()->unique()->numberBetween(1, 9999),
        ];
    }
}
