<?php

namespace App\Tests\Functional\Amo;

use App\Entity\CreanceAmo;
use App\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tableau de suivi (AM-10), copie de l'ordonnance (AM-01), droits et isolation du module AMO.
 */
final class SuiviEtDroitsAmoTest extends AmoTestCase
{
    public function testEncoursParOrganismeEtAnciennete(): void
    {
        $this->ouvrirCaisse($this->officine->adjoint);
        $ancienne = $this->vendreAmo(2);
        $this->vendreAmo();
        $this->vendreAmo(1, $this->assureCmss);

        // La première vente date de 75 jours.
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($ancienne): void {
            $em->createQuery('UPDATE '.CreanceAmo::class.' c SET c.dateVente = :date WHERE c.vente = :vente')
                ->setParameter('date', new \DateTimeImmutable('today -75 days 10:00'))
                ->setParameter('vente', $ancienne)
                ->execute();
        });

        $crawler = $this->client->request('GET', '/amo');
        self::assertSelectorTextContains('#encours-total', "2\u{00A0}800\u{00A0}FCFA");
        $inps = $crawler->filter('#suivi tr[data-organisme="INPS"] td')->each(static fn ($td) => trim($td->text()));
        self::assertSame(["2\u{00A0}100\u{00A0}FCFA", "700\u{00A0}FCFA", '—', "1\u{00A0}400\u{00A0}FCFA", '—'], \array_slice($inps, 1, 5), 'Encours, puis 0-30, 31-60, 61-90 et + 90 jours.');
        self::assertSelectorTextContains('#suivi tr[data-organisme="CMSS"]', "700\u{00A0}FCFA");
        self::assertSame('INPS', $crawler->filter('#suivi tbody tr')->first()->attr('data-organisme'), 'Le plus gros encours en premier.');

        $this->client->request('GET', '/amo/creances?statut=en_attente&organisme='.$this->cmss->getId());
        self::assertSelectorCount(1, '#creances tbody tr');
        self::assertSelectorTextContains('#creances', 'Oumar Sidibé');
    }

    public function testCopieDeLOrdonnanceDepuisLaFicheDeLaVente(): void
    {
        $this->ouvrirCaisse($this->officine->vendeur);
        $vente = $this->vendreAmo();
        $this->client->request('GET', '/ventes/'.$vente);
        self::assertSelectorTextContains('#copie-ordonnance', 'Aucune copie');

        $texte = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($texte, 'pas une image');
        $this->client->submitForm('Joindre', ['copie' => new UploadedFile($texte, 'ordonnance.txt', 'text/plain', null, true)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'doit être une photo');

        $this->client->submitForm('Joindre', ['copie' => self::photo(800, 600)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Copie de l\'ordonnance enregistrée');
        self::assertSelectorExists('#copie-ordonnance img');
        $copie = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Vente::class, $vente)?->getOrdonnance()?->getCopie());
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}\.jpg$/', (string) $copie);

        // Remplacer la copie supprime l'ancien fichier.
        $this->client->submitForm('Remplacer', ['copie' => self::photo(800, 600)]);
        $nouvelle = $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->find(Vente::class, $vente)?->getOrdonnance()?->getCopie());
        self::assertNotSame($copie, $nouvelle);
        $dossier = ((string) self::getContainer()->getParameter('app.dossier_fichiers')).'/pharmacie-'.$this->officine->pharmacie->getId().'/ordonnances';
        self::assertFileDoesNotExist($dossier.'/'.$copie);
        self::assertFileExists($dossier.'/'.$nouvelle);

        // Copie jointe dès la caisse.
        $this->vendreAmo(1, null, ['ordonnance_copie' => self::photo(600, 800)]);
        self::assertNotNull($this->derniereVente()->getOrdonnance()?->getCopie());
    }

    public function testLeVendeurNAccedePasAuModuleAmoEtLesDonneesRestentDansLeurPharmacie(): void
    {
        $autre = $this->creerOfficine();
        $this->ouvrirCaisse($this->officine->adjoint);
        $vente = $this->vendreAmo(1, null, ['ordonnance_copie' => self::photo(400, 300)]);
        $this->creerBordereau();
        $this->client->followRedirect();
        $bordereau = $this->bordereau();

        $this->connecter($this->officine->vendeur);
        foreach (['/amo', '/amo/creances', '/amo/bordereaux', '/amo/bordereaux/'.$bordereau->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('GET', '/ventes/'.$vente.'/ordonnance');
        self::assertResponseIsSuccessful('Le vendeur voit l\'ordonnance de sa pharmacie.');

        $this->connecter($autre->proprietaire);
        foreach (['/amo/bordereaux/'.$bordereau->getId(), '/amo/bordereaux/'.$bordereau->getId().'/pdf', '/amo/bordereaux/'.$bordereau->getId().'/excel', '/ventes/'.$vente.'/ordonnance'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('GET', '/amo');
        self::assertSelectorTextContains('#encours-total', "0\u{00A0}FCFA");
    }
}
