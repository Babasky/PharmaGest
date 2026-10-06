<?php

namespace App\Tests\Functional\Finition;

use App\Entity\JournalAudit;
use App\Entity\Pharmacie;
use App\Stockage\StockageFichiers;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Réversibilité (§ 6) : export complet des données par le propriétaire, même en lecture seule ;
 * archivage automatique 12 mois après l'échéance, annoncé 30 jours avant, avec l'export envoyé (§ 3.2).
 */
final class ExportEtArchivageTest extends AppWebTestCase
{
    public function testLeProprietaireExporteToutesSesDonneesMemeEnLectureSeule(): void
    {
        $officine = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->echue(20));
        $autre = $this->creerOfficine();
        ProduitFactory::createOne(['pharmacie' => $officine->pharmacie, 'nomCommercial' => 'Doliprane']);
        ProduitFactory::createOne(['pharmacie' => $officine->pharmacie, 'nomCommercial' => '=HYPERLINK("http://exemple.ml")']);
        ProduitFactory::createOne(['pharmacie' => $autre->pharmacie, 'nomCommercial' => 'Secretol']);
        /** @var StockageFichiers $stockage */
        $stockage = self::getContainer()->get(StockageFichiers::class);
        $logo = $stockage->ecrire($officine->pharmacie, 'logo', 'PNG', 'png');

        $this->connecter($officine->adjoint)->request('GET', '/abonnement/export');
        self::assertResponseStatusCodeSame(403);

        $this->connecter($officine->proprietaire)->request('GET', '/abonnement');
        self::assertSelectorExists('#export-donnees a[href="/abonnement/export"]');
        $this->client->request('GET', '/abonnement/export');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/zip');
        self::assertStringContainsString('attachment; filename=pharmagest-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $zip = $this->ouvrir((string) $this->client->getInternalResponse()->getContent());
        $noms = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $noms[] = (string) $zip->getNameIndex($i);
        }
        foreach (['LISEZMOI.txt', 'donnees/pharmacie.csv', 'donnees/equipe.csv', 'donnees/produit.csv', 'donnees/vente.csv', 'donnees/journal_audit.csv', 'donnees/referentiel_organisme_amo.csv', 'fichiers/logo/'.$logo] as $attendu) {
            self::assertContains($attendu, $noms);
        }
        self::assertNotContains('donnees/utilisateur.csv', $noms, 'Aucun mot de passe ni code PIN exporté.');
        $produits = (string) $zip->getFromName('donnees/produit.csv');
        self::assertStringStartsWith("\u{FEFF}id;", $produits);
        self::assertStringContainsString('Doliprane', $produits);
        self::assertStringContainsString("'=HYPERLINK", $produits, 'Injection de formule neutralisée.');
        self::assertStringNotContainsString('Secretol', $produits, 'Aucune donnée d\'une autre pharmacie.');
        self::assertStringContainsString($officine->vendeur->getEmail(), (string) $zip->getFromName('donnees/equipe.csv'));
        self::assertStringNotContainsString('password', (string) $zip->getFromName('donnees/equipe.csv'));
        self::assertStringContainsString('donnees/produit.csv : 2 ligne(s)', (string) $zip->getFromName('LISEZMOI.txt'));

        $actions = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->createQuery('SELECT j.action FROM '.JournalAudit::class.' j')->getSingleColumnResult());
        self::assertContains('donnees.exportees', $actions);
    }

    public function testArchivageAutomatiqueAnnonceePuisRealiseeAvecExport(): void
    {
        $bientot = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->with(['nom' => 'Pharmacie Bientôt', 'finAbonnement' => new \DateTimeImmutable('today -12 months +20 days')]));
        $ancienne = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->with(['nom' => 'Pharmacie Ancienne', 'finAbonnement' => new \DateTimeImmutable('today -13 months')]));
        $this->creerOfficine(static fn (PharmacieFactory $f) => $f->echue(20));
        $suspendue = $this->creerOfficine(static fn (PharmacieFactory $f) => $f->with(['finAbonnement' => new \DateTimeImmutable('today -14 months')]));
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($suspendue): void {
            $em->find(Pharmacie::class, $suspendue->pharmacie->getId())?->suspendre('Litige');
            $em->flush();
        });
        ProduitFactory::createOne(['pharmacie' => $ancienne->pharmacie, 'nomCommercial' => 'Doliprane']);

        $sortie = $this->archiver();
        self::assertStringContainsString('1 pharmacie(s) archivée(s), 1 archivage(s) annoncé(s)', $sortie);

        $emails = self::getMailerMessages();
        self::assertCount(2, $emails);
        [$annonce, $export] = $emails;
        self::assertInstanceOf(Email::class, $annonce);
        self::assertInstanceOf(Email::class, $export);
        self::assertSame($bientot->proprietaire->getEmail(), $annonce->getTo()[0]->getAddress());
        self::assertStringContainsString('Pharmacie Bientôt sera archivée le', (string) $annonce->getSubject());
        self::assertSame($ancienne->proprietaire->getEmail(), $export->getTo()[0]->getAddress());
        $pieces = $export->getAttachments();
        self::assertCount(1, $pieces);
        self::assertStringStartsWith('pharmagest-pharmacie-ancienne-', (string) $pieces[0]->getFilename());
        self::assertStringContainsString('Doliprane', (string) $this->ouvrir($pieces[0]->getBody())->getFromName('donnees/produit.csv'));

        $etats = $this->sansFiltre(static fn (EntityManagerInterface $em) => array_map(
            static fn (Pharmacie $p) => $p->isArchivee(),
            [$em->find(Pharmacie::class, $bientot->pharmacie->getId()), $em->find(Pharmacie::class, $ancienne->pharmacie->getId()), $em->find(Pharmacie::class, $suspendue->pharmacie->getId())],
        ));
        self::assertSame([false, true, false], $etats);

        // Le lendemain : l'annonce n'est pas répétée.
        self::assertStringContainsString('0 pharmacie(s) archivée(s), 0 archivage(s) annoncé(s)', $this->archiver());
    }

    private function archiver(): string
    {
        $this->viderGestionnaire();
        \assert(null !== self::$kernel);
        $commande = new CommandTester((new Application(self::$kernel))->find('app:pharmacies:archiver'));
        $commande->execute([]);
        $commande->assertCommandIsSuccessful();

        return $commande->getDisplay(true);
    }

    private function ouvrir(string $contenu): \ZipArchive
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($chemin, $contenu);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($chemin));

        return $zip;
    }
}
