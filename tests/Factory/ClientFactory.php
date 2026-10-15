<?php

namespace App\Tests\Factory;

use App\Entity\Client;
use App\Entity\OrganismeAmo;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Assurances : « organismeAmo » + « numeroAssure » pour une seule, ou « assurances » => [[organisme, n°], …].
 *
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

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(Instantiator::withConstructor()->allowExtra('organismeAmo', 'numeroAssure', 'assurances'))
            ->afterInstantiate(static function (Client $client, array $attributs): void {
                /** @var list<array{OrganismeAmo, string}> $assurances */
                $assurances = $attributs['assurances'] ?? [];
                if (($attributs['organismeAmo'] ?? null) instanceof OrganismeAmo) {
                    array_unshift($assurances, [$attributs['organismeAmo'], (string) ($attributs['numeroAssure'] ?? '')]);
                }
                foreach ($assurances as [$organisme, $numero]) {
                    $client->definirAssurance($organisme, $numero);
                }
            });
    }
}
