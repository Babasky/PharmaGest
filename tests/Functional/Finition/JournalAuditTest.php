<?php

namespace App\Tests\Functional\Finition;

use App\Entity\JournalAudit;
use App\Entity\Pharmacie;
use App\Service\AuditLogger;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Journal d'audit : traces des modifications de prix et de paramètres (AU-01), consultation filtrable par le
 * propriétaire et journal de la plateforme pour le super admin (AU-02).
 */
final class JournalAuditTest extends AppWebTestCase
{
    public function testLeProprietaireConsulteEtFiltreLeJournalDeSaPharmacie(): void
    {
        $officine = $this->creerOfficine();
        $autre = $this->creerOfficine();
        $produit = ProduitFactory::createOne(['pharmacie' => $officine->pharmacie, 'nomCommercial' => 'Doliprane', 'prixVente' => 1000]);
        $this->tracer(AuditLogger::VENTE_ANNULEE, $autre->pharmacie, ['motif' => 'Erreur dans une autre officine']);

        // Modification de prix et des règles de gestion : tracées quel que soit l'écran.
        $this->connecter($officine->proprietaire)->request('GET', '/produits/'.$produit->getId().'/modifier');
        $this->client->submitForm('Enregistrer', ['produit[prixVente]' => '1250']);
        self::assertResponseRedirects();
        $this->client->request('GET', '/parametres?onglet=regles');
        $this->client->submitForm('Enregistrer les règles', ['parametres[plafondRemise]' => '15']);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/journal');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#sidebar a.active', "Journal d'audit");
        $journal = $crawler->filter('#journal')->text();
        self::assertStringContainsString('Prix modifié', $journal);
        self::assertStringContainsString('produit : Doliprane · prix vente : 1000', $journal);
        self::assertStringContainsString('prix vente : 1250', $journal);
        self::assertStringContainsString('Règles de gestion modifiées', $journal);
        self::assertStringContainsString('plafond remise : 15', $journal);
        self::assertStringContainsString($officine->proprietaire->getNom(), $journal);
        self::assertStringNotContainsString('autre officine', $journal, 'Le journal ne montre que la pharmacie courante.');
        self::assertCount(1, $crawler->filter('#journal a[href="/produits/'.$produit->getId().'"]'));

        $this->client->request('GET', '/journal', ['action' => AuditLogger::PRODUIT_PRIX_MODIFIE]);
        self::assertSame(1, $this->client->getCrawler()->filter('#journal tbody tr')->count());
        $this->client->request('GET', '/journal', ['utilisateur' => (string) $officine->proprietaire->getId(), 'du' => (new \DateTimeImmutable('today'))->format('Y-m-d')]);
        self::assertSame(2, $this->client->getCrawler()->filter('#journal tbody tr')->count());
        $this->client->request('GET', '/journal', ['du' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')]);
        self::assertSelectorTextContains('#journal', 'Aucune entrée pour ces critères.');

        // AU-02 : lecture réservée au propriétaire ; aucune route ne modifie ni ne supprime une entrée.
        foreach ([$officine->adjoint, $officine->vendeur] as $utilisateur) {
            $this->connecter($utilisateur)->request('GET', '/journal');
            self::assertResponseStatusCodeSame(403);
        }
        $this->connecter($officine->proprietaire)->request('POST', '/journal');
        self::assertResponseStatusCodeSame(405);
    }

    public function testLeSuperAdminNeVoitQueLesActionsDeLaPlateforme(): void
    {
        $officine = $this->creerOfficine();
        $this->tracer(AuditLogger::PHARMACIE_SUSPENDUE, $officine->pharmacie, ['motif' => 'Impayé']);
        $this->tracer(AuditLogger::VENTE_ANNULEE, $officine->pharmacie, ['motif' => 'Donnée de l\'officine']);

        $crawler = $this->connecter($this->creerSuperAdmin())->request('GET', '/admin/journal');
        self::assertResponseIsSuccessful();
        $journal = $crawler->filter('#journal')->text();
        self::assertStringContainsString('Pharmacie suspendue', $journal);
        self::assertStringContainsString($officine->pharmacie->getNom(), $journal);
        self::assertStringNotContainsString('Vente annulée', $journal, 'Le super admin ne voit pas l\'activité des officines.');
        self::assertStringNotContainsString('Vente annulée', $crawler->filter('#audit-action')->text());

        $this->client->request('GET', '/admin/journal', ['action' => AuditLogger::VENTE_ANNULEE]);
        self::assertStringContainsString('Pharmacie suspendue', $this->client->getCrawler()->filter('#journal')->text(), 'Action hors plateforme ignorée.');

        $this->connecter($officine->proprietaire)->request('GET', '/admin/journal');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $apres
     */
    private function tracer(string $action, Pharmacie $pharmacie, array $apres): void
    {
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($action, $pharmacie, $apres): void {
            $em->persist(new JournalAudit($action, $em->getReference(Pharmacie::class, $pharmacie->getId()), null, 'Pharmacie', $pharmacie->getId(), null, $apres));
            $em->flush();
        });
    }
}
