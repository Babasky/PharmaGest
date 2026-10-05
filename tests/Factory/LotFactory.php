<?php

namespace App\Tests\Factory;

use App\Entity\Lot;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Lot créé directement en base, sans mouvement : pour préparer un état de stock (lot déjà périmé…).
 * Pour tester les mouvements, passer par {@see \App\Stock\StockService}.
 *
 * @extends PersistentObjectFactory<Lot>
 */
final class LotFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Lot::class;
    }

    protected function defaults(): array
    {
        return [
            'produit' => ProduitFactory::new(),
            'numero' => strtoupper(self::faker()->unique()->bothify('L##??##')),
            'datePeremption' => new \DateTimeImmutable('today +1 year'),
            'quantiteInitiale' => 20,
            'prixAchat' => 800,
            'dateReception' => new \DateTimeImmutable('today -10 days'),
        ];
    }

    protected function initialize(): static
    {
        return $this->afterInstantiate(static function (Lot $lot): void {
            $lot->modifierQuantite($lot->getQuantiteInitiale());
        });
    }
}
