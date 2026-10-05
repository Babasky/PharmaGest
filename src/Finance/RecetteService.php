<?php

namespace App\Finance;

use App\Entity\Recette;
use App\Entity\ReglementAmo;
use App\Entity\Vente;
use App\Enum\ModeReglement;
use App\Enum\OrigineRecette;
use App\Repository\RecetteRepository;
use App\Service\AuditLogger;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Recettes (FI-04, FI-05, RG-10) : générées automatiquement par les ventes (montant encaissé, part assuré
 * seulement pour l'AMO) et par les règlements AMO, contre-passées à l'annulation, ou saisies à la main.
 *
 * Les méthodes automatiques ne font que persister : elles s'exécutent dans la transaction du document
 * d'origine, qui se charge du flush.
 */
class RecetteService
{
    public const MONTANT_MAXIMAL = 100_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecetteRepository $recettes,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $horloge,
    ) {
    }

    /** Une recette par mode de paiement de la vente, datée du jour de l'encaissement. */
    public function enregistrerVente(Vente $vente): void
    {
        $le = $vente->getValideeLe() ?? $this->horloge->now();
        foreach ($vente->getPaiements() as $paiement) {
            if ($paiement->getMontant() > 0) {
                $this->em->persist(Recette::pourVente($vente, ModeReglement::depuisPaiement($paiement->getMode()), $paiement->getMontant(), $le));
            }
        }
    }

    /** Annulation d'une vente (RG-12) : chacune de ses recettes est contre-passée. */
    public function contrePasserVente(Vente $vente): void
    {
        $libelle = \sprintf('Annulation de la vente %s', $vente->getNumero());
        foreach ($this->recettes->deLaVente($vente) as $recette) {
            if (OrigineRecette::Vente === $recette->getOrigine() && !$recette->estAnnulee()) {
                $this->em->persist($recette->contrePasser($libelle, $this->tenantContext->getUtilisateur(), $this->horloge->now()));
            }
        }
    }

    /** La part AMO devient une recette au règlement du bordereau (RG-10, H3). */
    public function enregistrerReglementAmo(ReglementAmo $reglement): void
    {
        $this->em->persist(Recette::pourReglementAmo($reglement, $this->tenantContext->getUtilisateur(), $this->horloge->now()));
    }

    /**
     * Recette manuelle : autre produit que les ventes et l'AMO (FI-05).
     *
     * @throws FinanceException
     */
    public function enregistrerManuelle(\DateTimeImmutable $date, string $libelle, int $montant, ModeReglement $mode): Recette
    {
        $libelle = trim($libelle);
        if ('' === $libelle) {
            throw new FinanceException('Le libellé de la recette est obligatoire.');
        }
        if ($montant <= 0 || $montant > self::MONTANT_MAXIMAL) {
            throw new FinanceException('Le montant de la recette doit être positif.');
        }
        if ($date > $this->horloge->now()->setTime(0, 0)) {
            throw new FinanceException('La date de la recette ne peut pas être dans le futur.');
        }
        $recette = new Recette($date->setTime(0, 0), OrigineRecette::Manuelle, mb_substr($libelle, 0, 150), $montant, $mode, $this->tenantContext->getUtilisateur(), $this->horloge->now());
        $this->em->persist($recette);
        $this->em->flush();

        return $recette;
    }

    /**
     * Annule une recette manuelle par contre-passation, avec un motif journalisé.
     *
     * @throws FinanceException
     */
    public function annulerManuelle(Recette $recette, string $motif): void
    {
        if (OrigineRecette::Manuelle !== $recette->getOrigine()) {
            throw new FinanceException('Seule une recette manuelle s\'annule ici : une vente s\'annule depuis sa fiche.');
        }
        if ($recette->estAnnulee()) {
            throw new FinanceException('Cette recette est déjà annulée.');
        }
        $motif = trim($motif);
        if ('' === $motif) {
            throw new FinanceException('Le motif de l\'annulation est obligatoire.');
        }
        $this->em->wrapInTransaction(function () use ($recette, $motif): void {
            $contre = $recette->contrePasser('Annulation : '.$recette->getLibelle(), $this->tenantContext->getUtilisateur(), $this->horloge->now());
            $this->em->persist($contre);
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::RECETTE_ANNULEE, $recette->getPharmacie(), $recette, [
                'date' => $recette->getDate()->format('Y-m-d'),
                'libelle' => $recette->getLibelle(),
                'montant' => $recette->getMontant(),
                'mode' => $recette->getMode()->value,
            ], ['motif' => mb_substr($motif, 0, 255)]);
            $this->em->flush();
        });
    }
}
