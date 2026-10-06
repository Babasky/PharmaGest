<?php

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\InactiviteSessionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Expiration de session après 30 min d'inactivité (§ 6 Sécurité).
 */
final class InactiviteSessionListenerTest extends TestCase
{
    public function testSessionInactiveDepuis31MinutesEstVidee(): void
    {
        $session = $this->sessionUtiliseeIlYA(31 * 60);

        $this->declencher($session);

        self::assertFalse($session->has('_security_main'));
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
    }

    public function testSessionActiveIlYA29MinutesEstConservee(): void
    {
        $session = $this->sessionUtiliseeIlYA(29 * 60);

        $this->declencher($session);

        self::assertTrue($session->has('_security_main'));
    }

    public function testDelaiDeLaCaisseMemoriseEnSession(): void
    {
        $session = $this->sessionUtiliseeIlYA(61 * 60, ['_inactivite_delai' => 7200]);

        $this->declencher($session);

        self::assertTrue($session->has('_security_main'), 'Caisse ouverte avec un délai de 2 h : session conservée.');
    }

    /**
     * @param array<string, mixed> $attributs
     */
    private function sessionUtiliseeIlYA(int $secondes, array $attributs = []): Session
    {
        $stockage = new MockArraySessionStorage();
        $stockage->setSessionData([
            '_sf2_attributes' => ['_security_main' => 'jeton', ...$attributs],
            '_sf2_meta' => ['u' => time() - $secondes, 'c' => time() - 3600, 'l' => 0],
        ]);

        return new Session($stockage);
    }

    private function declencher(Session $session): void
    {
        $requete = new Request();
        $requete->setSession($session);
        $requete->cookies->set($session->getName(), 'id');

        (new InactiviteSessionListener(1800))(new RequestEvent($this->createStub(HttpKernelInterface::class), $requete, HttpKernelInterface::MAIN_REQUEST));
    }
}
