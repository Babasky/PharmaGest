<?php

namespace App\Amo;

use App\Entity\OrganismeAmo;
use App\Repository\CreanceAmoRepository;
use App\Repository\OrganismeAmoRepository;
use Psr\Clock\ClockInterface;

/**
 * Tableau de suivi de la créance AMO (AM-10) : encours par organisme, ancienneté depuis la vente, taux de rejet.
 *
 * Taux de rejet = montant rejeté / montant des créances transmises (sorties de l'attente), en montant.
 */
class SuiviAmo
{
    /** Tranches d'ancienneté en jours : libellé => borne haute incluse (null = sans limite). */
    public const TRANCHES = ['0-30 j' => 30, '31-60 j' => 60, '61-90 j' => 90, '+ 90 j' => null];

    public function __construct(
        private readonly CreanceAmoRepository $creances,
        private readonly OrganismeAmoRepository $organismes,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * @return array{lignes: list<array{organisme: OrganismeAmo, nombre: int, encours: int, tranches: array<string, int>, transmis: int, rejete: int, taux_rejet: ?float}>, total: array{nombre: int, encours: int, tranches: array<string, int>, transmis: int, rejete: int, taux_rejet: ?float}}
     */
    public function tableau(): array
    {
        $aujourdhui = $this->horloge->now()->setTime(0, 0);
        $vide = array_fill_keys(array_keys(self::TRANCHES), 0);
        $lignes = [];

        foreach ($this->creances->ouvertes() as $creance) {
            $organisme = $creance->getOrganisme();
            $cle = (int) $organisme->getId();
            $lignes[$cle] ??= ['organisme' => $organisme, 'nombre' => 0, 'encours' => 0, 'tranches' => $vide, 'transmis' => 0, 'rejete' => 0, 'taux_rejet' => null];
            $reste = $creance->getReste();
            ++$lignes[$cle]['nombre'];
            $lignes[$cle]['encours'] += $reste;
            $lignes[$cle]['tranches'][self::tranche((int) $creance->getDateVente()->setTime(0, 0)->diff($aujourdhui)->days)] += $reste;
        }

        $rejets = $this->creances->rejetsParOrganisme();
        foreach ($rejets as $id => $chiffres) {
            if (!isset($lignes[$id])) {
                // Organisme sans encours mais avec un historique de bordereaux.
                $organisme = $this->organismes->find($id);
                if (null === $organisme) {
                    continue;
                }
                $lignes[$id] = ['organisme' => $organisme, 'nombre' => 0, 'encours' => 0, 'tranches' => $vide, 'transmis' => 0, 'rejete' => 0, 'taux_rejet' => null];
            }
            $lignes[$id]['transmis'] = $chiffres['transmis'];
            $lignes[$id]['rejete'] = $chiffres['rejete'];
            $lignes[$id]['taux_rejet'] = self::taux($chiffres['rejete'], $chiffres['transmis']);
        }

        $lignes = array_values($lignes);
        usort($lignes, static fn (array $a, array $b) => $b['encours'] <=> $a['encours'] ?: strcmp($a['organisme']->getCode(), $b['organisme']->getCode()));

        $total = ['nombre' => 0, 'encours' => 0, 'tranches' => $vide, 'transmis' => 0, 'rejete' => 0, 'taux_rejet' => null];
        foreach ($lignes as $ligne) {
            foreach (['nombre', 'encours', 'transmis', 'rejete'] as $champ) {
                $total[$champ] += $ligne[$champ];
            }
            foreach ($ligne['tranches'] as $tranche => $montant) {
                $total['tranches'][$tranche] += $montant;
            }
        }
        $total['taux_rejet'] = self::taux($total['rejete'], $total['transmis']);

        return ['lignes' => $lignes, 'total' => $total];
    }

    public static function tranche(int $jours): string
    {
        foreach (self::TRANCHES as $libelle => $borne) {
            if (null === $borne || $jours <= $borne) {
                return $libelle;
            }
        }

        throw new \LogicException('Tranche introuvable.');
    }

    private static function taux(int $rejete, int $transmis): ?float
    {
        return $transmis > 0 ? round($rejete * 100 / $transmis, 1) : null;
    }
}
