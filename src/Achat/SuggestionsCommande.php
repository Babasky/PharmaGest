<?php

namespace App\Achat;

use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Enum\Rotation;
use App\Repository\CommandeRepository;
use App\Repository\FournisseurRepository;
use App\Repository\LotRepository;
use App\Repository\MouvementStockRepository;
use App\Repository\ProduitRepository;
use App\Service\ParametresPharmacie;
use App\Stock\StockService;
use App\Tenant\TenantContext;

/**
 * Suggestions de commande aux grossistes, groupées par fournisseur.
 *
 * - Selon les tendances de vente (CO-02 enrichi) : produits sous le seuil d'alerte, ou dont le stock atteindra le seuil
 *   avant qu'une commande passée aujourd'hui soit livrée. La quantité couvre le délai de livraison du fournisseur
 *   puis les jours de couverture choisis dans les paramètres, au rythme des ventes moyennes de la période d'analyse,
 *   sans dépasser le stock maximum du produit.
 * - Selon les ventes d'un jour : chaque produit vendu ce jour-là est recommandé dans la quantité vendue.
 *
 * Le fournisseur est le fournisseur habituel du produit, à défaut celui de sa dernière commande (habitudes d'achat).
 *
 * @phpstan-type Suggestion array{produit: Produit, fournisseur: Fournisseur|null, stock: int, attendu: int, quantite: int, vendu: int, moyenne: float, rotation: Rotation, couverture: float|null, delai: int, motif: string, inhabituelle: bool, dernier_achat: array{fournisseur: int, date: \DateTimeImmutable, quantite: int}|null}
 */
class SuggestionsCommande
{
    /** Une quantité qui dépasse ce multiple du dernier achat du produit est signalée « inhabituelle », à vérifier. */
    public const FACTEUR_INHABITUEL = 3;
    /** Délai de livraison retenu pour un fournisseur dont la fiche ne le précise pas, en jours. */
    public const DELAI_LIVRAISON_PAR_DEFAUT = 2;
    /** Les habitudes d'achat se lisent sur les commandes de l'année écoulée. */
    private const JOURS_HABITUDES = 365;

    public function __construct(
        private readonly ProduitRepository $produits,
        private readonly LotRepository $lots,
        private readonly CommandeRepository $commandes,
        private readonly FournisseurRepository $fournisseurs,
        private readonly MouvementStockRepository $mouvements,
        private readonly ParametresPharmacie $parametres,
        private readonly TenantContext $tenantContext,
        private readonly StockService $stock,
    ) {
    }

    /**
     * Quantité proposée = cible − stock actuel − quantité déjà commandée et pas encore livrée.
     *
     * Sans vente sur la période, la cible est le stock maximum (à défaut, le double du seuil d'alerte). Avec des ventes,
     * la cible est le seuil d'alerte plus les ventes attendues pendant le délai de livraison et les jours de couverture,
     * plafonnée au stock maximum. Un produit en rupture et non commandé reçoit au moins 1 unité.
     */
    public static function quantiteProposee(Produit $produit, int $stock, int $attendu, float $ventesParJour = 0.0, int $jours = 0): int
    {
        if ($ventesParJour > 0) {
            $cible = (int) $produit->getSeuilAlerte() + (int) ceil($ventesParJour * $jours);
            if (null !== $produit->getStockMax()) {
                $cible = min($cible, $produit->getStockMax());
            }
        } else {
            $cible = $produit->getStockMax() ?? 2 * (int) $produit->getSeuilAlerte();
        }
        $quantite = $cible - $stock - $attendu;
        if ($quantite <= 0 && 0 === $stock + $attendu) {
            return 1;
        }

        return max(0, $quantite);
    }

    /**
     * Garde-fou : quantité bien plus forte que celle de la dernière commande du produit (pic de ventes, erreur de saisie…).
     * Une commande qui en contient une n'est pas envoyée automatiquement : elle attend la vérification d'un responsable.
     */
    public static function inhabituelle(int $quantite, ?int $dernierAchat): bool
    {
        return null !== $dernierAchat && $dernierAchat > 0 && $quantite > self::FACTEUR_INHABITUEL * $dernierAchat;
    }

    /**
     * Suggestions selon les tendances de vente.
     *
     * @return list<Suggestion>
     */
    public function selonTendances(): array
    {
        $parametres = $this->parametres->pour($this->tenantContext->exigerPharmacie());
        $jour = $this->stock->aujourdhui();
        $periode = $parametres->getJoursAnalyseVentes();
        $ventes = $this->mouvements->ventesNettesParProduit($jour->modify(\sprintf('-%d days', $periode - 1)), $jour->modify('+1 day'));

        /** @var list<Produit> $sousLeSeuil */
        $sousLeSeuil = $this->produits->sousLeSeuil($jour)->getQuery()->getResult();
        $produits = [];
        foreach ($sousLeSeuil as $produit) {
            $produits[(int) $produit->getId()] = $produit;
        }
        $vendus = array_diff(array_keys($ventes), array_keys($produits));
        if ([] !== $vendus) {
            foreach ($this->produits->findBy(['id' => $vendus, 'actif' => true]) as $produit) {
                $produits[(int) $produit->getId()] = $produit;
            }
        }

        $suggestions = [];
        foreach ($this->preparer(array_values($produits), $jour) as $s) {
            $s['vendu'] = $ventes[(int) $s['produit']->getId()] ?? 0;
            $s['moyenne'] = $s['vendu'] / $periode;
            $s['rotation'] = Rotation::depuis($s['moyenne']);
            $seuil = (int) $s['produit']->getSeuilAlerte();
            $disponible = $s['stock'] + $s['attendu'];
            if ($s['moyenne'] > 0) {
                $s['couverture'] = $disponible / $s['moyenne'];
            }
            $pointDeCommande = $seuil + (int) ceil($s['moyenne'] * $s['delai']);
            if ($s['stock'] <= $seuil) {
                $s['motif'] = 0 === $s['stock'] ? 'Rupture' : 'Sous le seuil d\'alerte';
            } elseif ($disponible <= $pointDeCommande) {
                $s['motif'] = 'Seuil atteint avant la livraison';
            } else {
                continue;
            }
            $s['quantite'] = self::quantiteProposee($s['produit'], $s['stock'], $s['attendu'], $s['moyenne'], $s['delai'] + $parametres->getJoursCouverture());
            $s['inhabituelle'] = self::inhabituelle($s['quantite'], $s['dernier_achat']['quantite'] ?? null);
            $suggestions[] = $s;
        }

        return $suggestions;
    }

