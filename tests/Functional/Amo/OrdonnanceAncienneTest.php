<?php

namespace App\Tests\Functional\Amo;

use App\Entity\Ordonnance;

/**
 * Demande du 23/10/2026 : à la caisse, une ordonnance AMO prescrite depuis au moins une semaine
 * est signalée par un simple avertissement, avec son ancienneté ; la vente reste possible.
 */
final class OrdonnanceAncienneTest extends AmoTestCase
{
    public function testOrdonnanceAmoDeHuitJoursSignaleeSansBloquerLaVente(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($this->remboursable);
        $this->poster('/caisse/client', ['client' => $this->assure->getId()]);
        $prescription = new \DateTimeImmutable('-8 days');
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => $prescription->format('Y-m-d')]);
        $this->client->request('GET', '/caisse');

        self::assertSelectorExists('#ordonnance-ancienne:not(.d-none)');
        self::assertSelectorTextContains('#ordonnance-ancienne', 'cette ordonnance AMO est ancienne de 8 jours (prescrite le '.$prescription->format('d/m/Y').')');

        $this->encaisser(['especes' => ['remis' => '1000']]);
        self::assertResponseRedirects();
        self::assertGreaterThan(0, $this->derniereVente()->getPartAmo(), 'La vente AMO passe malgré l\'avertissement.');
    }

    public function testPasDAvertissementAvantUneSemaineNiHorsAmo(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($this->remboursable);
        $this->poster('/caisse/client', ['client' => $this->assure->getId()]);

        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('-6 days'))->format('Y-m-d')]);
        $this->client->request('GET', '/caisse');
        self::assertSelectorExists('#ordonnance-ancienne.d-none');

        // Ordonnance classique : pas d'alerte, même ancienne.
        $this->poster('/caisse/vente', ['type' => 'ordonnance', 'ordonnance_date' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d')]);
        $this->client->request('GET', '/caisse');
        self::assertSelectorExists('#ordonnance-ancienne.d-none');
        // Le seuil et le jour de présentation servent à l'avertissement affiché dès la saisie de la date.
        self::assertSelectorExists('#ordonnance-ancienne[data-seuil="7"][data-aujourdhui="'.(new \DateTimeImmutable('today'))->format('Y-m-d').'"]');
    }

    public function testAncienneteEnJoursCalendaires(): void
    {
        $ordonnance = (new Ordonnance())->setDate(new \DateTimeImmutable('2026-10-16'));
        self::assertSame(7, $ordonnance->joursDepuisPrescription(new \DateTimeImmutable('2026-10-23 21:50')));
        self::assertSame(6, $ordonnance->joursDepuisPrescription(new \DateTimeImmutable('2026-10-22')));
        self::assertNull((new Ordonnance())->joursDepuisPrescription(new \DateTimeImmutable('2026-10-23')));
    }
}
