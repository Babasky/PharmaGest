<?php

namespace App\Achat;

use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Enum\StatutCommande;
use App\Repository\CommandeRepository;

/**
 * Transmission directe des commandes aux grossistes : à partir des suggestions, une commande par fournisseur,
 * puis envoi par email du bon de commande. Les produits sans fournisseur restent à commander à la main.
 *
 * @phpstan-import-type Suggestion from SuggestionsCommande
 */
class TransmissionCommandes
{
    public function __construct(
        private readonly CommandeService $commandes,
        private readonly CommandeRepository $repository,
    ) {
    }

    /**
     * Brouillons prêts à vérifier, un par fournisseur. Avec $reutiliser, les produits sont ajoutés au brouillon déjà
     * ouvert chez ce fournisseur (sans doubler ceux qui y sont déjà), pour qu'une seconde préparation ne crée pas
     * de doublon ; sinon (envoi automatique), une nouvelle commande ne touche pas aux brouillons en cours.
     *
     * @param list<Suggestion> $suggestions
     *
     * @return list<Commande>
     *
     * @throws AchatException
     */
    public function preparer(array $suggestions, bool $reutiliser = true): array
    {
        /** @var array<int, array{fournisseur: Fournisseur, lignes: list<array{0: Produit, 1: int}>}> $groupes */
        $groupes = [];
        foreach ($suggestions as $s) {
            if (null === $s['fournisseur'] || $s['quantite'] <= 0) {
                continue;
            }
            $cle = (int) $s['fournisseur']->getId();
            $groupes[$cle] ??= ['fournisseur' => $s['fournisseur'], 'lignes' => []];
            $groupes[$cle]['lignes'][] = [$s['produit'], $s['quantite']];
        }

        $preparees = [];
        foreach ($groupes as ['fournisseur' => $fournisseur, 'lignes' => $lignes]) {
            $brouillon = $reutiliser ? $this->repository->findOneBy(['fournisseur' => $fournisseur, 'statut' => StatutCommande::Brouillon], ['creeLe' => 'DESC', 'id' => 'DESC']) : null;
            if (null === $brouillon) {
                $preparees[] = $this->commandes->creer($fournisseur, $lignes);
                continue;
            }
            foreach ($lignes as [$produit, $quantite]) {
                if (null === $brouillon->ligneDe($produit)) {
                    $this->commandes->ajouter($brouillon, $produit, $quantite);
                }
            }
            $preparees[] = $brouillon;
        }

        return $preparees;
    }

    /**
     * Envoie chaque brouillon par email à son fournisseur. Un fournisseur sans email garde son brouillon ;
     * un envoi en échec aussi (l'échec est historisé par {@see CommandeService::envoyer()}).
     *
     * @param list<Commande> $brouillons
     *
     * @return array{envoyees: list<Commande>, sans_email: list<Commande>, echecs: list<string>}
     */
    public function transmettre(array $brouillons): array
    {
        $bilan = ['envoyees' => [], 'sans_email' => [], 'echecs' => []];
        foreach ($brouillons as $commande) {
            if (null === $commande->getFournisseur()->getEmail()) {
                $bilan['sans_email'][] = $commande;
                continue;
            }
            try {
                $this->commandes->envoyer($commande);
                $bilan['envoyees'][] = $commande;
            } catch (AchatException $e) {
                $bilan['echecs'][] = $e->getMessage();
            }
        }

        return $bilan;
    }
}
