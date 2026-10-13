<?php

namespace App\Tests\Functional\Commande;

use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\Notification;
use App\Entity\ParametrePharmacie;
use App\Entity\Produit;
use App\Enum\StatutCommande;
use App\Enum\StatutEnvoi;
use App\Enum\TypeMouvement;
use App\Enum\TypeNotification;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Commandes aux grossistes calculées sur les ventes (tendances, rotation, habitudes d'achat, ventes du jour),
 * transmises en une fois par email, ou automatiquement chaque soir.
 */
final class CommandesGrossistesTest extends CommandeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->sansFiltre(function (EntityManagerInterface $em): void {
            $em->find(Fournisseur::class, $this->ppm->getId())?->setDelaiLivraison(3);
            $em->flush();
        });
    }

    public function testSuggestionsSelonLesTendancesDeVente(): void
    {
        // Doliprane : 30 vendus en 30 jours (1 par jour) → seuil 10 + 1 × (3 jours de livraison + 15 de couverture) = 28 → 24 à commander.
        $this->vendre($this->doliprane, 30, '-5 days');
        // Ibuprofène : 300 vendus (10 par jour), stock 40 au-dessus du seuil mais atteint avant la livraison
        // (seuil 10 + 10 × 3 = 40) → plafonné au stock max 60 → 20 à commander.
        $this->vendre($this->ibuprofene, 300, '-2 days');
        // Smecta : ventes hors période d'analyse → règle du stock maximum (30 − 2).
        $this->vendre($this->smecta, 100, '-60 days');
        // Amoxicilline : sans fournisseur habituel, dernière commande chez Laborex (habitude d'achat).
        $amoxicilline = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'nomCommercial' => 'Amoxicilline', 'prixAchat' => 900, 'seuilAlerte' => 5, 'stockMax' => 20, 'fournisseurHabituel' => null]);
        LotFactory::createOne(['produit' => $amoxicilline, 'quantiteInitiale' => 3]);
        $this->commandeRecue($this->laborex, $amoxicilline, 12, '-20 days');

        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/commandes/suggestions?fournisseur='.$this->ppm->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('#suggestions tbody tr'), 'Doliprane, Coartem et Ibuprofène.');
        self::assertInputValueSame('quantite['.$this->doliprane->getId().']', '24');
        self::assertInputValueSame('quantite['.$this->coartem->getId().']', '10', 'Sans vente : double du seuil.');
        self::assertInputValueSame('quantite['.$this->ibuprofene->getId().']', '20');
        $ibuprofene = $crawler->filter('#suggestions tr[data-produit="Ibuprofène"]')->text();
        self::assertStringContainsString('seuil atteint avant la livraison', $ibuprofene);
        self::assertStringContainsString('Rapide', $ibuprofene);
        self::assertStringContainsString('10,0', $ibuprofene, 'Ventes par jour.');
        self::assertSelectorTextContains('#suggestions tr[data-produit="Coartem"]', 'Aucune vente');
        self::assertSelectorTextContains('#formulaire-suggestions', 'Délai de livraison de PPM : 3 jour(s)');

        $crawler = $this->client->request('GET', '/commandes/suggestions?fournisseur='.$this->laborex->getId());
        self::assertInputValueSame('quantite['.$this->smecta->getId().']', '28', 'Ventes anciennes : stock max − stock.');
        self::assertInputValueSame('quantite['.$amoxicilline->getId().']', '17');
        self::assertStringContainsString('12 le ', $crawler->filter('#suggestions tr[data-produit="Amoxicilline"]')->text(), 'Dernier achat affiché.');
    }

    public function testEnvoiDirectAuxGrossistesApresConfirmation(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/commandes/suggestions');
        self::assertSelectorTextContains('#transmission', '2 grossiste(s)');
        self::assertSelectorTextContains('#transmission', 'Laborex Mali (sans email)');
        self::assertSelectorExists('#transmission [data-turbo-confirm*="à PPM"]', 'Confirmation avant l\'envoi.');

        $this->client->submitForm('Envoyer aux grossistes');
        self::assertResponseRedirects();
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('commandes@ppm.example', $email->getTo()[0]->getAddress());
        $this->client->followRedirect();
        $annee = date('Y');
        self::assertSelectorTextContains('.alert-success', "1 commande(s) envoyée(s) aux grossistes : PPM (CMD-$annee-000001)");
        self::assertSelectorTextContains('.alert-warning', 'Laborex Mali');

        [$ppm, $laborex] = [$this->commandesDe($this->ppm), $this->commandesDe($this->laborex)];
        self::assertCount(1, $ppm);
        self::assertSame(StatutCommande::Envoyee, $ppm[0]->getStatut());
        self::assertSame(StatutEnvoi::Envoye, $ppm[0]->getEnvois()->first()->getStatut());
        self::assertSame(['Coartem' => 10, 'Doliprane' => 56], $this->quantites($ppm[0]));
        self::assertCount(1, $laborex);
        self::assertTrue($laborex[0]->estBrouillon(), 'Fournisseur sans email : brouillon à transmettre autrement.');
        self::assertSame(['Smecta' => 28], $this->quantites($laborex[0]));

        // Une nouvelle préparation complète le brouillon Laborex au lieu d'en créer un second.
        $this->connecter($this->officine->vendeur)->request('GET', '/commandes/suggestions');
        self::assertSelectorNotExists('#transmission [data-turbo-confirm]', 'Le vendeur prépare, il n\'envoie pas.');
        $crawler = $this->client->getCrawler();
        $this->client->request('POST', '/commandes/suggestions/transmettre', ['_token' => $crawler->filter('#transmission input[name=_token]')->attr('value')]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/commandes/suggestions');
        $this->cliquer('Préparer toutes les commandes');
        self::assertSelectorTextContains('.alert-success', 'brouillon(s) prêt(s)');
        self::assertCount(1, $this->commandesDe($this->laborex));
    }

    public function testSuggestionSelonLesVentesDuJour(): void
    {
        $this->vendre($this->doliprane, 3, 'today 09:00');
        $this->vendre($this->ibuprofene, 5, 'today 10:00');
        $this->vendre($this->ibuprofene, 7, 'yesterday 11:00');
        $this->vendre($this->smecta, 40, 'today 12:00');
        $this->annuler($this->doliprane, 1, 'today 15:00');

        $crawler = $this->connecter($this->officine->adjoint)->request('GET', '/commandes/suggestions?base=jour&fournisseur='.$this->ppm->getId());
        self::assertSelectorTextContains('#bases-suggestion .active', 'ventes du jour');
        self::assertCount(2, $crawler->filter('#suggestions tbody tr'));
        self::assertInputValueSame('quantite['.$this->doliprane->getId().']', '2', '3 vendus, 1 annulé.');
        self::assertInputValueSame('quantite['.$this->ibuprofene->getId().']', '5');

        $this->client->request('GET', '/commandes/suggestions?base=jour&fournisseur='.$this->laborex->getId());
        self::assertInputValueSame('quantite['.$this->smecta->getId().']', '28', '40 vendus, plafonné au stock max 30 − stock 2.');

        $hier = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $crawler = $this->client->request('GET', '/commandes/suggestions?base=jour&date='.$hier.'&fournisseur='.$this->ppm->getId());
        self::assertCount(1, $crawler->filter('#suggestions tbody tr'));
        self::assertInputValueSame('quantite['.$this->ibuprofene->getId().']', '7');

        $this->client->submit($crawler->filter('#formulaire-suggestions')->form());
        self::assertResponseRedirects();
        self::assertSame(['Ibuprofène' => 7], $this->quantites($this->commande()));
    }

    public function testEnvoiAutomatiqueALHeureDeChaqueFournisseur(): void
    {
        $this->commandeRecue($this->laborex, $this->smecta, 5, '-10 days');
        // Brouillon en cours de l'adjoint chez PPM : l'envoi automatique n'y touche pas.
        $this->connecter($this->officine->adjoint);
        $brouillon = $this->creerBrouillon($this->ppm, [[$this->doliprane, 4]]);

        // Horaire de commande sur la fiche du fournisseur : PPM à 21 h, tous les jours (aucun jour coché).
        $this->connecter($this->officine->proprietaire)->request('GET', '/fournisseurs/'.$this->ppm->getId().'/modifier');
        $this->client->submitForm('Enregistrer', ['fournisseur[heureCommande]' => '21']);
        self::assertResponseRedirects();
        // Laborex a maintenant un email, commande à 21 h ; son dernier achat de Smecta était de 5 : 28 est inhabituel.
        $this->sansFiltre(function (EntityManagerInterface $em): void {
            $em->find(Fournisseur::class, $this->laborex->getId())?->setEmail('commandes@laborex.example')->setHeureCommande(21)->setJoursCommande([]);
            $em->flush();
        });

        $this->executer(21);
        self::assertCount(0, $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Commande::class)->findBy(['statut' => StatutCommande::Envoyee])), 'Envoi automatique désactivé par défaut.');

        $this->client->request('GET', '/parametres?onglet=regles');
        $this->client->submitForm('Enregistrer les règles', [
            'parametres[envoiAutomatiqueCommandes]' => '1',
            'parametres[joursCouverture]' => '20',
            'parametres[joursAnalyseVentes]' => '60',
        ]);
        self::assertResponseRedirects();
        $parametres = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(ParametrePharmacie::class)->findOneBy(['pharmacie' => $this->officine->pharmacie->getId()]));
        self::assertNotNull($parametres);
        self::assertSame([true, 20, 60], [$parametres->isEnvoiAutomatiqueCommandes(), $parametres->getJoursCouverture(), $parametres->getJoursAnalyseVentes()]);

        self::assertStringContainsString('0 commande(s) envoyée(s)', $this->executer(7), 'Pas l\'heure des fournisseurs.');

        $this->client->request('GET', '/commandes/suggestions?fournisseur='.$this->laborex->getId());
        self::assertSelectorTextContains('#suggestions tr[data-produit="Smecta"]', 'quantité inhabituelle');
        self::assertSelectorTextContains('#formulaire-suggestions', 'Commande automatique à 21 h, tous les jours');

        self::assertStringContainsString('1 commande(s) envoyée(s)', $this->executer(21));
        $commandes = $this->commandesDe($this->ppm);
        self::assertCount(2, $commandes);
        self::assertSame([$brouillon, StatutCommande::Brouillon, ['Doliprane' => 4]], [$commandes[0]->getId(), $commandes[0]->getStatut(), $this->quantites($commandes[0])]);
        self::assertSame(StatutCommande::Envoyee, $commandes[1]->getStatut());
        self::assertSame(['Coartem' => 10, 'Doliprane' => 56], $this->quantites($commandes[1]));
        $laborex = $this->commandesDe($this->laborex);
        self::assertTrue(end($laborex)->estBrouillon(), 'Quantité inhabituelle : la commande attend une vérification.');

        $notifications = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Notification::class)->findBy(['type' => TypeNotification::Commande]));
        self::assertCount(2, $notifications, 'Propriétaire et adjoint prévenus.');
        self::assertStringContainsString('1 commande(s) envoyée(s) automatiquement', $notifications[0]->getMessage());
        self::assertStringContainsString('À vérifier avant envoi, quantité inhabituelle chez Laborex Mali : Smecta', $notifications[0]->getMessage());
    }

    public function testJoursDeCommandeDuFournisseur(): void
    {
        $fournisseur = (new Fournisseur())->setHeureCommande(9)->setJoursCommande([3, 1, 1]);
        self::assertSame([1, 3], $fournisseur->getJoursCommande());
        self::assertTrue($fournisseur->commandeAutomatiquePrevue(new \DateTimeImmutable('2026-10-12 09:00')), 'Lundi 9 h.');
        self::assertFalse($fournisseur->commandeAutomatiquePrevue(new \DateTimeImmutable('2026-10-13 09:00')), 'Mardi.');
        self::assertFalse($fournisseur->commandeAutomatiquePrevue(new \DateTimeImmutable('2026-10-14 10:00')), 'Mercredi, mauvaise heure.');
        self::assertSame([], $fournisseur->setJoursCommande(range(1, 7))->getJoursCommande(), 'Tous les jours.');
    }

    private function executer(int $heure): string
    {
        $this->viderGestionnaire();
        \assert(null !== self::$kernel);
        $commande = new CommandTester((new Application(self::$kernel))->find('app:commandes:automatiques'));
        $commande->execute(['--heure' => (string) $heure]);
        $commande->assertCommandIsSuccessful();

        return $commande->getDisplay(true);
    }

    /** Sortie de vente enregistrée sur un lot du produit (le stock des lots n'est pas modifié). */
    private function vendre(Produit $produit, int $quantite, string $quand, TypeMouvement $type = TypeMouvement::Vente): void
    {
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($produit, $quantite, $quand, $type): void {
            $lot = $em->getRepository(Lot::class)->findOneBy(['produit' => $produit->getId()]);
            self::assertInstanceOf(Lot::class, $lot);
            $em->persist(new MouvementStock($lot, $type, TypeMouvement::Vente === $type ? -$quantite : $quantite, new \DateTimeImmutable($quand)));
            $em->flush();
        });
    }

    private function annuler(Produit $produit, int $quantite, string $quand): void
    {
        $this->vendre($produit, $quantite, $quand, TypeMouvement::Annulation);
    }

    private function commandeRecue(Fournisseur $fournisseur, Produit $produit, int $quantite, string $quand): void
    {
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($fournisseur, $produit, $quantite, $quand): void {
            $le = new \DateTimeImmutable($quand);
            $f = $em->find(Fournisseur::class, $fournisseur->getId()) ?? throw new \LogicException();
            $commande = (new Commande($f, null, $le))->setPharmacie($f->getPharmacie() ?? throw new \LogicException());
            $commande->ajouterLigne($em->find(Produit::class, $produit->getId()) ?? throw new \LogicException(), $quantite, 900);
            $commande->passer('CMD-TEST-'.$produit->getId(), $le, null);
            $commande->cloturer(StatutCommande::Recue, 'Reçue', $le);
            $em->persist($commande);
            $em->flush();
        });
    }

    /**
     * Commandes d'un fournisseur, de la plus ancienne à la plus récente, avec leurs lignes et envois chargés.
     *
     * @return list<Commande>
     */
    private function commandesDe(Fournisseur $fournisseur): array
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($fournisseur): array {
            $commandes = $em->getRepository(Commande::class)->findBy(['fournisseur' => $fournisseur->getId()], ['id' => 'ASC']);
            foreach ($commandes as $commande) {
                $commande->getEnvois()->toArray();
                foreach ($commande->getLignes() as $ligne) {
                    $ligne->getProduit()->getNomCommercial();
                }
            }

            return $commandes;
        });
    }

    /**
     * @return array<string, int> quantité par produit
     */
    private function quantites(Commande $commande): array
    {
        $quantites = [];
        foreach ($commande->getLignes() as $ligne) {
            $quantites[$ligne->getProduit()->getNomCommercial()] = $ligne->getQuantite();
        }
        ksort($quantites);

        return $quantites;
    }
}
