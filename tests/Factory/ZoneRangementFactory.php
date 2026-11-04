<?php

namespace App\Tests\Factory;

use App\Entity\ZoneRangement;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ZoneRangement>
 */
final class ZoneRangementFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ZoneRangement::class;
    }

    protected function defaults(): array
    {
        return [
            'pharmacie' => PharmacieFactory::new(),
            'nom' => 'Zone '.self::faker()->unique()->numberBetween(1, 99999),
        ];
    }
}
