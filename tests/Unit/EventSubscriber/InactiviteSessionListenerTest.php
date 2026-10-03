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

    private function sessionUtiliseeIlYA(int $secondes): Session
    {
        $stockage = new MockArraySessionStorage();
        $stockage->setSessionData([
            '_sf2_attributes' => ['_security_main' => 'jeton'],
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
