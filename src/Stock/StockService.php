<?php

namespace App\Stock;

use App\Entity\Fournisseur;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\Produit;
use App\Enum\TypeMouvement;
use App\Repository\LotRepository;
use App\Service\AuditLogger;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Seul point d'entrée pour faire varier le stock (ST-04) : chaque variation d'un lot écrit un mouvement typé.
 *
 * Règles : stock = lots non périmés (RG-03), sortie FEFO (RG-04), lot périmé invendable (RG-05),
 * stock jamais négatif.
 */
class StockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LotRepository $lots,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function aujourdhui(): \DateTimeImmutable
    {
        return $this->horloge->now()->setTime(0, 0);
    }

    public function stockDisponible(Produit $produit): int
    {
        return $this->lots->stockDisponible($produit, $this->aujourdhui());
    }

    /**
     * Crée un lot et son mouvement d'entrée. Sert à l'entrée manuelle (stock initial, livraison sans commande)
     * et, au Lot 6, à la réception des commandes.
     *
     * @throws StockException
     */
    public function entrer(
        Produit $produit,
        string $numero,
        \DateTimeImmutable $datePeremption,
        int $quantite,
        int $prixAchat,
        ?Fournisseur $fournisseur = null,
        TypeMouvement $type = TypeMouvement::EntreeManuelle,
        ?string $motif = null,
        ?string $document = null,
    ): Lot {
        if ('' === trim($numero)) {
            throw new StockException('Le numéro de lot est obligatoire.');
        }
        if ($quantite <= 0) {
            throw new StockException('La quantité doit être supérieure à zéro.');
        }
        if ($prixAchat < 0) {
            throw new StockException('Le prix d\'achat ne peut pas être négatif.');
        }
        if ($datePeremption <= $this->aujourdhui()) {
            throw new StockException(\sprintf('Le lot %s est déjà périmé (%s) : il ne peut pas entrer en stock.', trim($numero), $datePeremption->format('d/m/Y')));
        }
        if (!$produit->isActif()) {
            throw new StockException(\sprintf('Le produit %s est archivé.', $produit->getNomCommercial()));
        }

        return $this->transaction(function () use ($produit, $numero, $datePeremption, $quantite, $prixAchat, $fournisseur, $type, $motif, $document): Lot {
            $lot = new Lot($produit, $numero, $datePeremption, $quantite, $prixAchat, $this->aujourdhui(), $fournisseur);
            $this->em->persist($lot);
            $this->mouvementer($lot, $type, $quantite, $motif, $document);

            if (TypeMouvement::EntreeManuelle === $type) {
                $this->em->flush();
                $this->audit->journaliser(AuditLogger::STOCK_ENTREE, $produit->getPharmacie(), $lot, null, [
                    'produit' => $produit->getNomCommercial(),
                    'lot' => $lot->getNumero(),
                    'peremption' => $datePeremption->format('Y-m-d'),
                    'quantite' => $quantite,
                    'prix_achat' => $prixAchat,
                    'motif' => $motif,
                ]);
            }
            $this->em->flush();

            return $lot;
        });
    }

    /**
     * Sort une quantité d'un produit en FEFO (RG-04) : les lots qui périment en premier sortent en premier,
     * une même sortie peut consommer plusieurs lots. Les lots périmés ne sortent jamais ainsi (RG-05).
     *
     * @return list<Prelevement>
     *
     * @throws StockException
     */
    public function prelever(Produit $produit, int $quantite, TypeMouvement $type = TypeMouvement::Vente, ?string $document = null): array
    {
        if ($quantite <= 0) {
            throw new StockException('La quantité doit être supérieure à zéro.');
        }

        return $this->transaction(function () use ($produit, $quantite, $type, $document): array {
            $disponibles = $this->lots->verrouillerDisponibles($produit, $this->aujourdhui());
            $total = array_sum(array_map(static fn (Lot $l) => $l->getQuantiteRestante(), $disponibles));

            if ($total < $quantite) {
                throw new StockException($this->messageStockInsuffisant($produit, $total, $quantite));
            }

            $prelevements = [];
            $reste = $quantite;
            foreach ($disponibles as $lot) {
                $prise = min($reste, $lot->getQuantiteRestante());
                $this->mouvementer($lot, $type, -$prise, null, $document);
                $prelevements[] = new Prelevement($lot, $prise);
                $reste -= $prise;
                if (0 === $reste) {
                    break;
                }
            }
            $this->em->flush();

            return $prelevements;
        });
    }

    /**
     * Vérifie, sans rien écrire, qu'une quantité peut sortir en FEFO (contrôle de la caisse avant validation).
     *
     * @throws StockException
     */
    public function verifierDisponible(Produit $produit, int $quantite): void
    {
        $disponible = $this->stockDisponible($produit);
        if ($disponible < $quantite) {
            throw new StockException($this->messageStockInsuffisant($produit, $disponible, $quantite));
        }
    }

    /**
     * Remet des unités dans leur lot d'origine (annulation d'une vente, RG-12).
     *
     * @throws StockException
     */
    public function reintegrer(Lot $lot, int $quantite, TypeMouvement $type, ?string $document, ?string $motif = null): MouvementStock
    {
        if ($quantite <= 0) {
            throw new StockException('La quantité doit être supérieure à zéro.');
        }

        return $this->transaction(function () use ($lot, $quantite, $type, $document, $motif): MouvementStock {
            $this->lots->verrouiller($lot);
            $mouvement = $this->mouvementer($lot, $type, $quantite, $motif, $document);
            $this->em->flush();

            return $mouvement;
        });
    }

    /**
     * Corrige la quantité d'un lot (casse, erreur de saisie, écart d'inventaire). Action tracée (AU-01).
     *
     * @throws StockException
     */
    public function ajuster(Lot $lot, int $nouvelleQuantite, string $motif, ?string $document = null): ?MouvementStock
    {
        if ($nouvelleQuantite < 0) {
            throw new StockException('La quantité d\'un lot ne peut pas être négative.');
        }
        if ('' === trim($motif)) {
            throw new StockException('Le motif de l\'ajustement est obligatoire.');
        }

        return $this->transaction(function () use ($lot, $nouvelleQuantite, $motif, $document): ?MouvementStock {
            $this->lots->verrouiller($lot);
            $avant = $lot->getQuantiteRestante();
            if ($avant === $nouvelleQuantite) {
                return null;
            }

            $mouvement = $this->mouvementer($lot, TypeMouvement::Ajustement, $nouvelleQuantite - $avant, trim($motif), $document);
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::STOCK_AJUSTEMENT, $lot->getPharmacie(), $lot,
                ['produit' => $lot->getProduit()->getNomCommercial(), 'lot' => $lot->getNumero(), 'quantite' => $avant],
                ['produit' => $lot->getProduit()->getNomCommercial(), 'lot' => $lot->getNumero(), 'quantite' => $nouvelleQuantite, 'motif' => trim($motif), 'document' => $document],
            );
            $this->em->flush();

            return $mouvement;
        });
    }

    /**
     * Sort d'un lot des unités à détruire (périmées, abîmées). Seule sortie possible d'un lot périmé
     * avec le retour fournisseur (RG-05). Action tracée (AU-01).
     *
     * @throws StockException
     */
    public function detruire(Lot $lot, int $quantite, string $motif): MouvementStock
    {
        if ($quantite <= 0) {
            throw new StockException('La quantité doit être supérieure à zéro.');
        }
        if ('' === trim($motif)) {
            throw new StockException('Le motif de la destruction est obligatoire.');
        }

        return $this->transaction(function () use ($lot, $quantite, $motif): MouvementStock {
            $this->lots->verrouiller($lot);
            if ($quantite > $lot->getQuantiteRestante()) {
                throw new StockException(\sprintf('Le lot %s ne contient que %d unité(s).', $lot->getNumero(), $lot->getQuantiteRestante()));
            }

            $mouvement = $this->mouvementer($lot, TypeMouvement::Destruction, -$quantite, trim($motif), null);
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::STOCK_DESTRUCTION, $lot->getPharmacie(), $lot, null, [
                'produit' => $lot->getProduit()->getNomCommercial(),
                'lot' => $lot->getNumero(),
                'quantite' => $quantite,
                'valeur' => $quantite * $lot->getPrixAchat(),
                'motif' => trim($motif),
            ]);
            $this->em->flush();

            return $mouvement;
        });
    }

    /**
     * Applique un écart d'inventaire validé (compté − théorique) à la quantité actuelle du lot.
     * L'inventaire est tracé dans son ensemble par {@see GestionInventaire}.
     *
     * @internal
     */
    public function appliquerEcartInventaire(Lot $lot, int $ecart, string $document): MouvementStock
    {
        if ($lot->getQuantiteRestante() + $ecart < 0) {
            throw new StockException(\sprintf('Le lot %s de %s ne peut pas descendre sous zéro : vérifiez son comptage.', $lot->getNumero(), $lot->getProduit()->getNomCommercial()));
        }

        return $this->mouvementer($lot, TypeMouvement::Ajustement, $ecart, 'Écart d\'inventaire', $document);
    }

    /**
     * Transaction qui, contrairement à EntityManager::wrapInTransaction(), ne ferme pas le gestionnaire
     * quand une règle de gestion est refusée : la page peut encore afficher le message.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $resultat = $operation();
            $connexion->commit();

            return $resultat;
        } catch (\Throwable $e) {
            $connexion->rollBack();
            throw $e;
        }
    }

    private function mouvementer(Lot $lot, TypeMouvement $type, int $variation, ?string $motif, ?string $document): MouvementStock
    {
        $lot->modifierQuantite($variation);
        $mouvement = new MouvementStock($lot, $type, $variation, $this->horloge->now(), $this->tenantContext->getUtilisateur(), $motif, $document);
        $this->em->persist($mouvement);

        return $mouvement;
    }

    private function messageStockInsuffisant(Produit $produit, int $disponible, int $demande): string
    {
        if (0 === $disponible) {
            foreach ($this->lots->enStock($produit) as $lot) {
                if ($lot->estPerimeLe($this->aujourdhui())) {
                    return \sprintf('Vente impossible : le seul stock de %s est périmé (lot %s, périmé le %s).',
                        $produit->getNomCommercial(), $lot->getNumero(), $lot->getDatePeremption()->format('d/m/Y'));
                }
            }

            return \sprintf('%s est en rupture de stock.', $produit->getNomCommercial());
        }

        return \sprintf('Stock insuffisant pour %s : %d disponible(s) pour %d demandé(s).', $produit->getNomCommercial(), $disponible, $demande);
    }
}
