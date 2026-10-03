<?php

namespace App\Tests\Integration;

use App\Entity\JournalAudit;
use App\Entity\Offre;
use App\Entity\Utilisateur;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\UtilisateurFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

final class PlateformeServicesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testRg02NumerotationSequentielleParPorteeEtParAnnee(): void
    {
        self::mockTime('2026-06-15 10:00:00');
        $numeroteur = self::getContainer()->get(Numeroteur::class);
        $a = PharmacieFactory::createOne();
        $b = PharmacieFactory::createOne();

        $numeros = $this->em->wrapInTransaction(static fn () => [
            $numeroteur->suivant('V', $a),
            $numeroteur->suivant('V', $a),
            $numeroteur->suivant('V', $b),
            $numeroteur->suivant('CMD', $a),
            $numeroteur->suivant('V', $a),
        ]);
        self::assertSame(['V-2026-000001', 'V-2026-000002', 'V-2026-000001', 'CMD-2026-000001', 'V-2026-000003'], $numeros);

        self::mockTime('2027-01-01 08:00:00');
        self::assertSame('V-2027-000001', $this->em->wrapInTransaction(static fn () => $numeroteur->suivant('V', $a)), 'Le compteur repart à 1 chaque année.');
    }

    public function testLaNumerotationExigeUneTransaction(): void
    {
        $this->expectException(\LogicException::class);
        self::getContainer()->get(Numeroteur::class)->suivant('FAC');
    }

    public function testLeJournalDAuditNeSeModifiePasEtNeSeSupprimePas(): void
    {
        $entree = self::getContainer()->get(AuditLogger::class)->journaliser(AuditLogger::PHARMACIE_CREEE, null);
        $this->em->flush();

        try {
            $this->em->remove($entree);
            $this->em->flush();
            self::fail('La suppression aurait dû être refusée.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('ne se supprime pas', $e->getMessage());
        }

        $this->em->clear();
        self::assertNotNull($this->em->find(JournalAudit::class, $entree->getId()));
    }

    public function testRappelsDEcheanceAJ30J15J7(): void
    {
        foreach ([30, 29, 15, 7, 6] as $jours) {
            $pharmacie = PharmacieFactory::createOne(['finAbonnement' => new \DateTimeImmutable(\sprintf('today +%d days', $jours))]);
            $proprietaire = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_PROPRIETAIRE]);
            AffectationFactory::createOne(['utilisateur' => $proprietaire, 'pharmacie' => $pharmacie]);
        }
        // Un essai qui se termine dans 7 jours est rappelé aussi ; une pharmacie suspendue ne l'est pas.
        $essai = PharmacieFactory::new()->enEssai(7)->create();
        AffectationFactory::createOne(['utilisateur' => UtilisateurFactory::new()->with(['role' => Utilisateur::ROLE_PROPRIETAIRE]), 'pharmacie' => $essai]);
        $suspendue = PharmacieFactory::createOne(['finAbonnement' => new \DateTimeImmutable('today +15 days')]);
        $suspendue->suspendre('Test');
        $this->em->flush();
        AffectationFactory::createOne(['utilisateur' => UtilisateurFactory::new()->with(['role' => Utilisateur::ROLE_PROPRIETAIRE]), 'pharmacie' => $suspendue]);

        $commande = new CommandTester((new Application(self::$kernel))->find('app:abonnements:rappels'));
        $commande->execute([]);

        $commande->assertCommandIsSuccessful();
        self::assertStringContainsString('4 rappel(s) envoyé(s)', $commande->getDisplay());
        self::assertQueuedEmailCount(4);
    }

    public function testLesOffresDuCahierDesChargesSontEnBase(): void
    {
        $offres = [];
        foreach ($this->em->getRepository(Offre::class)->findBy([], ['ordre' => 'ASC']) as $offre) {
            $offres[$offre->getCode()] = [$offre->getMaxUtilisateurs(), $offre->getMaxPharmacies()];
        }

        self::assertSame(['essentiel' => [3, 1], 'standard' => [8, 1], 'premium' => [null, 5]], $offres);
    }
}
