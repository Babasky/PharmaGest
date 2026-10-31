<?php

namespace App\Tests\Integration;

use App\Entity\Pharmacie;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\StatutVente;
use App\Service\StatutVerrouille;
use App\Stock\StockService;
use App\Tenant\TenantContext;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
use App\Vente\VenteException;
use App\Vente\VenteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Requêtes concurrentes ou rejouées sur un même document (double envoi, deux onglets, deux utilisateurs).
 *
 * La requête concurrente est simulée en changeant le statut directement en base : la vente en mémoire garde
 * l'ancien statut, exactement comme dans la seconde requête, qui a lu la vente avant que la première ne la valide.
 */
final class ConcurrenceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private EntityManagerInterface $em;
    private VenteService $ventes;
    private StockService $stock;
    private Pharmacie $pharmacie;
    private Utilisateur $vendeur;
    private Produit $produit;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-05 09:00:00');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ventes = self::getContainer()->get(VenteService::class);
        $this->stock = self::getContainer()->get(StockService::class);
        $this->pharmacie = PharmacieFactory::createOne();
        self::getContainer()->get(TenantContext::class)->forcer($this->pharmacie);
        $this->vendeur = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_VENDEUR]);
        $this->produit = ProduitFactory::createOne(['pharmacie' => $this->pharmacie]);
        $this->stock->entrer($this->produit, 'A', new \DateTimeImmutable('2027-03-31'), 10, 800);
    }

    public function testPanierValideDeuxFoisNeSortLeStockQuUneFois(): void
    {
        $vente = $this->panier(3);
        $this->statutEnBase($vente, StatutVente::AEncaisser); // L'autre onglet vient de l'envoyer à la caisse.

        try {
            $this->ventes->envoyerEnCaisse($vente, $this->vendeur);
            self::fail('La seconde validation du même panier doit être refusée.');
        } catch (VenteException $e) {
            self::assertStringContainsString('vient d\'être validée', $e->getMessage());
        }

        self::assertSame(10, $this->stock->stockDisponible($this->produit));
        self::assertNull($vente->getNumero());
    }

    public function testAnnulationRejoueeNeRemetPasLeStockDeuxFois(): void
    {
        $vente = $this->panier(3);
        $this->ventes->envoyerEnCaisse($vente, $this->vendeur);
        self::assertSame(7, $this->stock->stockDisponible($this->produit));

        $this->statutEnBase($vente, StatutVente::Annulee); // Première annulation, déjà enregistrée.

        try {
            $this->ventes->annuler($vente, 'Client reparti');
            self::fail('La seconde annulation doit être refusée.');
        } catch (VenteException $e) {
            self::assertStringContainsString('vient d\'être annulée', $e->getMessage());
        }

        self::assertSame(7, $this->stock->stockDisponible($this->produit));
    }

    public function testValidationNormaleInchangee(): void
    {
        $vente = $this->panier(3);
        $this->ventes->envoyerEnCaisse($vente, $this->vendeur);

        self::assertSame(StatutVente::AEncaisser, $vente->getStatut());
        self::assertSame('V-2026-000001', $vente->getNumero());
        self::assertSame(7, $this->stock->stockDisponible($this->produit));

        $this->ventes->annuler($vente, 'Client reparti');
        self::assertSame(10, $this->stock->stockDisponible($this->produit));
    }

    public function testStatutRelueEnBaseEtNonEnMemoire(): void
    {
        $statuts = self::getContainer()->get(StatutVerrouille::class);
        $vente = $this->panier(1);

        $this->em->getConnection()->transactional(static function () use ($statuts, $vente): void {
            self::assertTrue($statuts->estParmi($vente, [StatutVente::EnCours, StatutVente::EnAttente]));
        });

        $this->statutEnBase($vente, StatutVente::Validee);
        self::assertSame(StatutVente::EnCours, $vente->getStatut());
        $this->em->getConnection()->transactional(static function () use ($statuts, $vente): void {
            self::assertFalse($statuts->estParmi($vente, [StatutVente::EnCours, StatutVente::EnAttente]));
            self::assertTrue($statuts->estParmi($vente, [StatutVente::Validee]));
        });

        // Un document pas encore enregistré n'a pas de concurrent.
        $nouvelle = new Vente($this->vendeur, new \DateTimeImmutable());
        $this->em->getConnection()->transactional(static function () use ($statuts, $nouvelle): void {
            self::assertTrue($statuts->estParmi($nouvelle, [StatutVente::EnCours]));
        });
    }

    private function panier(int $quantite): Vente
    {
        $vente = $this->ventes->panierOuNouveau($this->vendeur);
        $this->ventes->ajouter($vente, $this->produit, $quantite);
        $this->em->flush();

        return $vente;
    }

    private function statutEnBase(Vente $vente, StatutVente $statut): void
    {
        $this->em->getConnection()->executeStatement('UPDATE vente SET statut = ? WHERE id = ?', [$statut->value, $vente->getId()]);
    }
}
