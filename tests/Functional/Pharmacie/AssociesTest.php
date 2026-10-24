<?php

namespace App\Tests\Functional\Pharmacie;

use App\Entity\Affectation;
use App\Entity\JournalAudit;
use App\Entity\Offre;
use App\Entity\Utilisateur;
use App\Service\AuditLogger;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\UtilisateurFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Plusieurs pharmaciens associés détiennent la même pharmacie (demande de Modibo du 20/10/2026) :
 * mêmes droits pour tous, ajout depuis l'équipe ou la fiche plateforme, retrait par le seul super admin.
 */
final class AssociesTest extends AppWebTestCase
{
    public function testLeProprietaireAjouteUnAssocieQuiRecoitUnLienDActivation(): void
    {
        $officine = $this->creerOfficine();

        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/equipe');
        self::assertCount(1, $crawler->filter('a[href="/equipe/associe"]:contains("Ajouter un associé")'));

        $this->client->request('GET', '/equipe/associe');
        $this->client->submitForm('Ajouter l\'associé', [
            'associe[nom]' => 'Ibrahim Sangaré',
            'associe[email]' => 'i.sangare@test.ml',
        ]);
        self::assertResponseRedirects('/equipe');
        self::assertQueuedEmailCount(1);

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Ibrahim Sangaré est propriétaire associé');
        $ligne = $crawler->filter('tr:contains("i.sangare@test.ml")');
        self::assertStringContainsString('Propriétaire', $ligne->text());
        self::assertStringContainsString('Activation en attente', $ligne->text());
        // Le lien d'activation peut être renvoyé, mais un associé ne se désactive pas depuis l'équipe.
        self::assertCount(1, $ligne->filter('button[title="Renvoyer le lien d\'activation"]'));
        self::assertCount(0, $ligne->filter('button:contains("Désactiver")'));

        $associe = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Utilisateur::class)->findOneBy(['email' => 'i.sangare@test.ml']));
        self::assertTrue($associe?->isProprietaire());
        self::assertSame(1, $this->compterAudit(AuditLogger::PROPRIETAIRE_AJOUTE));

        $this->client->submit($ligne->selectButton('Renvoyer le lien')->form());
        self::assertResponseRedirects('/equipe');
        self::assertQueuedEmailCount(1);
    }

    public function testLAssocieAMemesDroitsQueLeProprietaire(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/equipe/associe');
        $this->client->submitForm('Ajouter l\'associé', [
            'associe[nom]' => 'Ibrahim Sangaré',
            'associe[email]' => 'i.sangare@test.ml',
            'associe[motDePasseInitial]' => 'associe-2026',
        ]);
        self::assertResponseRedirects('/equipe');
        self::assertQueuedEmailCount(0);

        $this->seConnecter('i.sangare@test.ml', 'associe-2026');
        $this->client->followRedirect();
        self::assertSelectorTextContains('header', $officine->pharmacie->getNom());
        foreach (['/equipe', '/depenses', '/recettes', '/parametres', '/abonnement', '/journal'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }

        // Il gère l'équipe comme le titulaire, mais pas le compte de l'autre associé.
        $crawler = $this->client->request('GET', '/equipe');
        self::assertCount(1, $crawler->filter(\sprintf('a[href="/equipe/%d/modifier"]', $officine->affectationVendeur->getId())));
        $affectationTitulaire = $this->affectationDe($officine->proprietaire);
        $this->client->request('GET', \sprintf('/equipe/%d/modifier', $affectationTitulaire->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnAssocieNePeutPasDesactiverLAutre(): void
    {
        $officine = $this->creerOfficine();
        $associe = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_PROPRIETAIRE]);
        $affectation = AffectationFactory::createOne(['utilisateur' => $associe, 'pharmacie' => $officine->pharmacie]);

        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/equipe');
        self::assertCount(0, $crawler->filter(\sprintf('#desactiver-%d', $affectation->getId())));

        $this->client->request('GET', \sprintf('/equipe/%d/modifier', $affectation->getId()));
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->affectationDe($associe)->isActif());
    }

    public function testUnProprietaireDUneAutrePharmacieDevientAssocieAvecLOffrePremium(): void
    {
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->offre(Offre::PREMIUM));
        $autre = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/equipe/associe');
        $this->client->submitForm('Ajouter l\'associé', [
            'associe[nom]' => 'Ignoré',
            'associe[email]' => $autre->proprietaire->getEmail(),
        ]);
        self::assertResponseRedirects('/equipe');
        self::assertQueuedEmailCount(0, message: 'Compte déjà actif : pas de lien d\'activation.');

        // Il bascule maintenant entre ses deux pharmacies.
        $crawler = $this->connecter($autre->proprietaire)->request('GET', '/');
        self::assertCount(2, $crawler->filter('form[action="/pharmacie/basculer"]'));
    }

    public function testLaLimiteDePharmaciesDeLOffreSAppliqueAuxAssocies(): void
    {
        $officine = $this->creerOfficine();
        $autre = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/equipe/associe');
        $this->client->submitForm('Ajouter l\'associé', [
            'associe[nom]' => 'Ignoré',
            'associe[email]' => $autre->proprietaire->getEmail(),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'L\'offre Standard permet 1 pharmacie(s) par propriétaire');
    }

    public function testUnAdjointDeLaPharmacieDevientAssocie(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->proprietaire)->request('GET', '/equipe/associe');
        $this->client->submitForm('Ajouter l\'associé', [
            'associe[nom]' => 'Ignoré',
            'associe[email]' => $officine->adjoint->getEmail(),
        ]);
        self::assertResponseRedirects('/equipe');

        $role = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Utilisateur::class, $officine->adjoint->getId())?->getRole());
        self::assertSame(Utilisateur::ROLE_PROPRIETAIRE, $role);
        $promu = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->findOneBy(['action' => AuditLogger::PROPRIETAIRE_AJOUTE])?->getApres());
        self::assertTrue($promu['promu'] ?? null);

        // Rôle changé : il se reconnecte avec son nouveau rôle.
        $this->seConnecter($officine->adjoint->getEmail());
        $this->client->request('GET', '/depenses');
        self::assertResponseIsSuccessful();
    }

    public function testRefusPourUnMembreDUneAutrePharmacieOuUnProprietaireDejaPresent(): void
    {
        $officine = $this->creerOfficine();
        $autre = $this->creerOfficine();

        foreach ([
            $autre->vendeur->getEmail() => 'déjà à un membre d\'une autre pharmacie',
            $officine->proprietaire->getEmail() => 'déjà propriétaire de cette pharmacie',
        ] as $email => $message) {
            $this->connecter($officine->proprietaire)->request('GET', '/equipe/associe');
            $this->client->submitForm('Ajouter l\'associé', ['associe[nom]' => 'Ignoré', 'associe[email]' => $email]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('.alert-danger', $message);
        }
        self::assertSame(0, $this->compterAudit(AuditLogger::PROPRIETAIRE_AJOUTE));
    }

    public function testLAssocieCompteDansLaLimiteDUtilisateurs(): void
    {
        // Essentiel : 3 utilisateurs actifs, déjà atteints (propriétaire, adjoint, vendeur).
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->offre(Offre::ESSENTIEL));

        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/equipe');
        self::assertCount(1, $crawler->filter('button[disabled]:contains("Ajouter un associé")'));

        $this->client->request('GET', '/equipe/associe');
        $this->client->submitForm('Ajouter l\'associé', ['associe[nom]' => 'De trop', 'associe[email]' => 'trop@test.ml']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'limitée à 3 utilisateurs actifs');
    }

    public function testAdjointEtVendeurNAjoutentPasDAssocie(): void
    {
        $officine = $this->creerOfficine();

        foreach ([$officine->adjoint, $officine->vendeur] as $membre) {
            $this->connecter($membre)->request('GET', '/equipe/associe');
            self::assertResponseStatusCodeSame(403);
        }
    }

    private function affectationDe(Utilisateur $utilisateur): Affectation
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Affectation::class)->findOneBy(['utilisateur' => $utilisateur->getId()]))
            ?? throw new \LogicException();
    }

    private function compterAudit(string $action): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->count(['action' => $action]));
    }
}
