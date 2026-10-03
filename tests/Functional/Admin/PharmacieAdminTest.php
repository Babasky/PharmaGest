<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Abonnement;
use App\Entity\JournalAudit;
use App\Entity\Offre;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Service\AuditLogger;
use App\Tests\Factory\OffreFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SA-01 à SA-06 : création d'une pharmacie et de son propriétaire, paiements, factures, suspension.
 */
final class PharmacieAdminTest extends AppWebTestCase
{
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->creerSuperAdmin();
    }

    public function testCreationPuisActivationDuProprietaire(): void
    {
        $this->connecter($this->admin)->request('GET', '/admin/pharmacies/nouvelle');
        $this->client->submitForm('Créer la pharmacie', [
            'nouvelle_pharmacie[pharmacie][nom]' => 'Pharmacie du Fleuve',
            'nouvelle_pharmacie[pharmacie][numeroAutorisation]' => 'AUT-2026-117',
            'nouvelle_pharmacie[pharmacie][ville]' => 'Bamako',
            'nouvelle_pharmacie[pharmacie][adresse]' => 'Badalabougou, rue 12',
            'nouvelle_pharmacie[pharmacie][telephone]' => '76 12 34 56',
            'nouvelle_pharmacie[offre]' => (string) OffreFactory::parCode(Offre::ESSENTIEL)->getId(),
            'nouvelle_pharmacie[joursEssai]' => '30',
            'nouvelle_pharmacie[nomProprietaire]' => 'Aminata Traoré',
            'nouvelle_pharmacie[emailProprietaire]' => 'a.traore@fleuve.ml',
        ]);

        self::assertResponseRedirects();
        self::assertQueuedEmailCount(1);
        $lien = $this->lienDansDernierEmail();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'lien d\'activation a été envoyé à a.traore@fleuve.ml');
        self::assertSelectorTextContains('main', '+223 76 12 34 56');
        self::assertSelectorTextContains('main', "Période d'essai");

        $pharmacie = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Pharmacie::class)->findOneBy(['nom' => 'Pharmacie du Fleuve']));
        self::assertInstanceOf(Pharmacie::class, $pharmacie);
        self::assertSame('+22376123456', $pharmacie->getTelephone());
        self::assertSame((new \DateTimeImmutable('today +30 days'))->format('Y-m-d'), $pharmacie->getFinEssai()?->format('Y-m-d'));
        self::assertSame(1, $this->compterAudit(AuditLogger::PHARMACIE_CREEE));

        // Le propriétaire active son compte avec le lien reçu, puis se connecte.
        $this->client->request('GET', $lien);
        self::assertSelectorTextContains('h1', 'Activez votre compte');
        $this->client->submitForm('Enregistrer le mot de passe', [
            'nouveau_mot_de_passe[motDePasse][first]' => 'officine-2026',
            'nouveau_mot_de_passe[motDePasse][second]' => 'officine-2026',
        ]);
        $this->seConnecter('a.traore@fleuve.ml', 'officine-2026');
        $this->client->followRedirect();

        self::assertSelectorTextContains('header', 'Pharmacie du Fleuve');
        self::assertSelectorTextContains('.alert-info', "Période d'essai : il reste 30 jours");
    }

    public function testTelephoneInvalideRefuse(): void
    {
        $this->connecter($this->admin)->request('GET', '/admin/pharmacies/nouvelle');
        $this->client->submitForm('Créer la pharmacie', [
            'nouvelle_pharmacie[pharmacie][nom]' => 'Pharmacie Test',
            'nouvelle_pharmacie[pharmacie][numeroAutorisation]' => 'AUT-1',
            'nouvelle_pharmacie[pharmacie][ville]' => 'Kayes',
            'nouvelle_pharmacie[pharmacie][adresse]' => 'Centre',
            'nouvelle_pharmacie[pharmacie][telephone]' => '12345',
            'nouvelle_pharmacie[nomProprietaire]' => 'X',
            'nouvelle_pharmacie[emailProprietaire]' => 'x@test.ml',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', '+223 XX XX XX XX');
        self::assertQueuedEmailCount(0);
    }

    public function testUnProprietaireStandardNePeutPasAvoirDeuxPharmacies(): void
    {
        $officine = $this->creerOfficine();

        $this->soumettreCreation('Seconde officine', $officine->proprietaire->getEmail(), Offre::STANDARD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'L\'offre Standard permet 1 pharmacie(s) par propriétaire');
    }

    public function testUnProprietairePremiumPeutAvoirPlusieursPharmacies(): void
    {
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->offre(Offre::PREMIUM));

        $this->soumettreCreation('Officine de Kati', $officine->proprietaire->getEmail(), Offre::PREMIUM);

        self::assertResponseRedirects();
        self::assertQueuedEmailCount(0, message: 'Le propriétaire a déjà un compte actif : pas de nouvel email.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'ajoutée aux pharmacies de');

        // Le propriétaire voit maintenant le sélecteur avec ses deux pharmacies.
        $crawler = $this->connecter($officine->proprietaire)->request('GET', '/');
        self::assertCount(2, $crawler->filter('form[action="/pharmacie/basculer"]'));
    }

    public function testPaiementProlongeEtGenereUneFacture(): void
    {
        $pharmacie = PharmacieFactory::new()->enEssai(10)->create();

        $this->connecter($this->admin)->request('GET', '/admin/pharmacies/'.$pharmacie->getId());
        $this->client->submitForm('Enregistrer et générer la facture', [
            'paiement[offre]' => (string) OffreFactory::parCode(Offre::PREMIUM)->getId(),
            'paiement[montant]' => '240000',
            'paiement[moyen]' => 'orange_money',
            'paiement[reference]' => 'OM-778899',
            'paiement[datePaiement]' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $annee = date('Y');
        self::assertSelectorTextContains('.alert-success', \sprintf('facture FAC-%s-', $annee));
        self::assertSelectorTextContains('main', "240\u{00A0}000\u{00A0}FCFA");

        $abonnement = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Abonnement::class)->findOneBy(['reference' => 'OM-778899']));
        self::assertInstanceOf(Abonnement::class, $abonnement);
        self::assertSame(Offre::PREMIUM, $abonnement->getOffre()->getCode());
        self::assertSame((new \DateTimeImmutable('today +12 months'))->format('Y-m-d'), $abonnement->getDateFin()->format('Y-m-d'));
        self::assertMatchesRegularExpression('/^FAC-\d{4}-\d{6}$/', $abonnement->getNumeroFacture());
        self::assertSame(1, $this->compterAudit(AuditLogger::ABONNEMENT_PAIEMENT));

        $this->client->request('GET', '/admin/abonnements/'.$abonnement->getId().'/facture');
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $this->client->getInternalResponse()->getContent());
    }

    public function testDeuxPaiementsSuccessifsSeCumulent(): void
    {
        $pharmacie = PharmacieFactory::createOne(['finAbonnement' => new \DateTimeImmutable('today +40 days')]);
        $this->connecter($this->admin);

        foreach (['P-1', 'P-2'] as $reference) {
            $this->client->request('GET', '/admin/pharmacies/'.$pharmacie->getId());
            $this->client->submitForm('Enregistrer et générer la facture', ['paiement[montant]' => '150000', 'paiement[reference]' => $reference]);
        }

        $fin = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Pharmacie::class, $pharmacie->getId())?->getFinAbonnement());
        self::assertSame((new \DateTimeImmutable('today +40 days'))->modify('+24 months')->format('Y-m'), $fin?->format('Y-m'));

        $numeros = $this->sansFiltre(static fn (EntityManagerInterface $em) => array_map(
            static fn (Abonnement $a) => $a->getNumeroFacture(),
            $em->getRepository(Abonnement::class)->findBy(['pharmacie' => $pharmacie->getId()], ['id' => 'ASC']),
        ));
        self::assertCount(2, $numeros);
        [$premier, $second] = array_map(static fn (string $n) => (int) substr($n, -6), $numeros);
        self::assertSame($premier + 1, $second, 'Numérotation sans trou (RG-02).');
    }

    public function testSuspensionCoupeLAccesPuisReactivation(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($this->admin)->request('GET', '/admin/pharmacies/'.$officine->pharmacie->getId());
        $this->client->submitForm('Suspendre l\'accès', ['motif' => 'Impayé']);
        self::assertResponseRedirects();

        $this->connecter($officine->vendeur)->request('GET', '/');
        self::assertResponseRedirects('/compte-suspendu');
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('main', 'est suspendu');
        self::assertSelectorTextContains('main', 'contact@pharmagest.ml');

        $this->connecter($this->admin)->request('GET', '/admin/pharmacies/'.$officine->pharmacie->getId());
        $this->client->submitForm('Réactiver l\'accès');

        $this->connecter($officine->vendeur)->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->compterAudit(AuditLogger::PHARMACIE_SUSPENDUE) + $this->compterAudit(AuditLogger::PHARMACIE_REACTIVEE));
    }

    public function testArchivageCoupeLAcces(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($this->admin)->request('GET', '/admin/pharmacies/'.$officine->pharmacie->getId());
        $this->client->submitForm('Archiver la pharmacie');

        $this->connecter($officine->proprietaire)->request('GET', '/equipe');
        self::assertResponseRedirects('/compte-suspendu');
    }

    public function testTableauDeBordEtListes(): void
    {
        PharmacieFactory::createOne(['nom' => 'Pharmacie Active']);
        PharmacieFactory::new()->enEssai(5)->create(['nom' => 'Pharmacie En Essai']);
        PharmacieFactory::createOne(['nom' => 'Pharmacie Bientôt', 'finAbonnement' => new \DateTimeImmutable('today +12 days')]);
        PharmacieFactory::new()->echue(20)->create(['nom' => 'Pharmacie Expirée']);

        $crawler = $this->connecter($this->admin)->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Pharmacie Bientôt');
        self::assertSelectorTextContains('main', 'Pharmacie Expirée');
        self::assertSelectorExists('[data-controller="chart"]');
        self::assertGreaterThanOrEqual(1, (int) $crawler->filter('.card .fs-4')->eq(3)->text());

        $this->client->request('GET', '/admin/pharmacies?q=Essai');
        self::assertSelectorTextContains('tbody', 'Pharmacie En Essai');
        self::assertSelectorTextNotContains('tbody', 'Pharmacie Active');

        $this->client->request('GET', '/admin/abonnements');
        self::assertSelectorTextContains('main', 'Pharmacie Bientôt');

        $this->client->request('GET', '/admin/offres');
        self::assertSelectorTextContains('main', 'Premium');
        self::assertSelectorTextContains('main', 'Illimités');
    }

    private function soumettreCreation(string $nom, string $emailProprietaire, string $offre): void
    {
        $this->connecter($this->admin)->request('GET', '/admin/pharmacies/nouvelle');
        $this->client->submitForm('Créer la pharmacie', [
            'nouvelle_pharmacie[pharmacie][nom]' => $nom,
            'nouvelle_pharmacie[pharmacie][numeroAutorisation]' => 'AUT-'.random_int(1000, 9999),
            'nouvelle_pharmacie[pharmacie][ville]' => 'Kati',
            'nouvelle_pharmacie[pharmacie][adresse]' => 'Marché',
            'nouvelle_pharmacie[pharmacie][telephone]' => '+223 66 00 11 22',
            'nouvelle_pharmacie[offre]' => (string) OffreFactory::parCode($offre)->getId(),
            'nouvelle_pharmacie[nomProprietaire]' => 'Peu importe',
            'nouvelle_pharmacie[emailProprietaire]' => $emailProprietaire,
        ]);
    }

    private function compterAudit(string $action): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->count(['action' => $action]));
    }
}
