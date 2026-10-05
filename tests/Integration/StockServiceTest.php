<?php

namespace App\Tests\Integration;

use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\Pharmacie;
use App\Entity\Produit;
use App\Enum\TypeMouvement;
use App\Stock\StockException;
use App\Stock\StockService;
use App\Tenant\TenantContext;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\ProduitFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Règles de stock RG-03 à RG-05 et scénarios de recette R-03 et R-04.
 */
final class StockServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private StockService $stock;
    private EntityManagerInterface $em;
    private Pharmacie $pharmacie;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-05 09:00:00');
        $this->stock = self::getContainer()->get(StockService::class);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pharmacie = PharmacieFactory::createOne();
        self::getContainer()->get(TenantContext::class)->forcer($this->pharmacie);
    }

    /**
     * @param array<string, mixed> $attributs
     */
    private function produit(array $attributs = []): Produit
    {
        return ProduitFactory::createOne(['pharmacie' => $this->pharmacie, ...$attributs]);
    }

    /**
     * @param array<string, mixed> $attributs
     */
    private function lot(array $attributs = []): Lot
    {
        return LotFactory::createOne(['produit' => $attributs['produit'] ?? $this->produit(), ...$attributs]);
    }

    public function testR04SortieFefoSurPlusieursLots(): void
    {
        $produit = $this->produit();
        $a = $this->stock->entrer($produit, 'A', new \DateTimeImmutable('2027-03-31'), 10, 800);
        $b = $this->stock->entrer($produit, 'B', new \DateTimeImmutable('2027-01-31'), 10, 750);

        $prelevements = $this->stock->prelever($produit, 15, TypeMouvement::Vente, 'V-2026-000001');

        self::assertSame([['B', 10, 750], ['A', 5, 800]], array_map(static fn ($p) => [$p->lot->getNumero(), $p->quantite, $p->getPrixAchat()], $prelevements));
        self::assertSame(0, $b->getQuantiteRestante());
        self::assertSame(5, $a->getQuantiteRestante());
        self::assertSame(5, $this->stock->stockDisponible($produit));

        $mouvements = $this->em->getRepository(MouvementStock::class)->findBy(['type' => TypeMouvement::Vente], ['id' => 'ASC']);
        self::assertSame([[-10, 0, 'V-2026-000001'], [-5, 5, 'V-2026-000001']], array_map(static fn (MouvementStock $m) => [$m->getQuantite(), $m->getQuantiteApres(), $m->getDocument()], $mouvements));
    }

    public function testR03SeulUnLotPerimeEnStockVenteRefusee(): void
    {
        $lot = $this->lot(['produit' => $this->produit(['nomCommercial' => 'Coartem']), 'numero' => 'P1', 'datePeremption' => new \DateTimeImmutable('2026-10-05')]);

        try {
            $this->stock->prelever($lot->getProduit(), 1);
            self::fail('La vente d\'un lot périmé aurait dû être refusée.');
        } catch (StockException $e) {
            self::assertSame('Vente impossible : le seul stock de Coartem est périmé (lot P1, périmé le 05/10/2026).', $e->getMessage());
        }
        self::assertSame(0, $this->stock->stockDisponible($lot->getProduit()), 'RG-03 : un lot périmé ne compte pas dans le stock.');
        self::assertSame(20, $lot->getQuantiteRestante());
    }

    public function testLeStockNeDevientJamaisNegatif(): void
    {
        $lot = $this->lot(['quantiteInitiale' => 3]);
        $this->expectExceptionMessage('3 disponible(s) pour 4 demandé(s)');
        try {
            $this->stock->prelever($lot->getProduit(), 4);
        } finally {
            $this->em->refresh($lot);
            self::assertSame(3, $lot->getQuantiteRestante());
            self::assertCount(0, $this->em->getRepository(MouvementStock::class)->findAll());
        }
    }

    public function testUnLotDejaPerimeNEntrePasEnStock(): void
    {
        $this->expectException(StockException::class);
        $this->stock->entrer($this->produit(), 'X', new \DateTimeImmutable('2026-10-05'), 5, 100);
    }

    public function testAjustementEtDestructionTracesDansLeJournal(): void
    {
        $lot = $this->lot(['quantiteInitiale' => 10]);

        $this->stock->ajuster($lot, 8, 'Boîtes abîmées');
        $this->stock->detruire($lot, 3, 'Emballage écrasé');

        self::assertSame(5, $lot->getQuantiteRestante());
        $types = array_map(static fn (MouvementStock $m) => [$m->getType(), $m->getQuantite()], $this->em->getRepository(MouvementStock::class)->findBy([], ['id' => 'ASC']));
        self::assertSame([[TypeMouvement::Ajustement, -2], [TypeMouvement::Destruction, -3]], $types);

        $actions = $this->em->createQuery('SELECT j.action FROM App\Entity\JournalAudit j ORDER BY j.id')->getSingleColumnResult();
        self::assertSame(['stock.ajustement', 'stock.destruction'], $actions);

        $this->expectExceptionMessage('ne contient que 5 unité(s)');
        $this->stock->detruire($lot, 6, 'Trop');
    }

    public function testUnLotPerimeSortParDestruction(): void
    {
        $lot = $this->lot(['datePeremption' => new \DateTimeImmutable('2026-09-30'), 'quantiteInitiale' => 4]);
        $this->stock->detruire($lot, 4, 'Périmé');
        self::assertSame(0, $lot->getQuantiteRestante());
        self::assertInstanceOf(Lot::class, $this->em->find(Lot::class, $lot->getId()));
    }
}
