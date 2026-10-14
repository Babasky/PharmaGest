<?php

namespace App\Tests\Functional\Finition;

use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Twig\Environment;

/**
 * Pages d'erreur personnalisées (403, 404, 500 et les autres) : en français, aux couleurs de l'application,
 * avec un lien de retour adapté et le support pour les erreurs techniques.
 */
final class PagesErreurTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Comme en production : sans le mode debug, Symfony affiche les pages d'erreur au lieu de la trace.
        self::ensureKernelShutdown();
        $this->client = self::createClient(['debug' => false]);
    }

    public function testPageIntrouvableSansEtreConnecte(): void
    {
        $this->client->request('GET', '/page-qui-n-existe-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('#erreur-code', '404');
        self::assertSelectorTextContains('h1', 'Page introuvable');
        self::assertSelectorTextContains('#erreur-accueil', 'Se connecter');
        self::assertSelectorNotExists('#sidebar');
        self::assertSelectorNotExists('#erreur-support');
    }

    public function testPageIntrouvableUneFoisConnecte(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->vendeur)->request('GET', '/clients/999999/modifier');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
        self::assertSelectorTextContains('#erreur-accueil', "Retour à l'accueil");
        self::assertSelectorExists('#erreur-support[href="/support"]');
    }

    public function testAccesRefusePourUnRoleInsuffisant(): void
    {
        $officine = $this->creerOfficine();

        $this->connecter($officine->vendeur)->request('GET', '/depenses');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('#erreur-code', '403');
        self::assertSelectorTextContains('h1', 'Accès refusé');
        self::assertSelectorTextContains('#erreur-message', 'propriétaire');
        self::assertSelectorExists('#erreur-accueil[href="/"]');
    }

    public function testLesAutresErreursOntUnePageGenerique(): void
    {
        $this->client->request('GET', '/amo/bordereaux/nouveau');

        self::assertResponseStatusCodeSame(405);
        self::assertSelectorTextContains('#erreur-code', '405');
        self::assertSelectorTextContains('h1', 'Action non autorisée');
    }

    public function testErreurTechniqueProposeLeSupport(): void
    {
        $crawler = $this->rendre('@Twig/Exception/error500.html.twig', 500);

        self::assertSame('Une erreur est survenue', trim($crawler->filter('h1')->text()));
        self::assertCount(1, $crawler->filter('#erreur-canaux #support-canaux'));
        self::assertCount(1, $crawler->filter('#support-telephone'));
        self::assertStringNotContainsString('Exception', $crawler->text(), 'Aucun détail technique ne doit être montré.');
    }

    public function testLesAutresErreursTechniquesProposentAussiLeSupport(): void
    {
        $crawler = $this->rendre('@Twig/Exception/error.html.twig', 503);

        self::assertSame('Service momentanément indisponible', trim($crawler->filter('h1')->text()));
        self::assertCount(1, $crawler->filter('#erreur-canaux'));
    }

    /**
     * Rendu direct du gabarit : aucune page de l'application ne lève volontairement d'erreur 500.
     */
    private function rendre(string $gabarit, int $code): Crawler
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $exception = FlattenException::createFromThrowable(new \RuntimeException('Exception interne'), $code);

        return new Crawler($twig->render($gabarit, [
            'status_code' => $code,
            'status_text' => $exception->getStatusText(),
            'exception' => $exception,
        ]));
    }
}
