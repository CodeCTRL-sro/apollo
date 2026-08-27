<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Utility;

use CodeCTRL\Apollo\Utility\Utils\StringUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringUtils::class)]
final class StringUtilsTest extends TestCase
{
    /**
     * The switch to random_int() must not change the returned length: callers size
     * database columns around it.
     */
    public function testGeneratedStringKeepsItsHistoricalLength(): void
    {
        // half + time() (10 digits) + half
        self::assertSame(30, strlen(StringUtils::generateRandomString(20)));
        self::assertSame(20, strlen(StringUtils::generateRandomString(10)));
        self::assertSame(10, strlen(StringUtils::generateRandomString(0)));
    }

    public function testGeneratedStringUsesTheHistoricalAlphabet(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-z]+$/', StringUtils::generateRandomString(20));
    }

    public function testGeneratedStringsDoNotRepeat(): void
    {
        $seen = array();
        for ($i = 0; $i < 200; $i++) {
            $seen[StringUtils::generateRandomString(20)] = true;
        }

        self::assertCount(200, $seen);
    }

    public function testSecureTokenLengthAndAlphabet(): void
    {
        self::assertSame(64, strlen(StringUtils::secureToken()));
        self::assertSame(16, strlen(StringUtils::secureToken(8)));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', StringUtils::secureToken(8));
    }

    public function testSecureTokenRejectsZeroLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StringUtils::secureToken(0);
    }

    /**
     * These exact outputs determine existing slugs, so they are pinned.
     */
    public function testStripAccentsKeepsItsEstablishedMappings(): void
    {
        self::assertSame('arvizturo tukorfurogep', StringUtils::stripAccents('árvíztűrő tükörfúrógép'));
        self::assertSame('Ss', StringUtils::stripAccents('ß'));
        self::assertSame('AEIOU', StringUtils::stripAccents('ÁÉÍÓÚ'));
    }

    public function testStripAccentsNowCoversCharactersTheTableNeverDid(): void
    {
        $result = StringUtils::stripAccents('Łódź Ćwikła');

        self::assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', $result);
    }

    public function testStripAccentsLeavesPlainAsciiAlone(): void
    {
        self::assertSame('already ascii 123', StringUtils::stripAccents('already ascii 123'));
    }

    public function testSlugify(): void
    {
        self::assertSame('arvizturo-tukorfurogep', StringUtils::slugify('Árvíztűrő Tükörfúrógép'));
        self::assertSame('hello-world', StringUtils::slugify('  Hello, World!  '));
    }

    /**
     * A hyphen is inside the allowed character class, so runs of them are preserved
     * rather than collapsed. Pinned because existing URLs depend on it.
     */
    public function testSlugifyDoesNotCollapseExistingHyphenRuns(): void
    {
        self::assertSame('a---b', StringUtils::slugify('a---b'));
    }

    public function testPriceFormatters(): void
    {
        self::assertSame('1.234,50', StringUtils::priceFormatWD(1234.5));
        self::assertSame('1 234,50', StringUtils::priceFormatWS(1234.5));
        self::assertSame('1,234.50', StringUtils::priceFormatWC(1234.5));
        self::assertSame('1.235', StringUtils::priceFormatWD(1234.5, 0));
    }
}
