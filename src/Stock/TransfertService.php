<?php

namespace App\Stock;

use App\Entity\LigneTransfert;
use App\Entity\Pharmacie;
use App\Entity\Produit;
use App\Entity\TransfertStock;
use App\Enum\TypeMouvement;
use App\Repository\AffectationRepository;
use App\Repository\ProduitRepository;
use App\Repository\TransfertStockRepository;
use App\Service\AuditLogger;
use App\Service\GenerateurNotifications;
use App\Service\Numeroteur;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Transferts de stock entre officines (ST-11), permis seulement entre officines d'un même propriétaire.
 *
 * L'officine d'origine prépare le transfert, puis l'expédie : le stock sort en FEFO et les lots prélevés sont notés.
 * L'officine destinataire confirme la réception : les mêmes lots (numéro, péremption, prix d'achat) entrent chez elle.
 * Les deux mouvements sont de type « Transfert » et portent le numéro du transfert.
 */
class TransfertService
{
    public const PREFIXE = 'TRF';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
        private readonly TenantContext $tenantContext,
        private readonly AffectationRepository $affectations,
        private readonly ProduitRepository $produits,
        private readonly TransfertStockRepository $transferts,
        private readonly Numeroteur $numeroteur,
        private readonly AuditLogger $audit,
        private readonly GenerateurNotifications $notifications,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * Officines vers lesquelles l'officine peut transférer : celles d'un de ses propriétaires,
     * sauf les officines archivées ou suspendues.
     *
     * @return list<Pharmacie>
     */
    public function destinations(Pharmacie $origine): array
    {
        return $this->tenantContext->sansFiltre(function () use ($origine): array {
            $destinations = [];
            foreach ($this->affectations->proprietaires($origine) as $proprietaire) {
                foreach ($this->affectations->activesDe($proprietaire) as $affectation) {
                    $pharmacie = $affectation->getPharmacie();
                    if (null !== $pharmacie && $pharmacie->getId() !== $origine->getId() && !$pharmacie->isArchivee() && !$pharmacie->isSuspendue()) {
                        $destinations[(int) $pharmacie->getId()] = $pharmacie;
                    }
                }
            }
            usort($destinations, static fn (Pharmacie $a, Pharmacie $b) => strcoll($a->getNom(), $b->getNom()));

            return $destinations;
        });
    }

    /** Les deux officines ont au moins un propriétaire actif en commun. */
    public function memeProprietaire(Pharmacie $a, Pharmacie $b): bool
    {
        return $this->tenantContext->sansFiltre(function () use ($a, $b): bool {
            $ids = static fn (array $utilisateurs) => array_map(static fn ($u) => $u->getId(), $utilisateurs);

            return [] !== array_intersect($ids($this->affectations->proprietaires($a)), $ids($this->affectations->proprietaires($b)));
        });
    }

    /**
     * Transferts expédiés ou reçus à destination de la pharmacie courante.
     *
     * @return list<TransfertStock>
     */
    public function entrants(): array
    {
        $courante = $this->tenantContext->exigerPharmacie();

        return $this->tenantContext->sansFiltre(fn () => $this->transferts->entrants($courante));
    }

    /**
     * Transfert visible par la pharmacie courante : un transfert sortant, ou un transfert expédié vers elle.
     * Null sinon (les autres sont « introuvables »).
     */
    public function trouver(int $id): ?TransfertStock
    {
        $courante = $this->tenantContext->exigerPharmacie();
        $transfert = $this->tenantContext->sansFiltre(fn () => $this->transferts->complet($id));
        if (null === $transfert) {
            return null;
        }
        if ($transfert->getPharmacieOrigine()->getId() === $courante->getId()) {
            return $transfert;
        }
        $versCourante = $transfert->getPharmacieDestination()->getId() === $courante->getId();

        return $versCourante && ($transfert->estExpedie() || null !== $transfert->getRecuLe()) ? $transfert : null;
    }

    /**
     * @throws StockException
     */
    public function creer(Pharmacie $destination, ?string $note = null): TransfertStock
    {
        $origine = $this->tenantContext->exigerPharmacie();
        $this->exigerMemeProprietaire($origine, $destination);
        if (!\in_array($destination->getId(), array_map(static fn (Pharmacie $p) => $p->getId(), $this->destinations($origine)), true)) {
            throw new StockException(\sprintf('%s ne peut pas recevoir de transfert (officine suspendue ou archivée).', $destination->getNom()));
        }
        $note = null === $note || '' === trim($note) ? null : mb_substr(trim($note), 0, 255);

        return $this->stock->transaction(function () use ($origine, $destination, $note): TransfertStock {
            $transfert = new TransfertStock(
                $this->numeroteur->suivant(self::PREFIXE, $origine),
                $destination,
                $this->tenantContext->getUtilisateur(),
                $this->horloge->now(),
                $note,
            );
            $this->em->persist($transfert);
            $this->em->flush();

            return $transfert;
        });
    }

