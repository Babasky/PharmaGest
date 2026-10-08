<?php

namespace App\Tests\Functional\Admin;

use App\Entity\FormeGalenique;
use App\Entity\OrganismeAmo;
use App\Enum\TypeOrganisme;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SA-07 : référentiels communs gérés par le super admin dans l'espace plateforme (EasyAdmin).
 */
final class ReferentielAdminTest extends AppWebTestCase
{
    public function testLesValeursInitialesSontEnPlace(): void
    {
        $this->connecter($this->creerSuperAdmin())->request('GET', '/admin/referentiels/organismes-amo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#main', 'INPS');
        self::assertSelectorTextContains('#main', 'CMSS');
        $this->client->request('GET', '/admin/referentiels/formes-galeniques');
        self::assertSelectorTextContains('#main', 'Comprimé');
        $this->client->request('GET', '/admin/referentiels/categories-depenses');
        self::assertSelectorTextContains('#main', 'Électricité');
    }

    public function testAjoutPuisDesactivationDUneForme(): void
    {
        $officine = $this->creerOfficine();
        $this->connecter($this->creerSuperAdmin());
        $this->creer('/admin/referentiels/formes-galeniques', ['FormeGalenique[nom]' => 'Lyophilisat']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('#main', 'Lyophilisat');

        $this->creer('/admin/referentiels/formes-galeniques', ['FormeGalenique[nom]' => 'Lyophilisat']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#main', 'Cette forme existe déjà');

        $crawler = $this->connecter($officine->adjoint)->request('GET', '/produits/nouveau');
        self::assertCount(1, $crawler->filter('#produit_forme option:contains("Lyophilisat")'));

        // Rien n'est supprimé : on décoche « Proposé aux pharmacies » (RG-15).
        $id = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(FormeGalenique::class)->findOneBy(['nom' => 'Lyophilisat'])?->getId());
        $crawler = $this->connecter($this->creerSuperAdmin())->request('GET', '/admin/referentiels/formes-galeniques/'.$id.'/edit');
        $formulaire = $crawler->selectButton('Sauvegarder les modifications')->form();
        $formulaire->remove('FormeGalenique[actif]'); // case décochée : le champ n'est pas envoyé
        $this->client->submit($formulaire);
        self::assertResponseRedirects();

        $crawler = $this->connecter($officine->adjoint)->request('GET', '/produits/nouveau');
        self::assertCount(0, $crawler->filter('#produit_forme option:contains("Lyophilisat")'), 'Une forme désactivée n\'est plus proposée.');
    }

    public function testUnOrganismeAUnCode(): void
    {
        $this->connecter($this->creerSuperAdmin());
        $this->creer('/admin/referentiels/organismes-amo', ['OrganismeAmo[nom]' => 'Mutuelle test', 'OrganismeAmo[code]' => 'mut']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#main', 'MUT');
        $type = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'MUT'])?->getType());
        self::assertSame(TypeOrganisme::Amo, $type, 'Par défaut, un organisme est un gestionnaire AMO.');
    }

    public function testUneAutreAssuranceQueLAmo(): void
    {
        $officine = $this->creerOfficine();
        $this->connecter($this->creerSuperAdmin());
        $this->creer('/admin/referentiels/organismes-amo', ['OrganismeAmo[nom]' => 'Assurance des ONG', 'OrganismeAmo[code]' => 'aong', 'OrganismeAmo[type]' => 'assurance']);
        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('#main', 'Autre assurance');
        $type = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'AONG'])?->getType());
        self::assertSame(TypeOrganisme::Assurance, $type);

        // Proposée aux pharmacies pour leurs clients et pour le taux, à côté des organismes AMO.
        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/clients/nouveau');
        self::assertCount(1, $crawler->filter('#client_organismeAmo optgroup[label="Autre assurance"] option:contains("Assurance des ONG")'));
        $crawler = $this->client->request('GET', '/parametres?onglet=amo');
        self::assertCount(1, $crawler->filter('#taux_amo_organisme optgroup[label="Autre assurance"] option:contains("Assurance des ONG")'));
        self::assertCount(1, $crawler->filter('#taux_amo_organisme optgroup[label="AMO"] option:contains("INPS")'));
    }

    public function testPasDeSuppression(): void
    {
        $crawler = $this->connecter($this->creerSuperAdmin())->request('GET', '/admin/referentiels/formes-galeniques');

        self::assertCount(0, $crawler->filter('.action-delete'));
        self::assertCount(0, $crawler->filter('.action-batchDelete'));
    }

    public function testReserveAuSuperAdmin(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/admin/referentiels/organismes-amo');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/referentiels/organismes-amo/new');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Soumet le formulaire de création EasyAdmin avec le bouton « Créer » (retour à la liste).
     *
     * @param array<string, string> $valeurs
     */
    private function creer(string $liste, array $valeurs): void
    {
        $crawler = $this->client->request('GET', $liste.'/new');
        $this->client->submit($crawler->filter('button[value="saveAndReturn"]')->form(), $valeurs);
    }
}
