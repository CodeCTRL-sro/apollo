<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Core;

use CodeCTRL\Apollo\Core\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::clearOverrides();
    }

    public function testReadsAStringAndTreatsBlankAsUnset(): void
    {
        Env::set('APOLLO_TEST_STRING', '  hello  ');
        self::assertSame('hello', Env::string('APOLLO_TEST_STRING'));

        Env::set('APOLLO_TEST_STRING', '');
        self::assertNull(Env::string('APOLLO_TEST_STRING', null));
        self::assertFalse(Env::has('APOLLO_TEST_STRING'));
    }

    public function testMissingKeyWithoutDefaultThrowsAndNamesTheKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APOLLO_TEST_ABSENT/');

        Env::string('APOLLO_TEST_ABSENT');
    }

    public function testMissingKeyWithDefaultReturnsTheDefault(): void
    {
        self::assertSame('fallback', Env::string('APOLLO_TEST_ABSENT', 'fallback'));
        self::assertSame(587, Env::int('APOLLO_TEST_ABSENT', 587));
        self::assertFalse(Env::bool('APOLLO_TEST_ABSENT', false));
        self::assertNull(Env::string('APOLLO_TEST_ABSENT', null));
    }

    public function testIntRejectsNonNumericValues(): void
    {
        Env::set('APOLLO_TEST_INT', 'not-a-number');

        $this->expectException(RuntimeException::class);
        Env::int('APOLLO_TEST_INT');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function booleanProvider(): array
    {
        return array(
            'one' => array('1', true),
            'true' => array('true', true),
            'TRUE' => array('TRUE', true),
            'yes' => array('yes', true),
            'on' => array('on', true),
            'zero' => array('0', false),
            'false' => array('false', false),
            'no' => array('no', false),
            'off' => array('off', false),
        );
    }

    /**
     * @param string $raw
     * @param bool $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('booleanProvider')]
    public function testBoolParsesTheUsualSpellings(string $raw, bool $expected): void
    {
        Env::set('APOLLO_TEST_BOOL', $raw);
        self::assertSame($expected, Env::bool('APOLLO_TEST_BOOL'));
    }

    public function testBoolRejectsAmbiguousValues(): void
    {
        Env::set('APOLLO_TEST_BOOL', 'maybe');

        $this->expectException(RuntimeException::class);
        Env::bool('APOLLO_TEST_BOOL');
    }

    public function testListSplitsAndTrims(): void
    {
        Env::set('APOLLO_TEST_LIST', ' 10.0.0.1 , 10.0.0.2 ,, ');

        self::assertSame(array('10.0.0.1', '10.0.0.2'), Env::list('APOLLO_TEST_LIST'));
    }

    public function testBinaryKeyDecodesBase64AndChecksLength(): void
    {
        $key = random_bytes(32);
        Env::set('APOLLO_TEST_KEY', 'base64:' . base64_encode($key));

        self::assertSame($key, Env::binaryKey('APOLLO_TEST_KEY', 32));
    }

    public function testBinaryKeyRejectsTheWrongLength(): void
    {
        Env::set('APOLLO_TEST_KEY', 'base64:' . base64_encode(random_bytes(16)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/32 bytes/');

        Env::binaryKey('APOLLO_TEST_KEY', 32);
    }

    public function testAssertRequiredListsEveryMissingKeyAtOnce(): void
    {
        Env::set('APOLLO_TEST_PRESENT', 'x');

        try {
            Env::assertRequired(array('APOLLO_TEST_PRESENT', 'APOLLO_TEST_GONE_A', 'APOLLO_TEST_GONE_B'));
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('APOLLO_TEST_GONE_A', $e->getMessage());
            self::assertStringContainsString('APOLLO_TEST_GONE_B', $e->getMessage());
            self::assertStringNotContainsString('APOLLO_TEST_PRESENT', $e->getMessage());
        }
    }

    public function testOverrideOfNullHidesARealEnvironmentVariable(): void
    {
        $_ENV['APOLLO_TEST_REAL'] = 'from-environment';
        self::assertSame('from-environment', Env::string('APOLLO_TEST_REAL'));

        Env::set('APOLLO_TEST_REAL', null);
        self::assertNull(Env::string('APOLLO_TEST_REAL', null));

        unset($_ENV['APOLLO_TEST_REAL']);
    }
}
