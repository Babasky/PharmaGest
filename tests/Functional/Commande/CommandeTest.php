<?php

namespace App\Tests\Functional\Commande;

use App\Entity\EnvoiCommande;
use App\Entity\JournalAudit;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Enum\StatutCommande;
use App\Enum\StatutEnvoi;
use App\Enum\TypeMouvement;
use App\Tests\Factory\FournisseurFactory;
use App\Tests\Factory\ProduitFactory;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\Mime\Email;

/**
 * Commandes fournisseurs : suggestion (CO-02), brouillon (CO-01), statuts (CO-03), Excel (CO-04), envoi par email
 * (CO-05) et réception (CO-06, RG-16) ; scénarios de recette R-09 et R-10.
 */
final class CommandeTest extends CommandeTestCase
{
    public function testR09CommandeGenereeDepuisLesSuggestionsPuisEnvoyee(): void
    {
        $this->connecter($this->officine->adjoint)->request('GET', '/commandes');
        self::assertSelectorTextContains('#lien-suggestions .badge', '3', 'Doliprane, Coartem (PPM) et Smecta (Laborex).');

        $this->client->clickLink('Suggestions');
        self::assertSelectorTextContains('#fournisseurs-suggeres .active', 'Laborex Mali', 'Premier fournisseur par ordre alphabétique.');
        $crawler = $this->client->clickLink('PPM');
        self::assertSelectorTextContains('#fournisseurs-suggeres .active', 'PPM');
        self::assertCount(2, $crawler->filter('#suggestions tbody tr'), 'Ibuprofène est au-dessus du seuil.');
        self::assertInputValueSame('quantite['.$this->doliprane->getId().']', '56', 'Stock max 60 − stock 4.');
        self::assertInputValueSame('quantite['.$this->coartem->getId().']', '10', 'Sans stock max : double du seuil.');

        $formulaire = $crawler->filter('#formulaire-suggestions')->form();
        $formulaire['quantite['.$this->doliprane->getId().']'] = '50';
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '2 produit(s) suggéré(s)');
        self::assertSelectorTextContains('#statut', 'Brouillon');
        self::assertSelectorTextContains('#montant', "86\u{00A0}500\u{00A0}FCFA", '50 × 1 150 + 10 × 2 900.');

        $this->client->submitForm('Envoyer par email');
        $annee = date('Y');

        // R-09 : email reçu par le fournisseur avec l'Excel joint.
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('commandes@ppm.example', $email->getTo()[0]->getAddress());
        self::assertSame('contact@fleuve.example', $email->getReplyTo()[0]->getAddress(), 'Le fournisseur répond à la pharmacie.');
        self::assertStringContainsString("CMD-$annee-000001", (string) $email->getSubject());
        self::assertStringContainsString('Doliprane', (string) $email->getHtmlBody());
        $pieces = $email->getAttachments();
        self::assertCount(1, $pieces);
        self::assertSame("bon-de-commande-CMD-$annee-000001.xlsx", $pieces[0]->getFilename());

        $feuille = $this->feuille($pieces[0]->getBody());
        $texte = $this->texte($feuille);
        foreach (['Pharmacie du Fleuve', 'PPM', 'Service commandes', "N° CMD-$annee-000001", 'BON DE COMMANDE', 'Doliprane — Paracétamol 500 mg', '3400930000011', 'Boîte de 16'] as $attendu) {
            self::assertStringContainsString($attendu, $texte);
        }
        self::assertStringNotContainsString('BROUILLON', $texte);
        self::assertSame(86500, (int) $feuille->getCell('G'.$this->ligneTotal($feuille))->getCalculatedValue(), 'Total du bon de commande.');

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', "Commande CMD-$annee-000001 envoyée à commandes@ppm.example");
        self::assertSelectorTextContains('#statut', 'Envoyée');
        self::assertSelectorTextContains('#envois', 'Envoyé');

