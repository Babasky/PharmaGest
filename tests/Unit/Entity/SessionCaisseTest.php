<?php

namespace App\Tests\Unit\Entity;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use PHPUnit\Framework\TestCase;

/**
 * Comptage de clôture (rapport Z) : les coupures se lisent de la plus grosse à la plus petite.
 */
final class SessionCaisseTest extends TestCase
{
    public function testLeComptageSuitLOrdreDesCoupuresMemeSiLaBaseLesAReordonnees(): void
    {
        $session = new SessionCaisse('SC-2026-000001', new Utilisateur(), new \DateTimeImmutable('2026-10-11 08:00'), 0);
        // Ordre rendu par une colonne JSON MySQL : clés triées par longueur puis alphabet.
        $session->cloturer(new \DateTimeImmutable('2026-10-11 20:00'), null, ['p5' => 1, 'p500' => 4, 'b5000' => 1, 'b10000' => 2], 27005, null);

        self::assertSame(['b10000' => 2, 'b5000' => 1, 'p500' => 4, 'p5' => 1], $session->getComptage());
        self::assertSame(27005, $session->getEspecesComptees());
    }
}