    /**
     * Ajoute un produit (ou augmente sa quantité). Le stock disponible est vérifié tout de suite.
     *
     * @throws StockException
     */
    public function ajouter(TransfertStock $transfert, Produit $produit, int $quantite): LigneTransfert
    {
        $this->exigerEnPreparation($transfert);
        if ($quantite <= 0) {
            throw new StockException('La quantité doit être supérieure à zéro.');
        }
        if (!$produit->isActif()) {
            throw new StockException(\sprintf('Le produit %s est archivé.', $produit->getNomCommercial()));
        }
        $ligne = $transfert->ligneDe($produit);
        $total = $quantite + ($ligne?->getQuantite() ?? 0);
        $this->stock->verifierDisponible($produit, $total);
        if (null === $ligne) {
            $ligne = $transfert->ajouterLigne($produit, $total);
            $this->em->persist($ligne);
        } else {
            $ligne->setQuantite($total);
        }
        $this->em->flush();

        return $ligne;
    }

    /**
     * Met à jour les quantités (indexées par id de ligne) ; une quantité à zéro retire le produit.
     *
     * @param array<mixed> $quantites
     *
     * @throws StockException
     */
    public function modifierQuantites(TransfertStock $transfert, array $quantites): void
    {
        $this->exigerEnPreparation($transfert);
        foreach ($transfert->getLignes()->toArray() as $ligne) {
            $saisie = $quantites[(int) $ligne->getId()] ?? null;
            if (null === $saisie || '' === $saisie) {
                continue;
            }
            $quantite = \is_scalar($saisie) && ctype_digit(trim((string) $saisie)) ? (int) $saisie : -1;
            if ($quantite < 0) {
                throw new StockException(\sprintf('Quantité invalide pour %s.', $ligne->getProduit()->getNomCommercial()));
            }
            if (0 === $quantite) {
                $transfert->retirerLigne($ligne);
                continue;
            }
            $this->stock->verifierDisponible($ligne->getProduit(), $quantite);
            $ligne->setQuantite($quantite);
        }
        $this->em->flush();
    }

    /**
     * @throws StockException
     */
    public function retirer(TransfertStock $transfert, LigneTransfert $ligne): void
    {
        $this->exigerEnPreparation($transfert);
        if ($ligne->getTransfert() !== $transfert) {
            throw new StockException('Ce produit ne fait pas partie du transfert.');
        }
        $transfert->retirerLigne($ligne);
        $this->em->flush();
    }

