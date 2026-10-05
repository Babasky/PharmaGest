<?php

namespace App\Tests\Functional\Amo;

use App\Entity\BordereauAmo;
use App\Entity\Client;
use App\Entity\CreanceAmo;
use App\Entity\OrganismeAmo;
use App\Entity\Produit;
use App\Tests\Factory\ClientFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Functional\Caisse\CaisseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Outils des tests AMO : un produit remboursable en stock, des assurés, des ventes AMO encaissées à la caisse.
 */
abstract class AmoTestCase extends CaisseTestCase
{
    protected Produit $remboursable;
    protected OrganismeAmo $inps;
    protected OrganismeAmo $cmss;
    protected Client $assure;
    protected Client $assureCmss;

    protected function setUp(): void
    {
        parent::setUp();
        $p = $this->officine->pharmacie;
        $this->remboursable = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Coartem', 'prixVente' => 1000, 'remboursableAmo' => true]);
        LotFactory::createOne(['produit' => $this->remboursable, 'quantiteInitiale' => 500]);
        [$inps, $cmss] = $this->sansFiltre(static fn (EntityManagerInterface $em) => [
            $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'INPS']),
            $em->getRepository(OrganismeAmo::class)->findOneBy(['code' => 'CMSS']),
        ]);
        self::assertInstanceOf(OrganismeAmo::class, $inps);
        self::assertInstanceOf(OrganismeAmo::class, $cmss);
        [$this->inps, $this->cmss] = [$inps, $cmss];
        $this->assure = ClientFactory::createOne(['pharmacie' => $p, 'nom' => 'Mariam Diallo', 'numeroAssure' => 'INPS-0045871', 'organismeAmo' => $this->inps]);
        $this->assureCmss = ClientFactory::createOne(['pharmacie' => $p, 'nom' => 'Oumar Sidibé', 'numeroAssure' => 'CMSS-1187', 'organismeAmo' => $this->cmss]);
    }

    /**
     * Vente AMO encaissée à la caisse (caisse déjà ouverte par l'utilisateur connecté) ; renvoie l'id de la vente.
     *
     * @param array<string, mixed> $fichiers
     */
    protected function vendreAmo(int $quantite = 1, ?Client $client = null, array $fichiers = []): int
    {
        $this->ajouter($this->remboursable, $quantite);
        $this->poster('/caisse/client', ['client' => ($client ?? $this->assure)->getId()]);
        $this->client->request('POST', '/caisse/vente', [
            '_token' => $this->jeton(), 'type' => 'amo',
            'ordonnance_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'ordonnance_prescripteur' => 'Dr Coulibaly',
        ], $fichiers);
        $this->encaisser(['especes' => ['remis' => (string) (1000 * $quantite)]]);
        self::assertResponseRedirects();

        return (int) $this->derniereVente()->getId();
    }

    protected function creance(int $venteId): CreanceAmo
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($venteId): CreanceAmo {
            $creance = $em->getRepository(CreanceAmo::class)->findOneBy(['vente' => $venteId]);
            self::assertInstanceOf(CreanceAmo::class, $creance);

            return $creance;
        });
    }

    protected function bordereau(?int $id = null): BordereauAmo
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($id): BordereauAmo {
            $bordereau = null === $id ? $em->getRepository(BordereauAmo::class)->findOneBy([], ['id' => 'DESC']) : $em->find(BordereauAmo::class, $id);
            self::assertInstanceOf(BordereauAmo::class, $bordereau);
            $bordereau->getCreances()->toArray();

            return $bordereau;
        });
    }

    /** Crée un brouillon depuis l'écran des bordereaux, pour les ventes du jour. */
    protected function creerBordereau(?OrganismeAmo $organisme = null): void
    {
        $aujourdhui = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->client->request('GET', '/amo/bordereaux');
        $this->client->submitForm('Créer le brouillon', ['organisme' => (string) ($organisme ?? $this->inps)->getId(), 'debut' => $aujourdhui, 'fin' => $aujourdhui]);
    }

    protected function cliquer(string $bouton): void
    {
        $this->client->submitForm($bouton);
        self::assertResponseRedirects();
        $this->client->followRedirect();
    }

    /** Photo d'ordonnance de test (PNG généré). */
    protected static function photo(int $largeur = 2400, int $hauteur = 1800): UploadedFile
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 240, 240, 230));
        $chemin = tempnam(sys_get_temp_dir(), 'ord').'.png';
        imagepng($image, $chemin);

        return new UploadedFile($chemin, 'ordonnance.png', 'image/png', null, true);
    }
}
