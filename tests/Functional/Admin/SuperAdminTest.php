<?php

namespace App\Tests\Functional\Admin;

use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\AuditLogger;
use App\Tests\Factory\UtilisateurFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Un super admin connecté crée un autre super admin depuis l'espace plateforme.
 */
final class SuperAdminTest extends AppWebTestCase
{
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = UtilisateurFactory::new()->superAdmin()->create(['nom' => 'Modibo Diallo']);
    }

    public function testCreationPuisActivationDuNouveauSuperAdmin(): void
    {
        $this->connecter($this->admin)->request('GET', '/admin/super-admins');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/admin/super-admins"]');
        self::assertSelectorTextContains('#super-admins', 'Modibo Diallo');

        $this->client->clickLink('Nouveau super admin');
        // La validation passe par une fenêtre de confirmation qui rappelle les droits du compte.
        self::assertSelectorTextContains('#confirmer-super-admin', 'mêmes droits que vous');
        $this->client->submitForm('Oui, créer le super admin', [
            'super_admin[nom]' => 'Fatoumata Coulibaly',
            'super_admin[email]' => 'F.Coulibaly@SoftPharm.ml',
        ]);

        self::assertResponseRedirects('/admin/super-admins');
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, 'compte super admin de la plateforme');
        $lien = $this->lienDansDernierEmail();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'lien d\'activation a été envoyé à f.coulibaly@softpharm.ml');
        self::assertSelectorTextContains('#super-admins', 'En attente d\'activation');

        $cree = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Utilisateur::class)->findOneBy(['email' => 'f.coulibaly@softpharm.ml']));
        self::assertInstanceOf(Utilisateur::class, $cree);
        self::assertTrue($cree->isSuperAdmin());
        self::assertFalse($cree->isActive());

        $entree = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->findOneBy(['action' => AuditLogger::SUPER_ADMIN_CREE]));
        self::assertInstanceOf(JournalAudit::class, $entree);
        self::assertNull($entree->getPharmacie());
        self::assertSame($this->admin->getId(), $entree->getUtilisateur()?->getId());
        self::assertSame('f.coulibaly@softpharm.ml', $entree->getApres()['email'] ?? null);

        $this->client->request('GET', '/admin/journal');
        self::assertSelectorTextContains('#main', 'Super admin créé');

        // La personne choisit son mot de passe avec le lien reçu, puis arrive dans l'espace plateforme.
        $this->client->request('GET', $lien);
        self::assertSelectorTextContains('h1', 'Activez votre compte');
        $this->client->submitForm('Enregistrer le mot de passe', [
            'nouveau_mot_de_passe[motDePasse][first]' => 'plateforme-2026',
            'nouveau_mot_de_passe[motDePasse][second]' => 'plateforme-2026',
        ]);
        $this->seConnecter('f.coulibaly@softpharm.ml', 'plateforme-2026');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $this->client->followRedirects();
        $this->client->request('GET', '/admin/super-admins');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#super-admins', 'Fatoumata Coulibaly');
    }

    public function testEmailDejaUtiliseRefuse(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($this->admin)->request('GET', '/admin/super-admins/nouveau');
        $this->client->submitForm('Oui, créer le super admin', [
            'super_admin[nom]' => 'Doublon',
            'super_admin[email]' => $officine->proprietaire->getEmail(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#main', 'Un compte existe déjà avec cet email.');
        self::assertQueuedEmailCount(0);
        self::assertSame(0, $this->compterAudit(AuditLogger::SUPER_ADMIN_CREE));
    }

    public function testReserveAuxSuperAdmins(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/admin/super-admins');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/super-admins/nouveau', ['super_admin' => ['nom' => 'Intrus', 'email' => 'intrus@exemple.ml']]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Utilisateur::class)->findOneBy(['email' => 'intrus@exemple.ml'])));
    }

    public function testRenvoiDuLienAUnSuperAdminNonActive(): void
    {
        $enAttente = UtilisateurFactory::new()->superAdmin()->nonActive()->create(['nom' => 'Seydou Keïta']);
        $officine = $this->creerOfficine();

        $crawler = $this->connecter($this->admin)->request('GET', '/admin/super-admins');
        self::assertSelectorTextNotContains('#super-admins', $officine->proprietaire->getNom());
        $this->client->submit($crawler->selectButton('Renvoyer le lien')->form());

        self::assertResponseRedirects('/admin/super-admins');
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', $enAttente->getEmail());
    }

    public function testLaListeNeContientQueLesSuperAdmins(): void
    {
        UtilisateurFactory::new()->superAdmin()->create(['nom' => 'Second admin']);
        $this->creerOfficine();

        /** @var UtilisateurRepository $utilisateurs */
        $utilisateurs = self::getContainer()->get(UtilisateurRepository::class);
        $noms = array_map(static fn (Utilisateur $u) => $u->getNom(), $utilisateurs->superAdmins());

        self::assertSame(['Modibo Diallo', 'Second admin'], $noms);
    }

    private function compterAudit(string $action): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->count(['action' => $action]));
    }
}
