<?php

namespace App\Tests\Functional\Amo;

use App\Entity\Client;
use App\Entity\ClientAssurance;
use App\Tests\Factory\ClientFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Un client peut avoir plusieurs assurances (AMO ou autres) ; la caisse choisit celle de la vente (AM-02).
 */
final class PlusieursAssurancesTest extends AmoTestCase
{
    public function testLaFicheClientAccepteplusieursAssurances(): void
    {
        $crawler = $this->connecter($this->officine->vendeur)->request('GET', '/clients/nouveau');
        self::assertSelectorExists('#assurances-client button:contains("Ajouter une assurance")');
        $valeurs = $crawler->filter('form[name=client]')->form()->getPhpValues();
        $valeurs['client']['nom'] = 'Awa Coulibaly';
        $valeurs['client']['assurances'] = [
            ['organisme' => (string) $this->inps->getId(), 'numeroAssure' => 'inps-77'],
            ['organisme' => '', 'numeroAssure' => ''],
            ['organisme' => (string) $this->cmss->getId(), 'numeroAssure' => 'CMSS-12'],
        ];
        $this->client->request('POST', '/clients/nouveau', $valeurs);
        self::assertResponseRedirects('/clients');

        $client = $this->clientNomme('Awa Coulibaly');
        self::assertSame(['INPS INPS-77', 'CMSS CMSS-12'], $client->getAssurances()->map(static fn (ClientAssurance $a) => $a->libelle())->getValues(), 'La ligne laissée vide est ignorée.');

        // La liste des clients montre toutes ses assurances, et la recherche le trouve par chacun de ses n°.
        $this->client->request('GET', '/clients?q=CMSS-12');
        self::assertSelectorTextContains('tbody', 'Awa Coulibaly');
        self::assertSelectorTextContains('tbody', 'INPS · INPS-77, CMSS · CMSS-12');

        // Retirer une assurance depuis la fiche.
        $crawler = $this->client->request('GET', '/clients/'.$client->getId().'/modifier');
        $valeurs = $crawler->filter('form[name=client]')->form()->getPhpValues();
        unset($valeurs['client']['assurances'][0]);
        $this->client->request('POST', '/clients/'.$client->getId().'/modifier', $valeurs);
        self::assertResponseRedirects('/clients');
        self::assertSame(['CMSS CMSS-12'], $this->clientNomme('Awa Coulibaly')->getAssurances()->map(static fn (ClientAssurance $a) => $a->libelle())->getValues());
    }

    public function testUneMemeAssuranceNeSeSaisitQuUneFois(): void
    {
        $crawler = $this->connecter($this->officine->vendeur)->request('GET', '/clients/nouveau');
        $valeurs = $crawler->filter('form[name=client]')->form()->getPhpValues();
        $valeurs['client']['nom'] = 'Awa Coulibaly';
        $valeurs['client']['assurances'] = [
            ['organisme' => (string) $this->inps->getId(), 'numeroAssure' => 'INPS-77'],
            ['organisme' => (string) $this->inps->getId(), 'numeroAssure' => 'INPS-78'],
            ['organisme' => (string) $this->cmss->getId(), 'numeroAssure' => ''],
        ];
        $this->client->request('POST', '/clients/nouveau', $valeurs);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'L\'assurance INPS est indiquée deux fois.');
        self::assertSelectorTextContains('main', 'Le n° d\'assuré est obligatoire.');
    }

    public function testLaCaisseChoisitLAssuranceDeLaVente(): void
    {
        $client = ClientFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nom' => 'Awa Coulibaly', 'assurances' => [[$this->inps, 'INPS-77'], [$this->cmss, 'CMSS-12']]]);
        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($this->remboursable, 1);
        $this->poster('/caisse/client', ['client' => $client->getId()]);
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d')]);

        // Par défaut, la première assurance de la fiche ; la caisse propose les autres.
        $crawler = $this->client->request('GET', '/caisse');
        self::assertSelectorTextContains('#client', 'INPS INPS-77, CMSS CMSS-12');
        $options = $crawler->filter('#assurance-vente select[name=organisme] option')->each(static fn ($o) => $o->text());
        self::assertCount(2, $options);
        self::assertStringStartsWith('INPS ·', $options[0]);
        self::assertStringEndsWith('n° INPS-77', $options[0]);
        self::assertStringEndsWith('n° CMSS-12', $options[1]);
        self::assertSelectorNotExists('#assurance-vente input[name=numero_assure]');
        self::assertSame(['INPS', 'INPS-77'], [$this->derniereVente()->getOrganismeAmo()?->getCode(), $this->derniereVente()->getNumeroAssure()]);

        // On choisit la CMSS : son n° d'assuré vient de la fiche, un n° saisi à la main est ignoré.
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'organisme' => $this->cmss->getId(), 'numero_assure' => 'AUTRE']);
        $this->client->request('GET', '/caisse');
        self::assertSelectorTextContains('#part-organisme', 'Part CMSS');
        self::assertSame('selected', $this->client->getCrawler()->filter('#assurance-vente option[value="'.$this->cmss->getId().'"]')->attr('selected'));

        $this->encaisser(['especes' => ['remis' => '1000']]);
        self::assertResponseRedirects();
        $vente = $this->derniereVente();
        self::assertSame(['CMSS', 'CMSS-12'], [$vente->getOrganismeAmo()?->getCode(), $vente->getNumeroAssure()]);
        self::assertSame('CMSS', $this->creance((int) $vente->getId())->getOrganisme()->getCode());
    }

    public function testUneAssuranceQueLeClientNAPasEstRemplaceeParSaPremiere(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $this->ajouter($this->remboursable, 1);
        $this->poster('/caisse/vente', ['type' => 'amo', 'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'organisme' => $this->cmss->getId(), 'numero_assure' => 'CMSS-9']);
        // Mariam n'est assurée qu'à l'INPS : la CMSS saisie avant de la choisir laisse place à son assurance.
        $this->poster('/caisse/client', ['client' => $this->assure->getId()]);
        self::assertSame(['INPS', 'INPS-0045871'], [$this->derniereVente()->getOrganismeAmo()?->getCode(), $this->derniereVente()->getNumeroAssure()]);
    }

    private function clientNomme(string $nom): Client
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($nom): Client {
            $client = $em->getRepository(Client::class)->findOneBy(['nom' => $nom]);
            self::assertInstanceOf(Client::class, $client);
            $client->getAssurances()->map(static fn (ClientAssurance $a) => $a->getOrganisme()?->getCode())->toArray();

            return $client;
        });
    }
}
