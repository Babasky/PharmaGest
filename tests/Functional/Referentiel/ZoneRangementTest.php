<?php

namespace App\Tests\Functional\Referentiel;

use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Zones de rangement propres à chaque pharmacie (RF-02) : les trois zones d'origine, puis celles que le pharmacien
 * crée, renomme et archive ; elles sont proposées sur les étagères.
 */
final class ZoneRangementTest extends AppWebTestCase
{
    private Officine $officine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officine = $this->creerOfficine();
    }

    public function testLePharmacienCreeRenommeEtArchiveSesZones(): void
    {
        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/etageres/zones');
        self::assertResponseIsSuccessful();
        self::assertSame(['Comptoir', 'Réfrigérateur', 'Réserve'], $this->noms($crawler), 'Les zones d\'origine sont créées d\'office.');

        // Création, nom obligatoire et unique.
        $this->client->submitForm('Créer la zone', ['zone_rangement[nom]' => '  Vitrine ']);
        self::assertResponseRedirects('/etageres/zones');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Zone « Vitrine » créée');
        self::assertSame(['Comptoir', 'Réfrigérateur', 'Réserve', 'Vitrine'], $this->noms($crawler));
        $this->client->submitForm('Créer la zone', ['zone_rangement[nom]' => 'vitrine']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Une zone porte déjà ce nom.');
        $this->client->submitForm('Créer la zone', ['zone_rangement[nom]' => '']);
        self::assertResponseStatusCodeSame(422);

        // La nouvelle zone est proposée sur les étagères.
        $crawler = $this->client->request('GET', '/etageres');
        $this->client->submitForm('Créer', ['etagere[code]' => 'V1', 'etagere[libelle]' => 'Cosmétiques', 'etagere[zone]' => $this->option($crawler, 'Vitrine')]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Vitrine');
        $crawler = $this->client->request('GET', '/etageres/zones');
        self::assertSame('1', trim($crawler->filter('#zones tr')->reduce(static fn (Crawler $l) => str_contains($l->text(), 'Vitrine'))->filter('td')->eq(1)->text()));

        // Renommée : les étagères suivent.
        $this->client->click($crawler->selectLink('Renommer Vitrine')->link());
        $this->client->submitForm('Enregistrer', ['zone_rangement[nom]' => 'Vitrine d\'entrée']);
        self::assertResponseRedirects('/etageres/zones');
        $this->client->request('GET', '/etageres');
        self::assertSelectorTextContains('main', 'Vitrine d\'entrée');

        // Archivée : plus proposée pour une nouvelle étagère, gardée par les siennes.
        $crawler = $this->client->request('GET', '/etageres/zones');
        $this->client->submit($crawler->selectButton('Archiver Réserve')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#zones', 'Archivée');
        $crawler = $this->client->request('GET', '/etageres');
        self::assertCount(0, $crawler->filter('#etagere_zone option')->reduce(static fn (Crawler $o) => 'Réserve' === $o->text()));
    }

    public function testReserveAuxResponsables(): void
    {
        $this->connecter($this->officine->vendeur)->request('GET', '/etageres/zones');
        self::assertResponseStatusCodeSame(403);

        $this->connecter($this->officine->adjoint)->request('GET', '/etageres');
        self::assertSelectorExists('a[href="/etageres/zones"]');
    }

    /**
     * @return list<string>
     */
    private function noms(Crawler $crawler): array
    {
        return $crawler->filter('#zones tbody td:first-child')->each(static fn (Crawler $c) => trim(str_replace('Archivée', '', $c->text())));
    }

    private function option(Crawler $crawler, string $libelle): string
    {
        return (string) $crawler->filter('#etagere_zone option')->reduce(static fn (Crawler $o) => $libelle === $o->text())->attr('value');
    }
}
