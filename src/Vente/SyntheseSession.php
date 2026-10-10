<?php

namespace App\Vente;

use App\Entity\SessionCaisse;
use App\Entity\Vente;
use App\Enum\ModePaiement;

/**
 * Chiffres d'une session de caisse (clôture et rapport Z, FI-06, FI-07).
 *
 * Les ventes annulées restent comptées dans les encaissements : leur remboursement est un décaissement.
 */
final class SyntheseSession
{
    /** @var array<string, int> encaissements par mode (valeur de {@see ModePaiement}) */
    public array $encaissements = [];
    /** @var array<string, int> remboursements des ventes annulées, par mode */
    public array $decaissements = [];
    public int $nombreVentes = 0;
    public int $nombreAnnulations = 0;
    /** Total net des ventes non annulées (part AMO comprise). */
    public int $chiffreAffaires = 0;
    public int $remises = 0;
    public int $partAmo = 0;
    public int $montantAnnule = 0;

    /**
     * @param list<Vente> $ventes ventes encaissées dans la session
     */
    public function __construct(public readonly SessionCaisse $session, array $ventes)
    {
        foreach (ModePaiement::cases() as $mode) {
            $this->encaissements[$mode->value] = 0;
            $this->decaissements[$mode->value] = 0;
        }
        foreach ($ventes as $vente) {
            foreach ($vente->getPaiements() as $paiement) {
                $this->encaissements[$paiement->getMode()->value] += $paiement->getMontant();
                if (!$vente->estValidee()) {
                    $this->decaissements[$paiement->getMode()->value] += $paiement->getMontant();
                }
            }
            if ($vente->estValidee()) {
                ++$this->nombreVentes;
                $this->chiffreAffaires += $vente->getTotalNet();
                $this->remises += $vente->getRemise();
                $this->partAmo += $vente->getPartAmo();
            } else {
                ++$this->nombreAnnulations;
                $this->montantAnnule += $vente->getTotalNet();
            }
        }
    }

    /**
     * Modes à afficher : ceux proposés à la caisse, plus un ancien mode (carte) s'il a servi dans la session.
     *
     * @return list<ModePaiement>
     */
    public function modes(): array
    {
        return array_values(array_filter(ModePaiement::cases(), fn (ModePaiement $m) => \in_array($m, ModePaiement::proposes(), true)
            || 0 !== $this->encaissements[$m->value] || 0 !== $this->decaissements[$m->value]));
    }

    public function net(ModePaiement $mode): int
    {
        return $this->encaissements[$mode->value] - $this->decaissements[$mode->value];
    }

    /** RG-13 : fond de caisse + encaissements espèces − décaissements espèces. */
    public function especesAttendues(): int
    {
        return $this->session->getFondCaisse() + $this->net(ModePaiement::Especes);
    }

    public function totalEncaisse(): int
    {
        return array_sum($this->encaissements) - array_sum($this->decaissements);
    }
}
