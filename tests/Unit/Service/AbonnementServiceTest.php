<?php

namespace App\Tests\Unit\Service;

use App\Entity\Offre;
use App\Entity\Pharmacie;
use App\Enum\StatutAbonnement;
use App\Service\AbonnementService;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Cycle de vie d'un compte (§ 3.2) et prolongation (RG-14).
 */
final class AbonnementServiceTest extends TestCase
{
    private const AUJOURDHUI = '2026-10-03';

    private AbonnementService $service;

    protected function setUp(): void
    {
        $this->service = new AbonnementService(
            $this->createStub(EntityManagerInterface::class),
            new MockClock(self::AUJOURDHUI.' 10:30:00'),
            $this->createStub(Numeroteur::class),
            $this->createStub(AuditLogger::class),
        );
    }

    /**
     * @return iterable<string, array{?string, ?string, StatutAbonnement, ?int}>
     */
    public static function etats(): iterable
    {
        // [fin d'abonnement, fin d'essai, statut attendu, jours restants]
        yield 'essai en cours' => [null, '2026-10-20', StatutAbonnement::Essai, 17];
        yield 'dernier jour d\'essai' => [null, '2026-10-03', StatutAbonnement::Essai, 0];
        yield 'essai terminé depuis 3 jours : grâce' => [null, '2026-09-30', StatutAbonnement::Grace, -3];
        yield 'actif' => ['2027-03-01', null, StatutAbonnement::Actif, 149];
        yield 'J-31 : encore actif' => ['2026-11-03', null, StatutAbonnement::Actif, 31];
        yield 'J-30 : alerte' => ['2026-11-02', null, StatutAbonnement::Alerte, 30];
        yield 'jour de l\'échéance : alerte' => ['2026-10-03', null, StatutAbonnement::Alerte, 0];
        yield 'J+1 : grâce' => ['2026-10-02', null, StatutAbonnement::Grace, -1];
        yield 'J+7 : dernier jour de grâce' => ['2026-09-26', null, StatutAbonnement::Grace, -7];
        yield 'J+8 : expiré (R-02)' => ['2026-09-25', null, StatutAbonnement::Expire, -8];
        yield 'ni essai ni paiement' => [null, null, StatutAbonnement::Expire, null];
        yield 'un paiement prime sur l\'essai' => ['2027-10-03', '2026-10-20', StatutAbonnement::Actif, 365];
    }

    #[DataProvider('etats')]
    public function testEtat(?string $finAbonnement, ?string $finEssai, StatutAbonnement $attendu, ?int $jours): void
    {
        $pharmacie = $this->pharmacie($finAbonnement, $finEssai);

        $etat = $this->service->etat($pharmacie);

        self::assertSame($attendu, $etat->statut);
        self::assertSame($jours, $etat->joursRestants);
    }

    public function testSuspensionEtArchivagePriment(): void
    {
        $pharmacie = $this->pharmacie('2027-03-01', null)->suspendre('Impayé');
        self::assertSame(StatutAbonnement::Suspendu, $this->service->etat($pharmacie)->statut);

        $pharmacie->archiver(new \DateTimeImmutable());
        self::assertSame(StatutAbonnement::Archive, $this->service->etat($pharmacie)->statut);

        $pharmacie->reactiver();
        self::assertSame(StatutAbonnement::Actif, $this->service->etat($pharmacie)->statut);
    }

    public function testDroitsSelonLeStatut(): void
    {
        self::assertTrue(StatutAbonnement::Grace->permetEcriture(), 'La caisse fonctionne encore pendant la grâce.');
        self::assertFalse(StatutAbonnement::Expire->permetEcriture(), 'Expiré : lecture seule.');
        self::assertTrue(StatutAbonnement::Expire->permetAcces(), 'Expiré : consultation et export possibles.');
        self::assertFalse(StatutAbonnement::Suspendu->permetAcces());
        self::assertFalse(StatutAbonnement::Archive->permetAcces());
    }

    public function testRg14ProlongationDepuisLaFinEnCoursSiEncoreActif(): void
    {
        [$debut, $fin] = $this->service->prochainePeriode($this->pharmacie('2026-12-31', null));

        self::assertSame('2027-01-01', $debut->format('Y-m-d'));
        self::assertSame('2027-12-31', $fin->format('Y-m-d'));
    }

    public function testRg14ProlongationDepuisAujourdhuiSiEchu(): void
    {
        [$debut, $fin] = $this->service->prochainePeriode($this->pharmacie('2026-09-01', null));

        self::assertSame(self::AUJOURDHUI, $debut->format('Y-m-d'));
        self::assertSame('2027-10-03', $fin->format('Y-m-d'));
    }

    public function testRg14LeJourMemeDeLEcheanceOnProlongeLaFinEnCours(): void
    {
        [, $fin] = $this->service->prochainePeriode($this->pharmacie(self::AUJOURDHUI, null));

        self::assertSame('2027-10-03', $fin->format('Y-m-d'));
    }

    public function testRg14LEssaiNEstPasProlonge(): void
    {
        [$debut, $fin] = $this->service->prochainePeriode($this->pharmacie(null, '2026-10-25'));

        self::assertSame(self::AUJOURDHUI, $debut->format('Y-m-d'));
        self::assertSame('2027-10-03', $fin->format('Y-m-d'));
    }

    /**
     * @return iterable<array{string, int, string}>
     */
    public static function ajoutsDeMois(): iterable
    {
        yield ['2026-01-31', 1, '2026-02-28'];
        yield ['2027-01-31', 13, '2028-02-29'];
        yield ['2028-02-29', 12, '2029-02-28'];
        yield ['2026-03-15', 12, '2027-03-15'];
        yield ['2026-12-31', 12, '2027-12-31'];
    }

    #[DataProvider('ajoutsDeMois')]
    public function testAjouterMoisNeDebordePasSurLeMoisSuivant(string $date, int $mois, string $attendu): void
    {
        self::assertSame($attendu, AbonnementService::ajouterMois(new \DateTimeImmutable($date), $mois)->format('Y-m-d'));
    }

    private function pharmacie(?string $finAbonnement, ?string $finEssai): Pharmacie
    {
        return (new Pharmacie(new Offre('standard', 'Standard', 8, 1)))
            ->setFinAbonnement(null !== $finAbonnement ? new \DateTimeImmutable($finAbonnement) : null)
            ->setFinEssai(null !== $finEssai ? new \DateTimeImmutable($finEssai) : null);
    }
}