    /**
     * Suggestions selon les ventes d'un jour : on recommande ce qui a été vendu, sans dépasser le stock maximum.
     *
     * @return list<Suggestion>
     */
    public function selonVentesDu(\DateTimeImmutable $jour): array
    {
        $jour = $jour->setTime(0, 0);
        $ventes = $this->mouvements->ventesNettesParProduit($jour, $jour->modify('+1 day'));
        if ([] === $ventes) {
            return [];
        }
        /** @var list<Produit> $produits */
        $produits = $this->produits->findBy(['id' => array_keys($ventes), 'actif' => true], ['nomCommercial' => 'ASC']);

        $suggestions = [];
        foreach ($this->preparer($produits, $this->stock->aujourdhui()) as $s) {
            $s['vendu'] = $ventes[(int) $s['produit']->getId()];
            $s['motif'] = 'Vendu ce jour';
            $quantite = $s['vendu'];
            if (null !== $max = $s['produit']->getStockMax()) {
                $quantite = min($quantite, max(0, $max - $s['stock'] - $s['attendu']));
            }
            $s['quantite'] = $quantite;
            $s['inhabituelle'] = self::inhabituelle($quantite, $s['dernier_achat']['quantite'] ?? null);
            $suggestions[] = $s;
        }

        return $suggestions;
    }

    /**
     * Nombre de produits à commander par fournisseur ; la clé 0 regroupe les produits sans fournisseur actif.
     *
     * @param list<Suggestion>|null $suggestions null : selon les tendances de vente
     *
     * @return array<int, array{fournisseur: Fournisseur|null, nombre: int}>
     */
    public function parFournisseur(?array $suggestions = null): array
    {
        $groupes = [];
        foreach ($suggestions ?? $this->selonTendances() as $suggestion) {
            $fournisseur = $suggestion['fournisseur'];
            $cle = (int) $fournisseur?->getId();
            $groupes[$cle] ??= ['fournisseur' => $fournisseur, 'nombre' => 0];
            ++$groupes[$cle]['nombre'];
        }
        uasort($groupes, static fn (array $a, array $b) => [null === $a['fournisseur'], $a['fournisseur']?->getNom()] <=> [null === $b['fournisseur'], $b['fournisseur']?->getNom()]);

        return $groupes;
    }

    /**
     * Suggestions d'un fournisseur (null : produits sans fournisseur actif).
     *
     * @param list<Suggestion>|null $suggestions null : selon les tendances de vente
     *
     * @return list<Suggestion>
     */
    public function pour(?Fournisseur $fournisseur, ?array $suggestions = null): array
    {
        return array_values(array_filter(
            $suggestions ?? $this->selonTendances(),
            static fn (array $s) => $s['fournisseur'] === $fournisseur,
        ));
    }

    /**
     * Stock, quantités attendues, fournisseur et dernier achat de chaque produit.
     *
     * @param list<Produit> $produits
     *
     * @return list<Suggestion>
     */
    private function preparer(array $produits, \DateTimeImmutable $jour): array
    {
        if ([] === $produits) {
            return [];
        }
        $synthese = $this->lots->syntheseParProduit($produits, $jour);
        $attendus = $this->commandes->quantitesAttendues();
        $achats = $this->commandes->derniersAchats($jour->modify(\sprintf('-%d days', self::JOURS_HABITUDES)));
        usort($produits, static fn (Produit $a, Produit $b) => strcasecmp($a->getNomCommercial(), $b->getNomCommercial()));

        $suggestions = [];
        foreach ($produits as $produit) {
            $id = (int) $produit->getId();
            $fournisseur = $this->fournisseur($produit, $achats[$id]['fournisseur'] ?? null);
            $suggestions[] = [
                'produit' => $produit,
                'fournisseur' => $fournisseur,
                'stock' => $synthese[$id]['stock'] ?? 0,
                'attendu' => $attendus[$id] ?? 0,
                'quantite' => 0,
                'vendu' => 0,
                'moyenne' => 0.0,
                'rotation' => Rotation::Nulle,
                'couverture' => null,
                'delai' => $fournisseur?->getDelaiLivraison() ?? self::DELAI_LIVRAISON_PAR_DEFAUT,
                'motif' => '',
                'inhabituelle' => false,
                'dernier_achat' => $achats[$id] ?? null,
            ];
        }

        return $suggestions;
    }

    /** Fournisseur habituel actif, sinon celui de la dernière commande du produit s'il est encore actif. */
    private function fournisseur(Produit $produit, ?int $dernier): ?Fournisseur
    {
        $habituel = $produit->getFournisseurHabituel();
        if (null !== $habituel && $habituel->isActif()) {
            return $habituel;
        }
        $fournisseur = null !== $dernier ? $this->fournisseurs->find($dernier) : null;

        return null !== $fournisseur && $fournisseur->isActif() ? $fournisseur : null;
    }
}
