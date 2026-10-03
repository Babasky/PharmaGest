<?php

namespace App\Tests\Functional;

use App\Entity\Abonnement;
use App\Entity\Affectation;
use App\Entity\Offre;
use App\Entity\Utilisateur;
use App\Enum\MoyenPaiement;
use App\Repository\AffectationRepository;
use App\Service\AbonnementService;
use App\Tenant\TenantContext;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\Officine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Isolation multi-tenant (§ 2 du cahier de mission, § 6 et R-01 du cahier des charges) :
 * un utilisateur de la pharmacie A ne peut ni lire, ni modifier, ni télécharger une donnée de B,
 * même en manipulant les identifiants dans l'URL. Réponse attendue : 404.
 */
final class IsolationTenantTest extends AppWebTestCase
{
    private Officine $a;
    private Officine $b;
    private Abonnement $factureB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->creerOfficine();
        $this->b = $this->creerOfficine();

        /** @var AbonnementService $abonnements */
        $abonnements = self::getContainer()->get(AbonnementService::class);
        $this->factureB = $abonnements->enregistrerPaiement($this->b->pharmacie, $this->b->pharmacie->getOffre(), 150000, MoyenPaiement::OrangeMoney, 'OM-1', new \DateTimeImmutable('today'), null);
    }

    public function testLeProprietaireNeVoitPasLEquipeDUneAutrePharmacie(): void
    {
        $this->connecter($this->a->proprietaire)->request('GET', '/equipe');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $this->a->vendeur->getEmail());
        self::assertSelectorTextNotContains('main', $this->b->vendeur->getEmail());
        self::assertSelectorTextNotContains('main', $this->b->proprietaire->getEmail());
    }

    public function testLecturePar404(): void
    {
        $this->connecter($this->a->proprietaire);

        $this->client->request('GET', \sprintf('/equipe/%d/modifier', $this->b->affectationVendeur->getId()));
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextNotContains('body', $this->b->vendeur->getEmail());
    }

    public function testModificationPar404EtDonneeIntacte(): void
    {
        $this->connecter($this->a->proprietaire);

        $this->client->request('POST', \sprintf('/equipe/%d/modifier', $this->b->affectationVendeur->getId()), [
            'membre_equipe' => ['nom' => 'Piraté', 'email' => 'pirate@test.ml', 'role' => Utilisateur::ROLE_VENDEUR],
        ]);
        self::assertResponseStatusCodeSame(404);

        $nom = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->find(Utilisateur::class, $this->b->vendeur->getId())?->getNom());
        self::assertSame($this->b->vendeur->getNom(), $nom);
    }

    public function testDesactivationPar404(): void
    {
        $this->connecter($this->a->proprietaire);

        $this->client->request('POST', \sprintf('/equipe/%d/desactiver', $this->b->affectationVendeur->getId()), ['_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(404);

        $actif = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->find(Affectation::class, $this->b->affectationVendeur->getId())?->isActif());
        self::assertTrue($actif);
    }

    public function testTelechargementDeFacturePar404(): void
    {
        $this->connecter($this->a->proprietaire)->request('GET', \sprintf('/abonnement/factures/%d', $this->factureB->getId()));

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderNotSame('Content-Type', 'application/pdf');
    }

    public function testLeProprietaireTelechargeSaPropreFacture(): void
    {
        $this->connecter($this->b->proprietaire)->request('GET', \sprintf('/abonnement/factures/%d', $this->factureB->getId()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $this->client->getInternalResponse()->getContent());
    }

    public function testIdentifiantsInexistantsEt404(): void
    {
        $this->connecter($this->a->proprietaire)->request('GET', '/equipe/999999/modifier');

        self::assertResponseStatusCodeSame(404);
    }

    public function testImpossibleDeBasculerVersUnePharmacieNonAffectee(): void
    {
        // Un propriétaire Premium avec deux pharmacies voit le sélecteur (et donc un jeton CSRF valide).
        $secondaire = PharmacieFactory::new()->offre(Offre::PREMIUM)->create();
        AffectationFactory::createOne(['utilisateur' => $this->a->proprietaire, 'pharmacie' => $secondaire]);

        $crawler = $this->connecter($this->a->proprietaire)->request('GET', '/');
        $jeton = (string) $crawler->filter('form[action="/pharmacie/basculer"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/pharmacie/basculer', ['_token' => $jeton, 'pharmacie' => $this->b->pharmacie->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/pharmacie/basculer', ['_token' => $jeton, 'pharmacie' => $secondaire->getId()]);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('header', $secondaire->getNom());
    }

    public function testLeFiltreDoctrineNeRenvoieQueLesDonneesDeLaPharmacieCourante(): void
    {
        /** @var TenantContext $tenant */
        $tenant = self::getContainer()->get(TenantContext::class);
        /** @var AffectationRepository $affectations */
        $affectations = self::getContainer()->get(AffectationRepository::class);

        $this->viderGestionnaire();
        $tenant->forcer($this->a->pharmacie);
        $pharmacies = array_unique(array_map(static fn (Affectation $x) => $x->getPharmacie()?->getId(), $affectations->findAll()));
        self::assertSame([$this->a->pharmacie->getId()], array_values($pharmacies));
        self::assertNull($affectations->find($this->b->affectationVendeur->getId()));

        // Sans pharmacie, le filtre est fermé : aucune ligne.
        $tenant->forcer(null);
        $tenant->activerFiltre(null);
        self::assertSame([], $affectations->findAll());
    }

    public function testImpossibleDEcrireDansUneAutrePharmacie(): void
    {
        /** @var TenantContext $tenant */
        $tenant = self::getContainer()->get(TenantContext::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tenant->forcer($this->a->pharmacie);

        $this->expectException(\LogicException::class);
        $em->persist(new Affectation($this->a->vendeur, $this->b->pharmacie));
    }

    public function testLaPharmacieEstAffecteeAutomatiquementALaCreation(): void
    {
        /** @var TenantContext $tenant */
        $tenant = self::getContainer()->get(TenantContext::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tenant->forcer($this->a->pharmacie);

        $nouveau = (new Utilisateur())->setEmail('nouveau@test.ml')->setNom('Nouveau')->setRole(Utilisateur::ROLE_VENDEUR);
        $affectation = new Affectation($nouveau);
        $em->persist($nouveau);
        $em->persist($affectation);

        self::assertSame($this->a->pharmacie, $affectation->getPharmacie());
    }

    public function testLesRolesDOfficineNAccedentPasALEspaceSuperAdmin(): void
    {
        foreach ([$this->a->proprietaire, $this->a->adjoint, $this->a->vendeur] as $utilisateur) {
            $this->connecter($utilisateur)->request('GET', '/admin/pharmacies');
            self::assertResponseStatusCodeSame(403, $utilisateur->getRole());
        }
    }

    public function testLeSuperAdminNAccedePasAuxDonneesDesPharmacies(): void
    {
        $this->connecter($this->creerSuperAdmin());

        $this->client->request('GET', '/equipe');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/abonnement');
        self::assertResponseStatusCodeSame(403);
    }
}
