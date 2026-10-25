<?php

namespace App\Stock;

use App\Entity\Entrepot;
use App\Entity\Produit;
use App\Entity\TransfertInterne;
use App\Enum\TypeMouvement;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Transferts de stock entre l'officine et les entrepôts d'une même pharmacie, ou d'un entrepôt à l'autre.
 *
 * Tout se fait en une transaction : chaque produit sort de l'origine en FEFO (les lots périmés ne partent jamais) et
 * entre aussitôt à la destination avec les mêmes lots. Les deux mouvements sont de type « Transfert » et portent le
 * numéro TRI-AAAA-NNNNNN du transfert.
 */
class TransfertInterneService
{
    public const PREFIXE = 'TRI';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
        private readonly TenantContext $tenantContext,
        private readonly Numeroteur $numeroteur,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * @param array<int, array{0: Produit, 1: int}> $lignes produit et quantité à déplacer
     *
     * @throws StockException
     */
    public function transferer(?Entrepot $origine, ?Entrepot $destination, array $lignes, ?string $note = null): TransfertInterne
    {
        $pharmacie = $this->tenantContext->exigerPharmacie();
        if ($origine?->getId() === $destination?->getId()) {
            throw new StockException('Choisissez une destination différente de l\'origine.');
        }
        foreach ([$origine, $destination] as $entrepot) {
            if (null === $entrepot) {
                continue;
            }
            if ($entrepot->getPharmacie()?->getId() !== $pharmacie->getId()) {
                throw new StockException('Cet entrepôt ne fait pas partie de votre pharmacie : choisissez-en un dans la liste.');
            }
            if (!$entrepot->isActif()) {
                throw new StockException(\sprintf('L\'entrepôt %s est archivé.', $entrepot->getNom()));
            }
        }

        $quantites = [];
        $produits = [];
        foreach ($lignes as [$produit, $quantite]) {
            if ($quantite <= 0) {
                continue;
            }
            if ($produit->getPharmacie()?->getId() !== $pharmacie->getId()) {
                throw new StockException('Produit introuvable.');
            }
            if (!$produit->isActif()) {
                throw new StockException(\sprintf('Le produit %s est archivé.', $produit->getNomCommercial()));
            }
            $id = (int) $produit->getId();
            $produits[$id] = $produit;
            $quantites[$id] = ($quantites[$id] ?? 0) + $quantite;
        }
        if ([] === $quantites) {
            throw new StockException('Indiquez au moins un produit et une quantité à transférer.');
        }
        $note = null === $note || '' === trim($note) ? null : mb_substr(trim($note), 0, 255);

        return $this->stock->transaction(function () use ($pharmacie, $origine, $destination, $produits, $quantites, $note): TransfertInterne {
            $transfert = new TransfertInterne(
                $this->numeroteur->suivant(self::PREFIXE, $pharmacie),
                $origine,
                $destination,
                $this->tenantContext->getUtilisateur(),
                $this->horloge->now(),
                $note,
            );
            $this->em->persist($transfert);

            $resume = [];
            foreach ($quantites as $id => $quantite) {
                $produit = $produits[$id];
                $this->em->persist($transfert->ajouterLigne($produit, $quantite));
                foreach ($this->stock->prelever($produit, $quantite, TypeMouvement::Transfert, $transfert->getNumero(), $origine) as $prelevement) {
                    $this->stock->rangerDepuisTransfertInterne($prelevement->lot, $prelevement->quantite, $destination, $transfert->getNumero());
                }
                $resume[] = ['produit' => $produit->getNomCommercial(), 'quantite' => $quantite];
            }
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::TRANSFERT_INTERNE, $pharmacie, $transfert, null, [
                'numero' => $transfert->getNumero(),
                'origine' => $transfert->getLibelleOrigine(),
                'destination' => $transfert->getLibelleDestination(),
                'produits' => $resume,
            ]);
            $this->em->flush();

            return $transfert;
        });
    }
}
