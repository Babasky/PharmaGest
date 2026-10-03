<?php

namespace App\Tests\Factory;

use App\Entity\Produit;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Produit>
 */
final class ProduitFactory extends PersistentObjectFactory
{
    private const MOLECULES = ['Paracétamol', 'Amoxicilline', 'Ibuprofène', 'Métronidazole', 'Artéméther', 'Oméprazole', 'Cotrimoxazole'];

    public static function class(): string
    {
        return Produit::class;
    }

    protected function defaults(): array
    {
        return [
            'pharmacie' => PharmacieFactory::new(),
            'nomCommercial' => ucfirst(self::faker()->unique()->lexify('??????')).'ol',
            'dci' => self::faker()->randomElement(self::MOLECULES),
            'dosage' => self::faker()->randomElement(['250 mg', '500 mg', '1 g']),
            'prixAchat' => 800,
            'prixVente' => 1200,
        ];
    }
}
