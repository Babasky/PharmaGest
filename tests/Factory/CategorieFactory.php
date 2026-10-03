<?php

namespace App\Tests\Factory;

use App\Entity\Categorie;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Categorie>
 */
final class CategorieFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Categorie::class;
    }

    protected function defaults(): array
    {
        return [
            'pharmacie' => PharmacieFactory::new(),
            'nom' => self::faker()->unique()->words(2, true),
        ];
    }
}
