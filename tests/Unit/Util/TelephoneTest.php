<?php

namespace App\Tests\Unit\Util;

use App\Util\Telephone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelephoneTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function saisies(): iterable
    {
        yield '8 chiffres' => ['76123456', '+22376123456'];
        yield 'avec espaces' => ['76 12 34 56', '+22376123456'];
        yield 'avec indicatif' => ['+223 76 12 34 56', '+22376123456'];
        yield 'avec 00' => ['00223 76123456', '+22376123456'];
        yield 'sans +' => ['22376123456', '+22376123456'];
        yield 'trop court' => ['7612345', null];
        yield 'trop long' => ['761234567', null];
        yield 'autre pays' => ['+221 77 123 45 67', null];
        yield 'vide' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('saisies')]
    public function testNormaliser(?string $saisie, ?string $attendu): void
    {
        self::assertSame($attendu, Telephone::normaliser($saisie));
    }

    public function testFormat(): void
    {
        self::assertSame('+223 76 12 34 56', Telephone::format('76123456'));
        self::assertSame('+223 20 22 33 44', Telephone::format('+22320223344'));
    }

    public function testFormatLaisseUneValeurInconnueTelleQuelle(): void
    {
        self::assertSame('123', Telephone::format('123'));
    }
}
