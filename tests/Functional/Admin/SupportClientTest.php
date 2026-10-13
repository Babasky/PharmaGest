<?php

namespace App\Tests\Functional\Admin;

use App\Entity\JournalAudit;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Service\AuditLogger;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\UtilisateurFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service support client : réglé par le super admin, les pharmacies le joignent par WhatsApp, appel ou email.
 */
final class SupportClientTest extends AppWebTestCase
{
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->creerSuperAdmin();
    }

    public function testParDefautLesCoordonneesDeLEditeurSontProposees(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->vendeur)->request('GET', '/support');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main #support-email[href^="mailto:contact@pharmagest.ml?subject="]');
        self::assertSelectorExists('main #support-telephone[href="tel:+22300000000"]');
        self::assertSelectorNotExists('main #support-whatsapp');
        // Accès rapide depuis la barre du haut et entrée du menu.
        self::assertSelectorExists('header #support #support-email');
        self::assertSelectorExists('#sidebar a[href="/support"]');
    }

    public function testLeSuperAdminConfigureLesCanauxEtLesPharmaciesLesVoient(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($this->admin)->request('GET', '/admin/support');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Enregistrer', [
            'support_client[whatsappActif]' => true,
            'support_client[whatsapp]' => '76 11 22 33',
            'support_client[telephoneActif]' => true,
            'support_client[telephone]' => '0022320223344',
            'support_client[emailActif]' => false,
            'support_client[email]' => 'support@softpharm.ml',
            'support_client[horaires]' => 'Du lundi au samedi, de 8 h à 18 h',
        ]);
        self::assertResponseRedirects('/admin/support');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Le service support client est enregistré.');

        $entree = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->findOneBy(['action' => AuditLogger::SUPPORT_MODIFIE]));
        self::assertInstanceOf(JournalAudit::class, $entree);
        self::assertNull($entree->getPharmacie());
        self::assertSame('+22376112233', $entree->getApres()['whatsapp'] ?? null);

        // Un enregistrement sans changement n'ajoute rien au journal.
        $this->client->submitForm('Enregistrer');
        self::assertResponseRedirects('/admin/support');
        self::assertSame(1, $this->compterAudit(AuditLogger::SUPPORT_MODIFIE));

        $this->client->request('GET', '/admin/journal');
        self::assertSelectorTextContains('#main', 'Support client modifié');

        $nom = $officine->pharmacie->getNom();
        $crawler = $this->connecter($officine->vendeur)->request('GET', '/support');
        $whatsapp = (string) $crawler->filter('main #support-whatsapp')->attr('href');
        self::assertStringStartsWith('https://wa.me/22376112233?text=', $whatsapp);
        self::assertStringContainsString(rawurlencode('Bonjour, ici '.$nom), $whatsapp);
        self::assertSelectorExists('main #support-telephone[href="tel:+22320223344"]');
        self::assertSelectorTextContains('main #support-telephone', '+223 20 22 33 44');
        self::assertSelectorNotExists('main #support-email');
        self::assertSelectorTextContains('main', 'Du lundi au samedi, de 8 h à 18 h');
    }

    public function testCanalActifSansCoordonneeRefuse(): void
    {
        $this->connecter($this->admin)->request('GET', '/admin/support');
        $this->client->submitForm('Enregistrer', [
            'support_client[whatsappActif]' => true,
            'support_client[whatsapp]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#main', 'Indiquez le numéro WhatsApp ou désactivez ce canal.');
        self::assertSame(0, $this->compterAudit(AuditLogger::SUPPORT_MODIFIE));
    }

    public function testNumeroInvalideEtAucunCanalRefuses(): void
    {
        $this->connecter($this->admin)->request('GET', '/admin/support');
        $this->client->submitForm('Enregistrer', [
            'support_client[telephone]' => '12345',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#main', '+223 XX XX XX XX');

        $this->client->submitForm('Enregistrer', [
            'support_client[telephone]' => '76 12 34 56',
            'support_client[telephoneActif]' => false,
            'support_client[emailActif]' => false,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#main', 'Activez au moins un canal');
    }

    public function testSeulLeSuperAdminRegleLeSupport(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/admin/support');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLeCaissierPeutContacterLeSupport(): void
    {
        $officine = $this->creerOfficine();
        $caissier = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_CAISSIER]);
        AffectationFactory::createOne(['utilisateur' => $caissier, 'pharmacie' => $officine->pharmacie]);

        $this->connecter($caissier)->request('GET', '/support');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#sidebar a[href="/support"]');
    }

    public function testLaPageDAccesSuspenduProposeLeSupport(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($this->admin)->request('GET', '/admin/support');
        $this->client->submitForm('Enregistrer', [
            'support_client[whatsappActif]' => true,
            'support_client[whatsapp]' => '76112233',
        ]);
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($officine): void {
            $em->find(Pharmacie::class, $officine->pharmacie->getId())?->suspendre('Impayé');
            $em->flush();
        });

        $this->connecter($officine->vendeur)->request('GET', '/compte-suspendu');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('main #support-whatsapp[href^="https://wa.me/22376112233"]');
        self::assertSelectorTextContains('main', 'contact@pharmagest.ml');
    }

    private function compterAudit(string $action): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->count(['action' => $action]));
    }
}
