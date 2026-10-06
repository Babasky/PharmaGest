<?php

namespace App\Stock;

use App\Repository\LotRepository;
use App\Repository\ProduitRepository;
use App\Service\ParametresPharmacie;
use App\Tenant\TenantContext;
use App\Util\Page;
use Doctrine\ORM\QueryBuilder;

/**
 * Alertes de stock de la pharmacie courante (ST-05).
 */
class AlertesStock
{
    public const SEUIL = 'seuil';
    public const PEREMPTION = 'peremption';
    public const PERIMES = 'perimes';
    public const DORMANTS = 'dormants';

    public const LIBELLES = [
        self::SEUIL => 'Rupture ou sous le seuil',
        self::PEREMPTION => 'Péremption proche',
        self::PERIMES => 'Lots périmés en stock',
        self::DORMANTS => 'Produits dormants',
    ];

    /** Sans vente depuis ce nombre de jours, un produit en stock est « dormant ». */
    public const JOURS_DORMANT = 90;

    public function __construct(
        private readonly ProduitRepository $produits,
        private readonly LotRepository $lots,
        private readonly ParametresPharmacie $parametres,
        private readonly TenantContext $tenantContext,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @return array<string, int> nombre d'alertes par type
     */
    public function compter(): array
    {
        $nombres = [];
        foreach (array_keys(self::LIBELLES) as $type) {
            $nombres[$type] = Page::depuis($this->requete($type), 1, 1)->total;
        }

        return $nombres;
    }

    /**
     * @return Page<mixed> produits (seuil, dormants) ou lots (péremption, périmés)
     */
    public function liste(string $type, int $page): Page
    {
        return Page::depuis($this->requete($type), $page);
    }

    /**
     * Identifiants des produits ou lots concernés par une alerte (notifications, NO-01).
     *
     * @return list<int>
     */
    public function identifiants(string $type): array
    {
        $qb = $this->requete($type);
        $alias = $qb->getRootAliases()[0];

        return array_map('intval', $qb->select($alias.'.id')->resetDQLPart('orderBy')->getQuery()->getSingleColumnResult());
    }

    public function delaiPeremption(): int
    {
        return $this->parametres->pour($this->tenantContext->exigerPharmacie())->getDelaiAlertePeremption();
    }

    private function requete(string $type): QueryBuilder
    {
        $jour = $this->stock->aujourdhui();

        return match ($type) {
            self::SEUIL => $this->produits->sousLeSeuil($jour),
            self::PEREMPTION => $this->lots->peremptionProche($jour, $this->delaiPeremption()),
            self::PERIMES => $this->lots->perimesEnStock($jour),
            self::DORMANTS => $this->produits->dormants($jour, self::JOURS_DORMANT),
            default => throw new \InvalidArgumentException(\sprintf('Type d\'alerte inconnu : %s.', $type)),
        };
    }
}
