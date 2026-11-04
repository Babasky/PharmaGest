<?php

namespace App\Tests\Functional;

use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Demande du 24/10/2026 : chaque champ de saisie des formulaires a un texte indicatif (placeholder), dans l'espace
 * pharmacie comme dans l'espace plateforme. Les champs écrits dans les gabarits sont vérifiés par
 * tests/Unit/Gabarit/PlaceholdersTest.
 */
final class PlaceholdersTest extends AppWebTestCase
{
    /** Types sans zone de texte libre, où le navigateur n'affiche pas de placeholder. */
    private const TYPES_SANS_SAISIE = ['hidden', 'checkbox', 'radio', 'submit', 'button', 'reset', 'image', 'file', 'date', 'datetime-local', 'month', 'week', 'time', 'color', 'range'];

    public function testPagesPubliques(): void
    {
        foreach (['/connexion', '/mot-de-passe-oublie'] as $page) {
            $this->assertChampsAvecPlaceholder($this->client->request('GET', $page), $page);
        }
    }

    public function testFormulairesDeLaPharmacie(): void
    {
        $officine = $this->creerOfficine();
        $produit = ProduitFactory::createOne(['pharmacie' => $officine->pharmacie]);
        LotFactory::createOne(['produit' => $produit, 'quantiteInitiale' => 5]);
        $this->connecter($officine->proprietaire);

        $pages = [
            '/produits/nouveau', '/produits/'.$produit->getId().'/modifier', '/stock/produit/'.$produit->getId().'/entree',
            '/clients/nouveau', '/fournisseurs/nouveau', '/depenses/nouvelle', '/categories', '/etageres', '/entrepots',
            '/equipe/nouveau', '/equipe/associe', '/parametres', '/mon-code-pin',
        ];
        foreach ($pages as $page) {
            $this->assertChampsAvecPlaceholder($this->client->request('GET', $page), $page);
        }
    }

    public function testFormulairesDeLaPlateforme(): void
    {
        $officine = $this->creerOfficine();
        $this->connecter($this->creerSuperAdmin());

        $pages = [
            '/admin/pharmacies/nouvelle', '/admin/pharmacies/'.$officine->pharmacie->getId().'/edit',
            '/admin/offres/'.$officine->pharmacie->getOffre()->getId().'/edit', '/admin/offres/new', '/admin/referentiels/organismes-amo/new', '/admin/referentiels/formes-galeniques/new',
            '/admin/referentiels/categories-depenses/new', '/admin/super-admins/nouveau', '/admin/support',
        ];
        foreach ($pages as $page) {
            $this->assertChampsAvecPlaceholder($this->client->request('GET', $page), $page);
        }
    }

    private function assertChampsAvecPlaceholder(Crawler $crawler, string $page): void
    {
        self::assertResponseIsSuccessful($page);
        $manquants = [];
        $champs = 0;
        foreach ($crawler->filter('form input, form textarea') as $champ) {
            \assert($champ instanceof \DOMElement);
            if (\in_array($champ->getAttribute('type'), self::TYPES_SANS_SAISIE, true)) {
                continue;
            }
            ++$champs;
            if ('' === trim($champ->getAttribute('placeholder'))) {
                $manquants[] = $champ->getAttribute('name');
            }
        }

        self::assertGreaterThan(0, $champs, $page);
        self::assertSame([], $manquants, 'Champs sans placeholder sur '.$page);
    }
}
