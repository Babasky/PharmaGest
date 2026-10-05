<?php

namespace App\Achat;

use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Repository\CommandeRepository;
use App\Repository\LotRepository;
use App\Repository\ProduitRepository;
use App\Stock\StockService;

/**
 * Suggestion de commande (CO-02) : produits au seuil d'alerte ou en dessous, groupés par fournisseur habituel,
 * avec la quantité qui ramène le stock au stock maximum.
 */
class SuggestionsCommande
{
    public function __construct(
        private readonly ProduitRepository $produits,
        private readonly LotRepository $lots,
        private readonly CommandeRepository $commandes,
        private readonly StockService $stock,
    ) {
    }

    /**
     * Quantité proposée = stock maximum − stock actuel − quantité déjà commandée et pas encore livrée.
     * Sans stock maximum, on vise le double du seuil d'alerte ; un produit en rupture et non commandé
     * reçoit au moins 1 unité.
     */
    public static function quantiteProposee(Produit $produit, int $stock, int $attendu): int
    {
        $cible = $produit->getStockMax() ?? 2 * (int) $produit->getSeuilAlerte();
        $quantite = $cible - $stock - $attendu;
        if ($quantite <= 0 && 0 === $stock + $attendu) {
            return 1;
        }

        return max(0, $quantite);
    }

    /**
     * Nombre de produits à commander par fournisseur habituel ; la clé 0 regroupe les produits sans fournisseur
     * habituel actif.
     *
     * @return array<int, array{fournisseur: Fournisseur|null, nombre: int}>
     */
    public function parFournisseur(): array
    {
        $groupes = [];
        foreach ($this->suggestions() as $suggestion) {
            $fournisseur = $this->fournisseurActif($suggestion['produit']);
            $cle = (int) $fournisseur?->getId();
            $groupes[$cle] ??= ['fournisseur' => $fournisseur, 'nombre' => 0];
            ++$groupes[$cle]['nombre'];
        }
        uasort($groupes, static fn (array $a, array $b) => [null === $a['fournisseur'], $a['fournisseur']?->getNom()] <=> [null === $b['fournisseur'], $b['fournisseur']?->getNom()]);

        return $groupes;
    }

    /**
     * Suggestions pour un fournisseur (null : produits sans fournisseur habituel actif).
     *
     * @return list<array{produit: Produit, stock: int, attendu: int, quantite: int}>
     */
    public function pour(?Fournisseur $fournisseur): array
    {
        return array_values(array_filter(
            $this->suggestions(),
            fn (array $s) => $this->fournisseurActif($s['produit']) === $fournisseur,
        ));
    }

    /**
     * @return list<array{produit: Produit, stock: int, attendu: int, quantite: int}>
     */
    private function suggestions(): array
    {
        $jour = $this->stock->aujourdhui();
        /** @var list<Produit> $produits */
        $produits = $this->produits->sousLeSeuil($jour)->getQuery()->getResult();
        $synthese = $this->lots->syntheseParProduit($produits, $jour);
        $attendus = $this->commandes->quantitesAttendues();

        $suggestions = [];
        foreach ($produits as $produit) {
            $id = (int) $produit->getId();
            $stock = $synthese[$id]['stock'] ?? 0;
            $attendu = $attendus[$id] ?? 0;
            $suggestions[] = ['produit' => $produit, 'stock' => $stock, 'attendu' => $attendu, 'quantite' => self::quantiteProposee($produit, $stock, $attendu)];
        }

        return $suggestions;
    }

    private function fournisseurActif(Produit $produit): ?Fournisseur
    {
        $fournisseur = $produit->getFournisseurHabituel();

        return null !== $fournisseur && $fournisseur->isActif() ? $fournisseur : null;
    }
}
