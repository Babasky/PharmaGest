<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Affectation;
use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Service\AuditLogger;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\UtilisateurFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le super admin gère les propriétaires associés depuis la fiche de la pharmacie :
 * il en ajoute, en retire et en réactive, sans jamais laisser la pharmacie sans propriétaire actif.
 */
final class ProprietairesAdminTest extends AppWebTestCase
{
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->creerSuperAdmin();
    }

    public function testAjoutDUnProprietaireDepuisLaFiche(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($this->admin)->request('GET', \sprintf('/admin/pharmacies/%d', $officine->pharmacie->getId()));
        $this->client->submitForm('Ajouter le propriétaire', [
            'associe[nom]' => 'Ibrahim Sangaré',
            'associe[email]' => 'i.sangare@test.ml',
        ]);
        self::assertResponseRedirects(\sprintf('/admin/pharmacies/%d', $officine->pharmacie->getId()));
        self::assertQueuedEmailCount(1);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Ibrahim Sangaré est propriétaire de la pharmacie');
        self::assertSelectorTextContains('#main', 'i.sangare@test.ml · Propriétaire');
        self::assertSame(1, $this->compterAudit(AuditLogger::PROPRIETAIRE_AJOUTE));

        // L'action est visible dans le journal de la plateforme.
        $this->client->request('GET', '/admin/journal');
        self::assertSelectorTextContains('#main', 'Propriétaire associé ajouté');
    }

    public function testErreurDAjoutAfficheeSurLaFiche(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($this->admin)->request('GET', \sprintf('/admin/pharmacies/%d', $officine->pharmacie->getId()));
        $this->client->submitForm('Ajouter le propriétaire', [
            'associe[nom]' => 'Ignoré',
            'associe[email]' => $officine->proprietaire->getEmail(),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'déjà propriétaire de cette pharmacie');
    }

    public function testRetraitPuisReactivationDUnAssocie(): void
    {
        $officine = $this->creerOfficine();
        $associe = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_PROPRIETAIRE, 'nom' => 'Ibrahim Sangaré']);
        $affectation = AffectationFactory::createOne(['utilisateur' => $associe, 'pharmacie' => $officine->pharmacie]);

        $crawler = $this->connecter($this->admin)->request('GET', \sprintf('/admin/pharmacies/%d', $officine->pharmacie->getId()));
        // Le retrait passe par une fenêtre de confirmation qui nomme le compte.
        $fenetre = $crawler->filter(\sprintf('#retirer-proprietaire-%d', $affectation->getId()));
        self::assertStringContainsString('Ibrahim Sangaré', $fenetre->filter('.modal-body')->text());
        $this->client->submit($fenetre->selectButton('Oui, retirer')->form());
        self::assertResponseRedirects();
        self::assertFalse($this->actif($affectation->getId()));
        self::assertSame(1, $this->compterAudit(AuditLogger::PROPRIETAIRE_RETIRE));

        // L'associé retiré ne peut plus se connecter à la pharmacie.
        $this->seConnecter($associe->getEmail());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'accès à la pharmacie a été désactivé');

        $crawler = $this->connecter($this->admin)->request('GET', \sprintf('/admin/pharmacies/%d', $officine->pharmacie->getId()));
        $this->client->submit($crawler->selectButton('Réactiver')->form());
        self::assertResponseRedirects();
        self::assertTrue($this->actif($affectation->getId()));
    }

    public function testLeDernierProprietaireNePeutPasEtreRetire(): void
    {
        $officine = $this->creerOfficine();
        $affectation = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Affectation::class)->findOneBy(['utilisateur' => $officine->proprietaire->getId()]));
        \assert($affectation instanceof Affectation);

        $crawler = $this->connecter($this->admin)->request('GET', \sprintf('/admin/pharmacies/%d', $officine->pharmacie->getId()));
        self::assertCount(0, $crawler->filter('button:contains("Retirer")'));

        $jeton = $crawler->filter('form[action$="/suspendre"] input[name="_token"]')->attr('value');
        $this->client->request('POST', \sprintf('/admin/pharmacies/%d/proprietaires/retirer', $officine->pharmacie->getId()), ['_token' => $jeton, 'affectation' => $affectation->getId()]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'au moins un propriétaire actif');
        self::assertTrue($this->actif((int) $affectation->getId()));
    }

    public function testUneAffectationDUneAutrePharmacieEstRefusee(): void
    {
        $officine = $this->creerOfficine();
        $autre = $this->creerOfficine();

        $crawler = $this->connecter($this->admin)->request('GET', \sprintf('/admin/pharmacies/%d', $officine->pharmacie->getId()));
        $jeton = $crawler->filter('form[action$="/suspendre"] input[name="_token"]')->attr('value');
        $this->client->request('POST', \sprintf('/admin/pharmacies/%d/proprietaires/retirer', $officine->pharmacie->getId()), ['_token' => $jeton, 'affectation' => $autre->affectationVendeur->getId()]);
        self::assertResponseStatusCodeSame(404);
    }

    private function actif(int $affectationId): ?bool
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Affectation::class, $affectationId)?->isActif());
    }

    private function compterAudit(string $action): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->count(['action' => $action]));
    }
}
