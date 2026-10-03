<?php

namespace App\Tests\Functional\Pharmacie;

use App\Entity\Utilisateur;
use App\Enum\MoyenPaiement;
use App\Service\AbonnementService;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Effets du cycle de vie de l'abonnement sur l'officine (§ 3.2, R-02).
 */
final class EtatAbonnementTest extends AppWebTestCase
{
    public function testR02ExpireDepuis8JoursLectureSeule(): void
    {
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->echue(8));

        // La consultation reste possible…
        $this->connecter($officine->proprietaire)->request('GET', '/equipe');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'Lecture seule');

        // … mais toute écriture est refusée.
        $this->client->request('GET', '/equipe/nouveau');
        $this->client->submitForm('Enregistrer', [
            'membre_equipe[nom]' => 'Bloqué',
            'membre_equipe[email]' => 'bloque@test.ml',
            'membre_equipe[role]' => Utilisateur::ROLE_VENDEUR,
        ]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-dismissible.alert-danger', 'PharmaGest est en lecture seule');

        $cree = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Utilisateur::class)->findOneBy(['email' => 'bloque@test.ml']));
        self::assertNull($cree);
    }

    public function testR02LesFacturesRestentTelechargeables(): void
    {
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->echue(30));
        /** @var AbonnementService $abonnements */
        $abonnements = self::getContainer()->get(AbonnementService::class);
        // Ancien paiement (la pharmacie est échue depuis, on force la date de fin pour le scénario).
        $facture = $abonnements->enregistrerPaiement($officine->pharmacie, $officine->pharmacie->getOffre(), 100000, MoyenPaiement::Especes, null, new \DateTimeImmutable('-13 months'), null);
        $officine->pharmacie->setFinAbonnement(new \DateTimeImmutable('today -30 days'));
        $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getConnection()->executeStatement('UPDATE pharmacie SET fin_abonnement = ? WHERE id = ?', [(new \DateTimeImmutable('today -30 days'))->format('Y-m-d'), $officine->pharmacie->getId()]));

        $this->connecter($officine->proprietaire)->request('GET', '/abonnement');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Expiré');

        $this->client->request('GET', '/abonnement/factures/'.$facture->getId());
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testPeriodeDeGraceTouteFonctionneAvecBandeauRouge(): void
    {
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->echue(3));

        $this->connecter($officine->proprietaire)->request('GET', '/equipe/nouveau');
        self::assertSelectorTextContains('.alert-danger', 'encore 4 jours');
        $this->client->submitForm('Enregistrer', [
            'membre_equipe[nom]' => 'Autorisé',
            'membre_equipe[email]' => 'autorise@test.ml',
            'membre_equipe[role]' => Utilisateur::ROLE_VENDEUR,
        ]);
        self::assertResponseRedirects('/equipe');
    }

    public function testBandeauDAlerteAJMoins30(): void
    {
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->with(['finAbonnement' => new \DateTimeImmutable('today +15 days')]));

        $this->connecter($officine->vendeur)->request('GET', '/');

        self::assertSelectorTextContains('.alert-warning', 'dans 15 jours');
        self::assertSelectorNotExists('.alert-warning a', 'Le lien « Mon abonnement » est réservé au propriétaire.');
    }

    public function testAucunBandeauQuandToutVaBien(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->vendeur)->request('GET', '/');

        self::assertSelectorNotExists('main .alert');
    }

    public function testPageCompteSuspenduInaccessibleSiTout(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->vendeur)->request('GET', '/compte-suspendu');

        self::assertResponseRedirects('/');
    }
}
