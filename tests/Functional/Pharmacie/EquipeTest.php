<?php

namespace App\Tests\Functional\Pharmacie;

use App\Entity\Affectation;
use App\Entity\JournalAudit;
use App\Entity\Offre;
use App\Entity\Utilisateur;
use App\Service\AuditLogger;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PH-03 : le propriétaire gère ses adjoints et vendeurs, dans la limite de son offre.
 */
final class EquipeTest extends AppWebTestCase
{
    public function testAjoutDUnVendeurAvecMotDePasseInitial(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/equipe/nouveau');
        $this->client->submitForm('Enregistrer', [
            'membre_equipe[nom]' => 'Moussa Coulibaly',
            'membre_equipe[email]' => 'm.coulibaly@test.ml',
            'membre_equipe[role]' => Utilisateur::ROLE_VENDEUR,
            'membre_equipe[motDePasseInitial]' => 'comptoir-2026',
        ]);
        self::assertResponseRedirects('/equipe');
        self::assertQueuedEmailCount(0);

        $this->seConnecter('m.coulibaly@test.ml', 'comptoir-2026');
        $this->client->followRedirect();
        self::assertSelectorTextContains('header', $officine->pharmacie->getNom());
        self::assertSame(1, $this->compterAudit(AuditLogger::UTILISATEUR_CREE));
    }

    public function testAjoutDUnAdjointSansMotDePasseEnvoieUnLienDActivation(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/equipe/nouveau');
        $this->client->submitForm('Enregistrer', [
            'membre_equipe[nom]' => 'Fatoumata Keïta',
            'membre_equipe[email]' => 'f.keita@test.ml',
            'membre_equipe[role]' => Utilisateur::ROLE_ADJOINT,
        ]);

        self::assertResponseRedirects('/equipe');
        self::assertQueuedEmailCount(1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Activation en attente');
        self::assertSelectorTextContains('main', 'Pharmacien adjoint');
    }

    public function testLimiteDUtilisateursDeLOffre(): void
    {
        // Essentiel : 3 utilisateurs actifs ; l'officine de test en a déjà 3 (propriétaire, adjoint, vendeur).
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->offre(Offre::ESSENTIEL));

        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/equipe');
        self::assertSelectorTextContains('main', '0 place disponible');
        self::assertCount(1, $crawler->filter('button[disabled]:contains("Ajouter un membre")'));

        $this->client->request('GET', '/equipe/nouveau');
        $this->client->submitForm('Enregistrer', [
            'membre_equipe[nom]' => 'De trop',
            'membre_equipe[email]' => 'trop@test.ml',
            'membre_equipe[role]' => Utilisateur::ROLE_VENDEUR,
        ]);
        self::assertSelectorTextContains('.alert-danger', 'limitée à 3 utilisateurs actifs');

        // Désactiver le vendeur libère une place.
        $this->desactiver($officine->affectationVendeur->getId());
        $this->client->request('GET', '/equipe');
        self::assertSelectorTextContains('main', '1 place disponible');
    }

    public function testDesactivationPuisReactivation(): void
    {
        $officine = $this->creerOfficine();
        $this->connecter($officine->proprietaire);

        $this->desactiver($officine->affectationVendeur->getId());
        self::assertResponseRedirects('/equipe');
        self::assertSame(1, $this->compterAudit(AuditLogger::UTILISATEUR_DESACTIVE));

        $this->seConnecter($officine->vendeur->getEmail());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'accès à la pharmacie a été désactivé');

        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/equipe');
        $this->client->submit($crawler->selectButton('Réactiver')->form());
        $actif = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Affectation::class, $officine->affectationVendeur->getId())?->isActif());
        self::assertTrue($actif);
    }

    public function testModificationDuRole(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', \sprintf('/equipe/%d/modifier', $officine->affectationVendeur->getId()));
        $this->client->submitForm('Enregistrer', ['membre_equipe[role]' => Utilisateur::ROLE_ADJOINT]);

        self::assertResponseRedirects('/equipe');
        $role = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Utilisateur::class, $officine->vendeur->getId())?->getRole());
        self::assertSame(Utilisateur::ROLE_ADJOINT, $role);
        self::assertSame(1, $this->compterAudit(AuditLogger::UTILISATEUR_MODIFIE));
    }

    public function testLeProprietaireNeGerePasSonPropreCompteIci(): void
    {
        $officine = $this->creerOfficine();
        $affectationProprietaire = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Affectation::class)->findOneBy(['utilisateur' => $officine->proprietaire->getId()]));

        $this->connecter($officine->proprietaire)->request('GET', \sprintf('/equipe/%d/modifier', $affectationProprietaire?->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdjointEtVendeurNeGerentPasLEquipe(): void
    {
        $officine = $this->creerOfficine();

        foreach ([$officine->adjoint, $officine->vendeur] as $membre) {
            $this->connecter($membre)->request('GET', '/equipe');
            self::assertResponseStatusCodeSame(403);
            $this->client->request('GET', '/abonnement');
            self::assertResponseStatusCodeSame(403);
        }
    }

    private function desactiver(int $affectationId): void
    {
        $crawler = $this->client->request('GET', '/equipe');
        $formulaire = $crawler->filter(\sprintf('form[action="/equipe/%d/desactiver"]', $affectationId))->form();
        $this->client->submit($formulaire);
    }

    private function compterAudit(string $action): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->count(['action' => $action]));
    }
}
