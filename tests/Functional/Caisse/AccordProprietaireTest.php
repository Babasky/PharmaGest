<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\JournalAudit;
use App\Entity\Notification;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Enum\PolitiqueSansOrdonnance;
use App\Enum\StatutVente;
use App\Enum\TypeNotification;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Retours de Modibo (20/10/2026) : pour un médicament qui exige une ordonnance, le propriétaire confirme la vente
 * depuis son propre compte, sans communiquer son code PIN au vendeur.
 */
final class AccordProprietaireTest extends CaisseTestCase
{
    private Produit $antibiotique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->antibiotique = ProduitFactory::createOne([
            'pharmacie' => $this->officine->pharmacie,
            'nomCommercial' => 'Amoxicilline',
            'prixVente' => 2500,
            'ordonnanceObligatoire' => true,
        ]);
        LotFactory::createOne(['produit' => $this->antibiotique]);
        $this->definirPin($this->officine->proprietaire, '4826');
        $this->definirPin($this->officine->vendeur, self::PIN);
    }

    public function testLeProprietaireDonneSonAccordDepuisSonCompteSansCodePin(): void
    {
        $this->demarrerLaVente();
        $this->client->request('GET', '/caisse');
        self::assertSelectorTextContains('#accord-proprietaire', "Demander l'accord du propriétaire");

        $this->poster('/caisse/accord');
        self::assertResponseRedirects('/caisse');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#accord-proprietaire .alert-info', 'en attente de sa réponse');
        self::assertSelectorTextNotContains('#accord-proprietaire', "Demander l'accord du propriétaire");

        $notifications = $this->notifications();
        self::assertCount(1, $notifications);
        self::assertSame(TypeNotification::Accord, $notifications[0]->getType());
        self::assertSame($this->officine->proprietaire->getId(), $notifications[0]->getUtilisateur()->getId());

        // Le propriétaire répond depuis son compte (son téléphone, son poste).
        $this->connecter($this->officine->proprietaire)->request('GET', '/accords');
        self::assertSelectorTextContains('#accords', $this->officine->vendeur->getNom());
        $this->client->clickLink('Examiner');
        self::assertSelectorTextContains('#panier-accord', 'Amoxicilline');
        self::assertSelectorTextContains('#panier-accord', 'Ordonnance obligatoire');
        $this->client->submitForm('Donner mon accord');
        self::assertResponseRedirects('/accords');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Accord donné à '.$this->officine->vendeur->getNom());
        self::assertSelectorTextContains('#accords', 'Aucune demande en attente');

        // Le vendeur valide sans le code PIN du propriétaire.
        $this->connecter($this->officine->vendeur)->request('GET', '/caisse');
        self::assertSelectorTextContains('#accord-proprietaire .alert-success', 'Accord donné par '.$this->officine->proprietaire->getNom());
        self::assertSelectorNotExists('#encaissement button[type=submit][disabled]');
        $this->encaisser();

        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame($this->officine->proprietaire->getId(), $vente->getAutorisePar()?->getId());
        self::assertSame(1, $this->audits('vente.accord_donne'));
        self::assertSame(1, $this->audits('vente.sans_ordonnance'));
    }

    public function testUnPanierModifieApresLAccordRedemandeLaConfirmation(): void
    {
        $this->demarrerLaVente();
        $this->accordDuProprietaire();
        $this->ajouter($this->antibiotique, 5);
        $this->client->request('GET', '/caisse');
        self::assertSelectorNotExists('#accord-proprietaire .alert-success');
        self::assertSelectorTextContains('#accord-proprietaire', "Demander l'accord du propriétaire");

        $this->encaisser();
        self::assertStringContainsString('propriétaire', $this->erreur());
        self::assertSame(StatutVente::EnCours, $this->derniereVente()->getStatut());

        // Le code PIN du propriétaire, même connu du vendeur, ne remplace pas l'accord : il faut le redemander.
        $this->poster('/caisse/encaisser', ['code_pin' => '4826', ...$this->pinVendeur()]);
        self::assertStringContainsString('Demandez l\'accord du propriétaire', $this->erreur());
        self::assertSame(StatutVente::EnCours, $this->derniereVente()->getStatut());

        $this->accordDuProprietaire();
        $this->encaisser();
        self::assertSame(StatutVente::Validee, $this->derniereVente()->getStatut());
    }

    public function testUnAssocieDonneAussiSonAccord(): void
    {
        $associe = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_PROPRIETAIRE, 'nom' => 'Fanta Coulibaly']);
        AffectationFactory::createOne(['utilisateur' => $associe, 'pharmacie' => $this->officine->pharmacie]);

        $this->demarrerLaVente();
        $this->accordDuProprietaire($associe);
        $this->encaisser();

        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame($associe->getId(), $vente->getAutorisePar()?->getId());
    }

    public function testLeProprietaireQuiVendLuiMemeNAttendPasDAccord(): void
    {
        $this->ouvrirCaisse($this->officine->proprietaire);
        $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getConnection()->executeStatement(
            'UPDATE parametre_pharmacie SET politique_sans_ordonnance = ? WHERE pharmacie_id = ?',
            [PolitiqueSansOrdonnance::Confirmation->value, $this->officine->pharmacie->getId()],
        ));
        $this->ajouter($this->antibiotique);
        $this->client->request('GET', '/caisse');
        self::assertSelectorNotExists('#accord-proprietaire');

        $this->encaisser([], $this->officine->proprietaire);
        $vente = $this->derniereVente();
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertSame($this->officine->proprietaire->getId(), $vente->getAutorisePar()?->getId());
    }

    public function testUnRefusBloqueLaVente(): void
    {
        $this->demarrerLaVente();
        $this->poster('/caisse/accord');

        $this->connecter($this->officine->proprietaire)->request('GET', '/accords');
        $this->client->clickLink('Examiner');
        $this->client->submitForm('Refuser');
        self::assertResponseRedirects('/accords');
        self::assertSame(1, $this->audits('vente.accord_refuse'));

        $this->connecter($this->officine->vendeur)->request('GET', '/caisse');
        self::assertSelectorTextContains('#accord-proprietaire .alert-danger', 'a refusé cette vente');
        $this->encaisser();
        self::assertSame(StatutVente::EnCours, $this->derniereVente()->getStatut());
    }

    public function testSeulLeProprietaireDeLaPharmacieRepond(): void
    {
        $autre = $this->creerOfficine();
        $this->demarrerLaVente();
        $this->poster('/caisse/accord');
        $id = $this->derniereVente()->getId();

        foreach ([$this->officine->vendeur, $this->officine->adjoint] as $membre) {
            $this->connecter($membre)->request('GET', '/accords');
            self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', '/accords/'.$id, ['reponse' => 'donner']);
            self::assertFalse($this->client->getResponse()->isSuccessful());
        }

        $this->connecter($autre->proprietaire)->request('GET', '/accords/'.$id);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->derniereVente()->accordValide());
    }

    /** Le vendeur ouvre la caisse et met au panier un antibiotique, vendu sans ordonnance avec l'accord du propriétaire. */
    private function demarrerLaVente(): void
    {
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getConnection()->executeStatement(
            'UPDATE parametre_pharmacie SET politique_sans_ordonnance = ? WHERE pharmacie_id = ?',
            [PolitiqueSansOrdonnance::Confirmation->value, $this->officine->pharmacie->getId()],
        ));
        $this->ajouter($this->antibiotique);
    }

    /** @return list<Notification> */
    private function notifications(): array
    {
        return $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(Notification::class)->findBy(['pharmacie' => $this->officine->pharmacie, 'type' => TypeNotification::Accord]));
    }

    private function audits(string $action): int
    {
        return $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(JournalAudit::class)->count(['action' => $action]));
    }
}
