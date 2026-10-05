<?php

namespace App\Amo;

use App\Entity\BordereauAmo;
use App\Entity\CreanceAmo;
use App\Entity\OrganismeAmo;
use App\Entity\ReglementAmo;
use App\Finance\RecetteService;
use App\Repository\CreanceAmoRepository;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Tenant\TenantContext;
use App\Util\Fcfa;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Bordereaux AMO (AM-06) : composition du brouillon, transmission, règlements créance par créance et rejets (AM-08).
 */
class GestionBordereaux
{
    public const MONTANT_MAXIMAL = 1_000_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CreanceAmoRepository $creances,
        private readonly Numeroteur $numeroteur,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
        private readonly RecetteService $recettes,
    ) {
    }

    /**
     * Brouillon regroupant les créances en attente de l'organisme pour les ventes de la période.
     *
     * @throws AmoException
     */
    public function creer(OrganismeAmo $organisme, \DateTimeImmutable $debut, \DateTimeImmutable $fin): BordereauAmo
    {
        if ($fin < $debut) {
            throw new AmoException('La fin de la période doit suivre son début.');
        }

        return $this->em->wrapInTransaction(function () use ($organisme, $debut, $fin): BordereauAmo {
            $disponibles = $this->creances->disponibles($organisme, $debut, $fin);
            if ([] === $disponibles) {
                throw new AmoException(\sprintf('Aucune créance %s en attente pour les ventes du %s au %s.', $organisme->getCode(), $debut->format('d/m/Y'), $fin->format('d/m/Y')));
            }
            $bordereau = new BordereauAmo($organisme, $debut, $fin, $this->tenantContext->getUtilisateur(), $this->horloge->now());
            $this->em->persist($bordereau);
            foreach ($disponibles as $creance) {
                $creance->rattacher($bordereau);
            }
            $this->em->flush();

            return $bordereau;
        });
    }

    /**
     * Ajoute au brouillon les créances de la période arrivées depuis sa création.
     *
     * @return int nombre de créances ajoutées
     *
     * @throws AmoException
     */
    public function actualiser(BordereauAmo $bordereau): int
    {
        $this->exigerBrouillon($bordereau);

        return $this->em->wrapInTransaction(function () use ($bordereau): int {
            $disponibles = $this->creances->disponibles($bordereau->getOrganisme(), $bordereau->getDebut(), $bordereau->getFin());
            foreach ($disponibles as $creance) {
                $creance->rattacher($bordereau);
            }
            $this->em->flush();

            return \count($disponibles);
        });
    }

    /**
     * Retire une créance du brouillon : elle redevient disponible pour un autre bordereau.
     *
     * @throws AmoException
     */
    public function retirer(BordereauAmo $bordereau, CreanceAmo $creance): void
    {
        $this->exigerBrouillon($bordereau);
        if ($creance->getBordereau() !== $bordereau) {
            throw new AmoException('Cette créance n\'est pas sur ce bordereau.');
        }
        $creance->rattacher(null);
        $this->em->flush();
    }

    /**
     * @throws AmoException
     */
    public function supprimer(BordereauAmo $bordereau): void
    {
        $this->exigerBrouillon($bordereau);
        foreach ($bordereau->getCreances()->toArray() as $creance) {
            $creance->rattacher(null);
        }
        $this->em->remove($bordereau);
        $this->em->flush();
    }

    /**
     * Transmission : numéro BRD (RG-02), montant figé, plus aucune modification (RG-11).
     *
     * @throws AmoException
     */
    public function transmettre(BordereauAmo $bordereau): void
    {
        $this->exigerBrouillon($bordereau);
        if ($bordereau->getCreances()->isEmpty()) {
            throw new AmoException('Un bordereau sans créance ne peut pas être transmis.');
        }
        $pharmacie = $this->tenantContext->exigerPharmacie();

        $this->em->wrapInTransaction(function () use ($bordereau, $pharmacie): void {
            $numero = $this->numeroteur->suivant('BRD', $pharmacie);
            $bordereau->transmettre($numero, $this->horloge->now(), $this->tenantContext->getUtilisateur());
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::AMO_BORDEREAU_TRANSMIS, $pharmacie, $bordereau, null, [
                'bordereau' => $numero,
                'organisme' => $bordereau->getOrganisme()->getCode(),
                'periode' => $bordereau->getDebut()->format('d/m/Y').' – '.$bordereau->getFin()->format('d/m/Y'),
                'creances' => $bordereau->getCreances()->count(),
                'montant' => $bordereau->getMontant(),
            ]);
            $this->em->flush();
        });
    }

    /**
     * Règlement reçu, affecté créance par créance ; les créances rejetées perdent leur reste (AM-08).
     *
     * @param array<array-key, mixed> $affectations montant réglé par id de créance
     * @param array<array-key, mixed> $rejets       motif de rejet par id de créance
     *
     * @throws AmoException
     */
    public function enregistrerReglement(BordereauAmo $bordereau, \DateTimeImmutable $date, int $montant, ?string $reference, array $affectations, array $rejets): ?ReglementAmo
    {
        $this->exigerOuvert($bordereau);
        $aujourdhui = $this->horloge->now()->setTime(0, 0);
        if ($date > $aujourdhui) {
            throw new AmoException('La date du règlement ne peut pas être dans le futur.');
        }
        if (null !== $bordereau->getTransmisLe() && $date < $bordereau->getTransmisLe()->setTime(0, 0)) {
            throw new AmoException('La date du règlement précède la transmission du bordereau.');
        }
        if ($montant < 0 || $montant > self::MONTANT_MAXIMAL) {
            throw new AmoException('Montant reçu invalide.');
        }

        $parCreance = [];
        foreach ($bordereau->getCreances() as $creance) {
            $parCreance[(int) $creance->getId()] = $creance;
        }

        $affecte = [];
        foreach ($affectations as $id => $valeur) {
            $valeur = trim(\is_scalar($valeur) ? (string) $valeur : '');
            if ('' === $valeur || '0' === $valeur) {
                continue;
            }
            $creance = $parCreance[(int) $id] ?? throw new AmoException('Créance inconnue sur ce bordereau.');
            $somme = self::entier($valeur);
            if (null === $somme || $somme > $creance->getReste()) {
                throw new AmoException(\sprintf('Vente %s : le montant réglé doit être compris entre 0 et %s.', $creance->getVente()->getNumero(), Fcfa::format($creance->getReste())));
            }
            $affecte[(int) $id] = $somme;
        }

        $motifs = [];
        foreach ($rejets as $id => $motif) {
            $motif = trim(\is_scalar($motif) ? (string) $motif : '');
            if ('' === $motif) {
                continue;
            }
            $creance = $parCreance[(int) $id] ?? throw new AmoException('Créance inconnue sur ce bordereau.');
            if ($creance->getReste() - ($affecte[(int) $id] ?? 0) <= 0) {
                throw new AmoException(\sprintf('Vente %s : la créance est entièrement réglée, il n\'y a rien à rejeter.', $creance->getVente()->getNumero()));
            }
            $motifs[(int) $id] = mb_substr($motif, 0, 255);
        }

        $total = array_sum($affecte);
        if ($total !== $montant) {
            throw new AmoException(\sprintf('Le montant reçu (%s) doit être égal au total affecté aux créances (%s).', Fcfa::format($montant), Fcfa::format($total)));
        }
        if (0 === $montant && [] === $motifs) {
            throw new AmoException('Saisissez un montant réglé ou un motif de rejet.');
        }

        $pharmacie = $this->tenantContext->exigerPharmacie();

        return $this->em->wrapInTransaction(function () use ($bordereau, $date, $montant, $reference, $affecte, $motifs, $parCreance, $pharmacie): ?ReglementAmo {
            $reglement = null;
            if ($montant > 0) {
                $reference = null === $reference || '' === trim($reference) ? null : mb_substr(trim($reference), 0, 80);
                $reglement = new ReglementAmo($bordereau, $date, $montant, $reference, $this->tenantContext->getUtilisateur(), $this->horloge->now());
                foreach ($affecte as $id => $somme) {
                    $reglement->affecter($parCreance[$id], $somme);
                }
                $this->em->persist($reglement);
                // RG-10 : la part AMO réglée devient une recette.
                $this->recettes->enregistrerReglementAmo($reglement);
            }
            foreach ($motifs as $id => $motif) {
                $parCreance[$id]->rejeter($motif, $date);
            }
            $bordereau->actualiserStatut();
            $this->em->flush();

            if (null !== $reglement) {
                $this->audit->journaliser(AuditLogger::AMO_REGLEMENT, $pharmacie, $reglement, null, [
                    'bordereau' => $bordereau->getNumero(),
                    'date' => $date->format('Y-m-d'),
                    'montant' => $montant,
                    'reference' => $reglement->getReference(),
                    'creances' => \count($affecte),
                ]);
            }
            if ([] !== $motifs) {
                $this->journaliserRejets($bordereau, array_map(static fn (int $id) => $parCreance[$id], array_keys($motifs)), $motifs);
            }
            $this->em->flush();

            return $reglement;
        });
    }

    /**
     * Rejet de tout ce qui reste dû sur le bordereau.
     *
     * @throws AmoException
     */
    public function rejeter(BordereauAmo $bordereau, string $motif): void
    {
        $this->exigerOuvert($bordereau);
        $motif = trim($motif);
        if ('' === $motif) {
            throw new AmoException('Le motif du rejet est obligatoire.');
        }
        $this->em->wrapInTransaction(function () use ($bordereau, $motif): void {
            $rejetees = [];
            $motifs = [];
            foreach ($bordereau->getCreances() as $creance) {
                if ($creance->getReste() > 0) {
                    $creance->rejeter($motif, $this->horloge->now()->setTime(0, 0));
                    $rejetees[] = $creance;
                    $motifs[(int) $creance->getId()] = $motif;
                }
            }
            $bordereau->actualiserStatut();
            $this->em->flush();
            $this->journaliserRejets($bordereau, $rejetees, $motifs);
            $this->em->flush();
        });
    }

    /**
     * @param list<CreanceAmo>   $creances
     * @param array<int, string> $motifs
     */
    private function journaliserRejets(BordereauAmo $bordereau, array $creances, array $motifs): void
    {
        $this->audit->journaliser(AuditLogger::AMO_REJET, $bordereau->getPharmacie(), $bordereau, null, [
            'bordereau' => $bordereau->getNumero(),
            'montant' => array_sum(array_map(static fn (CreanceAmo $c) => $c->getMontantRejete(), $creances)),
            'ventes' => array_map(static fn (CreanceAmo $c) => $c->getVente()->getNumero().' : '.$motifs[(int) $c->getId()], $creances),
        ]);
    }

    private function exigerBrouillon(BordereauAmo $bordereau): void
    {
        if (!$bordereau->estBrouillon()) {
            throw new AmoException(\sprintf('Le bordereau %s est transmis : il n\'est plus modifiable.', $bordereau->getLibelle()));
        }
    }

    private function exigerOuvert(BordereauAmo $bordereau): void
    {
        if ($bordereau->estBrouillon()) {
            throw new AmoException('Transmettez le bordereau avant d\'enregistrer un règlement.');
        }
        if ($bordereau->estSolde()) {
            throw new AmoException(\sprintf('Le bordereau %s est soldé : plus rien n\'est attendu.', $bordereau->getNumero()));
        }
    }

    private static function entier(string $valeur): ?int
    {
        $valeur = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $valeur);
        if (1 !== preg_match('/^\d{1,10}$/', $valeur)) {
            return null;
        }

        return (int) $valeur;
    }
}
