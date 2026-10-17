<?php

namespace App\Tests\Functional\Amo;

use App\Entity\OrganismeAmo;
use App\Enum\TypeOrganisme;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une pharmacie crée ses propres assurances (AMO ou autres), visibles d'elle seule ; les organismes communs
 * créés par l'éditeur restent proposés à toutes.
 */
final class AssurancesDeLaPharmacieTest extends AmoTestCase
{
    public function testLeProprietaireAjouteUneAssuranceVisibleDeSaSeulePharmacie(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=amo');
        $this->client->submitForm('Ajouter cette assurance', [
            'organisme_pharmacie[nom]' => 'Mutuelle des enseignants',
            'organisme_pharmacie[code]' => 'mutec',
            'organisme_pharmacie[type]' => TypeOrganisme::Assurance->value,
        ]);
        self::assertResponseRedirects('/parametres?onglet=amo');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#liste-assurances-pharmacie', 'MUTEC · Mutuelle des enseignants');

        $mutec = $this->organisme('MUTEC');
        self::assertSame($this->officine->pharmacie->getId(), $mutec->getPharmacie()?->getId());
        self::assertSame(TypeOrganisme::Assurance, $mutec->getType());

        // Proposée pour le taux, la fiche client et la caisse, à côté des organismes communs.
        $crawler = $this->client->request('GET', '/parametres?onglet=amo');
        self::assertCount(1, $crawler->filter('#taux_amo_organisme option:contains("Mutuelle des enseignants")'));
        self::assertCount(1, $crawler->filter('#taux_amo_organisme option:contains("INPS")'));
        $crawler = $this->client->request('GET', '/clients/nouveau');
        self::assertStringContainsString('Mutuelle des enseignants', (string) $crawler->filter('#assurances-client')->attr('data-collection-prototype-value'));

        // Une autre pharmacie ne la voit pas, mais garde les organismes communs.
        $autre = $this->creerOfficine();
        $crawler = $this->connecter($autre->proprietaire)->request('GET', '/parametres?onglet=amo');
        self::assertCount(0, $crawler->filter('#taux_amo_organisme option:contains("Mutuelle des enseignants")'));
        self::assertCount(1, $crawler->filter('#taux_amo_organisme option:contains("INPS")'));
        self::assertSelectorNotExists('#liste-assurances-pharmacie');
        $this->client->request('GET', '/amo/creances?organisme='.$mutec->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Mutuelle des enseignants');

        // Elle peut créer le même code de son côté.
        $this->client->request('GET', '/parametres?onglet=amo');
        $this->client->submitForm('Ajouter cette assurance', [
            'organisme_pharmacie[nom]' => 'Mutuelle des enseignants',
            'organisme_pharmacie[code]' => 'MUTEC',
            'organisme_pharmacie[type]' => TypeOrganisme::Assurance->value,
        ]);
        self::assertResponseRedirects('/parametres?onglet=amo');
    }

    public function testUnCodeDejaUtiliseParUnOrganismeCommunEstRefuse(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=amo');
        $this->client->submitForm('Ajouter cette assurance', [
            'organisme_pharmacie[nom]' => 'Autre INPS',
            'organisme_pharmacie[code]' => 'INPS',
            'organisme_pharmacie[type]' => TypeOrganisme::Amo->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#assurances-pharmacie', 'Ce code est déjà utilisé.');
    }

    public function testLaPharmacieArchiveSonAssuranceMaisPasUnOrganismeCommun(): void
    {
        $this->connecter($this->officine->proprietaire)->request('GET', '/parametres?onglet=amo');
        $this->client->submitForm('Ajouter cette assurance', [
            'organisme_pharmacie[nom]' => 'Mutuelle des enseignants',
            'organisme_pharmacie[code]' => 'MUTEC',
            'organisme_pharmacie[type]' => TypeOrganisme::Assurance->value,
        ]);
        $this->client->followRedirect();
        $this->client->submitForm('Archiver');
        self::assertResponseRedirects('/parametres?onglet=amo');
        self::assertFalse($this->organisme('MUTEC')->isActif());

        $this->client->request('POST', '/parametres/assurances/'.$this->inps->getId().'/archiver', ['_token' => 'x']);
        self::assertTrue($this->client->getResponse()->isClientError(), 'Un organisme commun relève de l\'éditeur.');
        self::assertTrue($this->organisme('INPS')->isActif());
    }

    public function testLeVendeurNePeutPasCreerDAssurance(): void
    {
        $this->connecter($this->officine->vendeur)->request('GET', '/parametres?onglet=amo');
        self::assertResponseStatusCodeSame(403);
    }

    private function organisme(string $code): OrganismeAmo
    {
        $id = $this->officine->pharmacie->getId();

        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($code, $id): OrganismeAmo {
            $organismes = $em->getRepository(OrganismeAmo::class)->findBy(['code' => $code]);
            $organismes = array_values(array_filter($organismes, static fn (OrganismeAmo $o) => null === $o->getPharmacie() || $o->getPharmacie()->getId() === $id));
            self::assertCount(1, $organismes);

            return $organismes[0];
        });
    }
}
