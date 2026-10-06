<?php

namespace App\Tests\Unit\Reporting;

use App\Reporting\Periode;
use PHPUnit\Framework\TestCase;

/**
 * Périodes des rapports et période précédente de comparaison (RA-01).
 */
final class PeriodeTest extends TestCase
{
    private const JEUDI = '2026-10-08';

    public function testLaSemaineVaDuLundiAuDimanche(): void
    {
        $semaine = Periode::pour(Periode::SEMAINE, new \DateTimeImmutable(self::JEUDI));
        self::assertSame(['2026-10-05', '2026-10-11'], [$semaine->debut->format('Y-m-d'), $semaine->fin->format('Y-m-d')]);
        self::assertSame(['2026-09-28', '2026-10-04'], [$semaine->precedente()->debut->format('Y-m-d'), $semaine->precedente()->fin->format('Y-m-d')]);
        self::assertSame('Semaine du 05/10 au 11/10/2026', $semaine->libelle());
    }

    public function testMoisAnneeEtJour(): void
    {
        $mars = Periode::pour(Periode::MOIS, new \DateTimeImmutable('2026-03-31'));
        self::assertSame(['2026-03-01', '2026-03-31', 31], [$mars->debut->format('Y-m-d'), $mars->fin->format('Y-m-d'), $mars->nombreJours()]);
        // Le mois précédent de mars est février entier, sans débordement.
        self::assertSame(['2026-02-01', '2026-02-28'], [$mars->precedente()->debut->format('Y-m-d'), $mars->precedente()->fin->format('Y-m-d')]);
        self::assertSame('Mars 2026', $mars->libelle());
        self::assertSame('2026-04-01', $mars->suivante()->debut->format('Y-m-d'));

        $annee = Periode::pour(Periode::ANNEE, new \DateTimeImmutable(self::JEUDI));
        self::assertSame(['2025-01-01', '2025-12-31'], [$annee->precedente()->debut->format('Y-m-d'), $annee->precedente()->fin->format('Y-m-d')]);

        $jour = Periode::pour(Periode::JOUR, new \DateTimeImmutable(self::JEUDI.' 15:30'));
        self::assertSame('Jeudi 8 octobre 2026', $jour->libelle());
        self::assertSame('2026-10-09 00:00', $jour->finInstant()->format('Y-m-d H:i'));
    }

    public function testPlageLibreEtPeriodePrecedenteDeMemeDuree(): void
    {
        $plage = Periode::depuisRequete(Periode::LIBRE, null, '2026-10-10', '2026-10-01', new \DateTimeImmutable(self::JEUDI));
        self::assertSame(['2026-10-01', '2026-10-10', 10], [$plage->debut->format('Y-m-d'), $plage->fin->format('Y-m-d'), $plage->nombreJours()]);
        self::assertSame(['2026-09-21', '2026-09-30'], [$plage->precedente()->debut->format('Y-m-d'), $plage->precedente()->fin->format('Y-m-d')]);
        self::assertSame(['periode' => 'libre', 'du' => '2026-10-01', 'au' => '2026-10-10'], $plage->parametres());
    }

    public function testUneSaisieInvalideDonneLeMoisOuLeJourEnCours(): void
    {
        foreach ([['inconnu', null], [Periode::LIBRE, null], [null, null]] as [$type, $date]) {
            $periode = Periode::depuisRequete($type, $date, null, '2026-10-02', new \DateTimeImmutable(self::JEUDI));
            self::assertSame([Periode::MOIS, '2026-10-01'], [$periode->type, $periode->debut->format('Y-m-d')]);
        }
        $jour = Periode::depuisRequete(Periode::JOUR, '2026-02-30', null, null, new \DateTimeImmutable(self::JEUDI));
        self::assertSame([Periode::JOUR, self::JEUDI], [$jour->type, $jour->debut->format('Y-m-d')]);
    }
}
