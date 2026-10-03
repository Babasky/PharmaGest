<?php

namespace App\Tests\Factory;

use App\Entity\Client;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Client>
 */
final class ClientFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Client::class;
    }

    protected function defaults(): array
    {
        return [
            'pharmacie' => PharmacieFactory::new(),
            'nom' => self::faker()->name(), 'telephone' => '+2237'.self::faker()->numerify('#######'),
        ];
    }
}
