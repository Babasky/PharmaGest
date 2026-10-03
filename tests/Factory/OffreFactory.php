<?php

namespace App\Tests\Factory;

use App\Entity\Offre;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Les trois offres sont créées par la migration : la factory les retrouve par code.
 *
 * @extends PersistentObjectFactory<Offre>
 */
final class OffreFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Offre::class;
    }

    public static function parCode(string $code): Offre
    {
        return self::findBy(['code' => $code])[0]
            ?? throw new \RuntimeException(\sprintf('Offre %s absente : jouez les migrations sur la base de test.', $code));
    }

    protected function defaults(): array
    {
        return [
            'code' => self::faker()->unique()->lexify('offre-????'),
            'nom' => self::faker()->word(),
            'maxUtilisateurs' => 5,
            'maxPharmacies' => 1,
        ];
    }
}
