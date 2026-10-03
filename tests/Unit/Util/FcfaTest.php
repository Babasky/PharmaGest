<?php

namespace App\Tests\Unit\Util;

use App\Util\Fcfa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FcfaTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float|string|null, string}>
     */
    public static function montants(): iterable
    {
        yield 'zéro' => [0, '0 FCFA'];
        yield 'null' => [null, '0 FCFA'];
        yield 'centaines' => [750, '750 FCFA'];
        yield 'milliers' => [12500, '12 500 FCFA'];
        yield 'millions' => [1250000, '1 250 000 FCFA'];
        yield 'négatif' => [-3500, '-3 500 FCFA'];
        yield 'flottant arrondi au-dessus' => [1249.5, '1 250 FCFA'];
        yield 'flottant arrondi en dessous' => [1249.4, '1 249 FCFA'];
        yield 'chaîne' => ['12500', '12 500 FCFA'];
    }

    #[DataProvider('montants')]
    public function testFormat(int|float|string|null $montant, string $attendu): void
    {
        self::assertSame($attendu, self::espacesNormales(Fcfa::format($montant)));
    }

    public function testFormatSansDevise(): void
    {
        self::assertSame('12 500', self::espacesNormales(Fcfa::format(12500, false)));
    }

    public function testLesEspacesSontInsecables(): void
    {
        self::assertSame("12\u{00A0}500\u{00A0}FCFA", Fcfa::format(12500));
    }

    public function testArrondirAccepteUneSaisieFormatee(): void
    {
        self::assertSame(12500, Fcfa::arrondir("12\u{00A0}500"));
        self::assertSame(13, Fcfa::arrondir('12,5'));
    }

    public function testArrondirRefuseUneSaisieInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Fcfa::arrondir('douze');
    }

    private static function espacesNormales(string $texte): string
    {
        return str_replace("\u{00A0}", ' ', $texte);
    }
}