        $commande = $this->commande();
        self::assertSame([StatutCommande::Envoyee, "CMD-$annee-000001"], [$commande->getStatut(), $commande->getNumero()]);
        self::assertSame([StatutEnvoi::Envoye, 'commandes@ppm.example'], [$commande->getEnvois()->first()->getStatut(), $commande->getEnvois()->first()->getDestinataire()]);

        // Ce qui est commandé n'est plus suggéré.
        $this->client->request('GET', '/commandes/suggestions');
        self::assertSelectorTextContains('#fournisseurs-suggeres .active', 'Laborex Mali', 'Plus rien à commander chez PPM.');
    }

    public function testR10ReceptionPartiellePuisComplete(): void
    {
        $this->connecter($this->officine->adjoint);
        $id = $this->creerBrouillon($this->ppm, [[$this->doliprane, 50], [$this->coartem, 20]]);
        $this->client->request('GET', '/commandes/'.$id);
        $this->cliquer('Passer sans email');
        $annee = date('Y');
        self::assertSelectorTextContains('#statut', 'Envoyée');
        $numero = "CMD-$annee-000001";

        // Première livraison : 30 Doliprane seulement.
        $crawler = $this->client->clickLink('Réceptionner');
        self::assertInputValueSame('lignes[0][quantite]', '50', 'Le reste attendu est proposé.');
        $formulaire = $crawler->filter('#reception')->form();
        $formulaire['bon_livraison'] = 'BL-7781';
        $formulaire['lignes[0][quantite]'] = '30';
        $formulaire['lignes[0][lot]'] = 'DP2611';
        $formulaire['lignes[0][peremption]'] = (new \DateTimeImmutable('today +2 years'))->format('Y-m-d');
        $formulaire['lignes[0][prix]'] = '1100';
        $formulaire['lignes[1][quantite]'] = '';
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'reçue partiellement');
        self::assertSelectorTextContains('#statut', 'Reçue partiellement');
        self::assertSelectorTextContains('#receptions', 'DP2611');

        // R-10 : lot créé avec le prix réel, stock mis à jour par un mouvement « Réception ».
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($numero): void {
            $lot = $em->getRepository(Lot::class)->findOneBy(['numero' => 'DP2611']);
            self::assertInstanceOf(Lot::class, $lot);
            self::assertSame([30, 30, 1100, 'PPM'], [$lot->getQuantiteInitiale(), $lot->getQuantiteRestante(), $lot->getPrixAchat(), $lot->getFournisseur()?->getNom()]);
            $mouvement = $em->getRepository(MouvementStock::class)->findOneBy(['lot' => $lot]);
            self::assertSame([TypeMouvement::Reception, 30, $numero, 'Réception, BL BL-7781'], [$mouvement?->getType(), $mouvement?->getQuantite(), $mouvement?->getDocument(), $mouvement?->getMotif()]);
        });
        $this->client->request('GET', '/produits/'.$this->doliprane->getId());
        self::assertSelectorTextContains('main', 'DP2611');

        // Seconde livraison : le reste du Doliprane avec 5 de plus (RG-16), le Coartem en deux lots.
        $crawler = $this->client->request('GET', '/commandes/'.$id.'/reception');
        $formulaire = $crawler->filter('#reception')->form();
        $peremption = (new \DateTimeImmutable('today +18 months'))->format('Y-m-d');
        $valeurs = $formulaire->getPhpValues();
        $valeurs['lignes'] = [
            ['ligne' => $valeurs['lignes'][0]['ligne'], 'quantite' => '25', 'lot' => 'DP2612', 'peremption' => $peremption, 'prix' => '1150'],
            ['ligne' => $valeurs['lignes'][1]['ligne'], 'quantite' => '12', 'lot' => 'CO118', 'peremption' => $peremption, 'prix' => '2900'],
            ['ligne' => $valeurs['lignes'][1]['ligne'], 'quantite' => '8', 'lot' => 'CO119', 'peremption' => $peremption, 'prix' => '2900'],
        ];
        $this->client->request('POST', $formulaire->getUri(), $valeurs);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'Doliprane : 55 reçu(s) pour 50 commandé(s), soit 5 de plus');
        self::assertSelectorTextContains('#statut', 'Reçue');
        self::assertSelectorNotExists('a[href$="/reception"]');

        $commande = $this->commande($id);
        self::assertSame(StatutCommande::Recue, $commande->getStatut());
        self::assertCount(2, $commande->getReceptions());
        self::assertSame([55, 20], [$commande->getLignes()[0]->getQuantiteRecue(), $commande->getLignes()[1]->getQuantiteRecue()]);
        self::assertSame(20, $this->sansFiltre(fn (EntityManagerInterface $em) => (int) $em->createQuery('SELECT SUM(l.quantiteRestante) FROM '.Lot::class.' l WHERE l.produit = :p')->setParameter('p', $this->coartem->getId())->getSingleScalarResult()));
    }

    public function testBrouillonModifiableJusquAuPassageEtNumerotationSansTrou(): void
    {
        $this->connecter($this->officine->adjoint);
        $premier = $this->creerBrouillon($this->ppm, [[$this->doliprane, 5]]);
        $id = $this->creerBrouillon($this->ppm, [[$this->doliprane, 5], [$this->doliprane, 3], [$this->coartem, 4]]);
        self::assertSame(8, $this->commande($id)->getLignes()[0]->getQuantite(), 'Ajouter deux fois le même produit cumule la quantité.');

        // Quantités et prix estimés ; une quantité à 0 retire la ligne.
        $crawler = $this->client->request('GET', '/commandes/'.$id);
        $lignes = $this->commande($id)->getLignes();
        $formulaire = $crawler->filter('#lignes-commande')->form();
        $formulaire['quantite['.$lignes[0]->getId().']'] = '12';
        $formulaire['prix['.$lignes[0]->getId().']'] = '1 200';
        $formulaire['quantite['.$lignes[1]->getId().']'] = '0';
        $this->client->submit($formulaire);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#montant', "14\u{00A0}400\u{00A0}FCFA");
        self::assertCount(1, $this->commande($id)->getLignes());

        // Le premier brouillon est supprimé : pas de trou dans les numéros (RG-02).
        $this->client->request('GET', '/commandes/'.$premier);
        $this->cliquer('Supprimer le brouillon');
        $this->client->request('GET', '/commandes/'.$id);
        $this->cliquer('Passer sans email');
        $annee = date('Y');
        self::assertSelectorTextContains('.alert-success', "Commande CMD-$annee-000001 passée sans email");

        // Passée, la commande n'est plus modifiable.
        self::assertSelectorNotExists('#lignes-commande');
        $jeton = (string) $this->client->getCrawler()->filter('form[action$="/envoyer"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/commandes/'.$id.'/ajouter', ['_token' => $jeton, 'produit' => $this->coartem->getId(), 'quantite' => 1]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', "La commande CMD-$annee-000001 est passée : elle n'est plus modifiable.");
        self::assertCount(1, $this->commande($id)->getLignes());

        // Bon de commande Excel téléchargeable pour un envoi par WhatsApp.
        $this->client->request('GET', '/commandes/'.$id.'/excel');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString("CMD-$annee-000001", $this->texte($this->feuille((string) $this->client->getInternalResponse()->getContent())));
    }

    public function testFournisseurSansEmailEtBrouillonExporte(): void
    {
        $this->connecter($this->officine->proprietaire);
        $id = $this->creerBrouillon($this->laborex, [[$this->smecta, 28]]);
        $this->client->request('GET', '/commandes/'.$id);
        self::assertSelectorExists('button[disabled]:contains("Envoyer par email")');
        $jeton = (string) $this->client->getCrawler()->filter('form[action$="/envoyer"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/commandes/'.$id.'/envoyer', ['_token' => $jeton]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Laborex Mali n\'a pas d\'adresse email');
        self::assertSame(StatutCommande::Brouillon, $this->commande($id)->getStatut());
        self::assertEmailCount(0);

        $this->client->request('GET', '/commandes/'.$id.'/excel');
        self::assertStringContainsString('BON DE COMMANDE — BROUILLON', $this->texte($this->feuille((string) $this->client->getInternalResponse()->getContent())));
    }

    public function testAnnulationEtAbandonDuReliquatTraces(): void
    {
        $this->connecter($this->officine->adjoint);
        $annulee = $this->creerBrouillon($this->ppm, [[$this->coartem, 10]]);
        $this->client->request('GET', '/commandes/'.$annulee);
        $this->cliquer('Envoyer par email');
        $this->cliquer('Annuler la commande', ['motif' => 'Rupture chez le fournisseur']);
        self::assertSelectorTextContains('#statut', 'Annulée');
        self::assertSelectorTextContains('#cloture', 'Rupture chez le fournisseur');
        self::assertSelectorNotExists('a[href$="/reception"]');

        $id = $this->creerBrouillon($this->ppm, [[$this->doliprane, 50]]);
        $this->client->request('GET', '/commandes/'.$id);
        $this->cliquer('Passer sans email');
        $crawler = $this->client->clickLink('Réceptionner');
        $formulaire = $crawler->filter('#reception')->form([
            'lignes[0][quantite]' => '20', 'lignes[0][lot]' => 'DP1', 'lignes[0][peremption]' => (new \DateTimeImmutable('today +1 year'))->format('Y-m-d'),
        ]);
        $this->client->submit($formulaire);
        $this->client->followRedirect();
        $this->cliquer('Solder la commande', ['motif' => 'Le reste ne sera pas livré']);
        self::assertSelectorTextContains('#statut', 'Reçue');
        self::assertSelectorTextContains('#cloture', 'Reliquat abandonné');

        $actions = $this->sansFiltre(static fn (EntityManagerInterface $em) => array_map(
            static fn (JournalAudit $j) => [$j->getAction(), $j->getApres()['unites_non_livrees'] ?? null],
            $em->getRepository(JournalAudit::class)->findBy(['action' => ['commande.annulee', 'commande.soldee']], ['id' => 'ASC']),
        ));
        self::assertSame([['commande.annulee', 10], ['commande.soldee', 30]], $actions);

        // Le reliquat abandonné n'est plus attendu : le Doliprane (stock 24) n'est plus sous le seuil, le Coartem est de nouveau suggéré.
        $this->client->request('GET', '/commandes/suggestions', ['fournisseur' => $this->ppm->getId()]);
        self::assertSelectorTextContains('#suggestions', 'Coartem');
        self::assertSelectorTextNotContains('#suggestions', 'Doliprane');
    }

    public function testReceptionRefuseeSansLotNiPeremptionValide(): void
    {
        $this->connecter($this->officine->adjoint);
        $id = $this->creerBrouillon($this->ppm, [[$this->doliprane, 10], [$this->coartem, 5]]);
        $this->client->request('GET', '/commandes/'.$id);
        $this->cliquer('Passer sans email');
        $peremption = (new \DateTimeImmutable('today +1 year'))->format('Y-m-d');

        $this->client->clickLink('Réceptionner');
        $this->client->submitForm('Enregistrer la réception', [
            'lignes[0][lot]' => 'DP1', 'lignes[0][peremption]' => $peremption,
            'lignes[1][lot]' => '', 'lignes[1][peremption]' => $peremption,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#erreur', 'Coartem : le numéro de lot est obligatoire');
        self::assertInputValueSame('lignes[0][lot]', 'DP1', 'La saisie est conservée.');

        $this->client->submitForm('Enregistrer la réception', [
            'lignes[1][lot]' => 'CO1', 'lignes[1][peremption]' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#erreur', 'Coartem : le lot CO1 est déjà périmé');

        $this->client->submitForm('Enregistrer la réception', ['date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'), 'lignes[1][peremption]' => $peremption]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#erreur', 'ne peut pas être dans le futur');

        self::assertCount(0, $this->commande($id)->getReceptions(), 'Rien n\'est enregistré.');
        self::assertNull($this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(Lot::class)->findOneBy(['numero' => 'DP1'])));
    }

    public function testLeVendeurPrepareUnBrouillonSansVoirLesPrix(): void
    {
        $this->connecter($this->officine->vendeur);
        $id = $this->creerBrouillon($this->ppm, [[$this->doliprane, 20]]);
        $this->client->request('GET', '/commandes/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#montant');
        self::assertSelectorNotExists('input[name^="prix["]');
        self::assertSelectorTextNotContains('main', '1 150');
        self::assertSelectorNotExists('form[action$="/envoyer"]');
        self::assertSelectorNotExists('a[href$="/excel"]');
        self::assertSelectorTextContains('main', 'Le propriétaire ou l\'adjoint vérifiera les prix');

        // Le prix envoyé par un vendeur est ignoré.
        $ligne = $this->commande($id)->getLignes()[0];
        $jeton = (string) $this->client->getCrawler()->filter('#lignes-commande input[name="_token"]')->attr('value');
        $this->client->request('POST', '/commandes/'.$id.'/lignes', ['_token' => $jeton, 'quantite' => [$ligne->getId() => '25'], 'prix' => [$ligne->getId() => '1']]);
        self::assertSame([25, 1150], [$this->commande($id)->getLignes()[0]->getQuantite(), $this->commande($id)->getLignes()[0]->getPrixEstime()]);

        foreach (['/commandes/'.$id.'/excel', '/commandes/'.$id.'/reception'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        foreach (['envoyer', 'passer', 'annuler', 'solder'] as $action) {
            $this->client->request('POST', '/commandes/'.$id.'/'.$action, ['_token' => $jeton]);
            self::assertResponseStatusCodeSame(403, $action);
        }
        self::assertSame(StatutCommande::Brouillon, $this->commande($id)->getStatut());
    }

    public function testUneCommandeDUneAutrePharmacieEstIntrouvable(): void
    {
        $autre = $this->creerOfficine();
        $fournisseur = FournisseurFactory::createOne(['pharmacie' => $autre->pharmacie, 'email' => 'x@autre.example']);
        $produitAutre = ProduitFactory::createOne(['pharmacie' => $autre->pharmacie]);

        $this->connecter($autre->adjoint);
        $id = $this->creerBrouillon($fournisseur, [[$produitAutre, 3]]);

        $this->connecter($this->officine->proprietaire)->request('GET', '/commandes/'.$id);
        self::assertResponseStatusCodeSame(404);
        foreach (['/commandes/'.$id.'/excel', '/commandes/'.$id.'/reception'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('GET', '/commandes');
        self::assertSelectorTextNotContains('#commandes', $fournisseur->getNom());

        // Un produit d'une autre pharmacie ne peut pas être ajouté à un brouillon.
        $mien = $this->creerBrouillon($this->ppm, []);
        $crawler = $this->client->request('GET', '/commandes/'.$mien, ['q' => 'Doliprane']);
        $jeton = (string) $crawler->filter('#resultats input[name="_token"]')->attr('value');
        $this->client->request('POST', '/commandes/'.$mien.'/ajouter', ['_token' => $jeton, 'produit' => $produitAutre->getId(), 'quantite' => 1]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Choisissez un produit');
        self::assertCount(0, $this->commande($mien)->getLignes());
        self::assertCount(0, $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getRepository(EnvoiCommande::class)->findAll()));
    }

    private function feuille(string $contenu): Worksheet
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($chemin, $contenu);
        try {
            return IOFactory::load($chemin)->getActiveSheet();
        } finally {
            unlink($chemin);
        }
    }

    private function texte(Worksheet $feuille): string
    {
        $texte = '';
        foreach ($feuille->toArray(null, false, false) as $ligne) {
            $texte .= implode(' | ', array_map(static fn ($v) => (string) $v, $ligne))."\n";
        }

        return $texte;
    }

    private function ligneTotal(Worksheet $feuille): int
    {
        foreach ($feuille->getRowIterator() as $ligne) {
            if ('Total' === $feuille->getCell('A'.$ligne->getRowIndex())->getValue()) {
                return $ligne->getRowIndex();
            }
        }
        self::fail('Ligne de total introuvable.');
    }
}