    /**
     * Expédie le transfert : chaque produit sort en FEFO (les lots périmés ne partent jamais), en une seule
     * transaction. Les responsables de l'officine destinataire sont prévenus.
     *
     * @throws StockException
     */
    public function expedier(TransfertStock $transfert): void
    {
        $this->exigerEnPreparation($transfert);
        if ($transfert->getLignes()->isEmpty()) {
            throw new StockException('Ajoutez au moins un produit avant d\'expédier le transfert.');
        }
        $origine = $transfert->getPharmacieOrigine();
        $destination = $transfert->getPharmacieDestination();
        $this->exigerMemeProprietaire($origine, $destination);
        if ($destination->isArchivee() || $destination->isSuspendue()) {
            throw new StockException(\sprintf('%s ne peut pas recevoir de transfert (officine suspendue ou archivée).', $destination->getNom()));
        }

        $this->stock->transaction(function () use ($transfert, $origine, $destination): void {
            foreach ($transfert->getLignes() as $ligne) {
                foreach ($this->stock->prelever($ligne->getProduit(), $ligne->getQuantite(), TypeMouvement::Transfert, $transfert->getNumero()) as $prelevement) {
                    $this->em->persist($ligne->ajouterLot($prelevement->lot, $prelevement->quantite));
                }
            }
            $transfert->marquerExpedie($this->tenantContext->getUtilisateur(), $this->horloge->now());
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::TRANSFERT_EXPEDIE, $origine, $transfert, null, [
                'numero' => $transfert->getNumero(),
                'destination' => $destination->getNom(),
                'produits' => $this->resume($transfert),
                'valeur' => $transfert->getValeur(),
            ]);
            $this->em->flush();
            $this->notifications->transfertAReceptionner($transfert);
        });
    }

    /**
     * Annule un transfert pas encore expédié (aucun stock n'a bougé).
     *
     * @throws StockException
     */
    public function annuler(TransfertStock $transfert, string $motif): void
    {
        if (!$transfert->estEnPreparation()) {
            throw new StockException(\sprintf('Le transfert %s est %s : il ne peut plus être annulé.', $transfert->getNumero(), mb_strtolower($transfert->getStatut()->libelle())));
        }
        if ('' === trim($motif)) {
            throw new StockException('Le motif de l\'annulation est obligatoire.');
        }
        $motif = mb_substr(trim($motif), 0, 255);
        $transfert->annuler($this->tenantContext->getUtilisateur(), $this->horloge->now(), $motif);
        $this->audit->journaliser(AuditLogger::TRANSFERT_ANNULE, $transfert->getPharmacieOrigine(), $transfert, null, [
            'numero' => $transfert->getNumero(),
            'destination' => $transfert->getPharmacieDestination()->getNom(),
            'motif' => $motif,
        ]);
        $this->em->flush();
    }

    /**
     * Confirme la réception par l'officine destinataire (pharmacie courante) : chaque lot expédié entre chez elle,
     * sur le produit correspondant de son catalogue (créé s'il n'existe pas encore).
     *
     * @return list<string> produits ajoutés au catalogue de l'officine
     *
     * @throws StockException
     */
    public function receptionner(TransfertStock $transfert): array
    {
        $destination = $this->tenantContext->exigerPharmacie();
        if ($transfert->getPharmacieDestination()->getId() !== $destination->getId()) {
            throw new StockException('Seule l\'officine destinataire confirme la réception du transfert.');
        }
        if (!$transfert->estExpedie()) {
            throw new StockException(\sprintf('Le transfert %s est %s : il n\'y a rien à réceptionner.', $transfert->getNumero(), mb_strtolower($transfert->getStatut()->libelle())));
        }
        $this->exigerMemeProprietaire($transfert->getPharmacieOrigine(), $destination);

        return $this->stock->transaction(function () use ($transfert, $destination): array {
            $crees = [];
            $resume = [];
            foreach ($transfert->getLignes() as $ligne) {
                $produit = $this->correspondance($ligne->getProduit(), $crees);
                foreach ($ligne->getLots() as $lot) {
                    $this->stock->entrerParTransfert($produit, $lot->getNumero(), $lot->getDatePeremption(), $lot->getQuantite(), $lot->getPrixAchat(), $transfert->getNumero());
                }
                $resume[] = ['produit' => $produit->getNomCommercial(), 'quantite' => $ligne->getQuantite()];
            }
            $transfert->marquerRecu($this->tenantContext->getUtilisateur(), $this->horloge->now());
            $this->notifications->transfertRecu($transfert);
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::TRANSFERT_RECU, $destination, $transfert, null, [
                'numero' => $transfert->getNumero(),
                'origine' => $transfert->getPharmacieOrigine()->getNom(),
                'produits' => $resume,
                'valeur' => $transfert->getValeur(),
                'produits_crees' => $crees,
            ]);
            $this->em->flush();

            return $crees;
        });
    }

    /**
     * Produit de la pharmacie courante qui correspond à celui de l'officine d'origine : même nom commercial et même
     * dosage (comme l'import). S'il n'existe pas, il est créé avec la fiche de l'origine, sans catégorie ni étagère
     * ni fournisseur (propres à chaque officine).
     *
     * @param list<string> $crees
     */
    private function correspondance(Produit $origine, array &$crees): Produit
    {
        foreach ($this->produits->findBy(['nomCommercial' => $origine->getNomCommercial(), 'actif' => true], ['id' => 'ASC']) as $produit) {
            if (mb_strtolower((string) $produit->getDosage()) === mb_strtolower((string) $origine->getDosage())) {
                return $produit;
            }
        }

        $produit = (new Produit())
            ->setNomCommercial($origine->getNomCommercial())
            ->setDci($origine->getDci())
            ->setForme($origine->getForme())
            ->setDosage($origine->getDosage())
            ->setConditionnement($origine->getConditionnement())
            ->setPrixAchat($origine->getPrixAchat())
            ->setPrixVente($origine->getPrixVente())
            ->setSeuilAlerte($origine->getSeuilAlerte())
            ->setStockMax($origine->getStockMax())
            ->setOrdonnanceObligatoire($origine->isOrdonnanceObligatoire())
            ->setRemboursableAmo($origine->isRemboursableAmo())
            ->setPrixVenteAmo($origine->getPrixVenteAmo())
            ->setTauxTva($origine->getTauxTva());
        $this->em->persist($produit);
        $crees[] = $produit->getNomCommercial();

        return $produit;
    }

    /**
     * @return list<array{produit: string, quantite: int}>
     */
    private function resume(TransfertStock $transfert): array
    {
        return array_values($transfert->getLignes()->map(static fn (LigneTransfert $l) => [
            'produit' => $l->getProduit()->getNomCommercial(),
            'quantite' => $l->getQuantite(),
        ])->toArray());
    }

    private function exigerEnPreparation(TransfertStock $transfert): void
    {
        if (!$transfert->estEnPreparation()) {
            throw new StockException(\sprintf('Le transfert %s est %s : il n\'est plus modifiable.', $transfert->getNumero(), mb_strtolower($transfert->getStatut()->libelle())));
        }
    }

    private function exigerMemeProprietaire(Pharmacie $origine, Pharmacie $destination): void
    {
        if ($origine->getId() === $destination->getId()) {
            throw new StockException('Choisissez une autre officine que la vôtre.');
        }
        if (!$this->memeProprietaire($origine, $destination)) {
            throw new StockException(\sprintf('Transfert refusé : %s et %s n\'appartiennent pas au même pharmacien.', $origine->getNom(), $destination->getNom()));
        }
    }
}
