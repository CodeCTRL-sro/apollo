<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Security;

use CodeCTRL\Apollo\Security\CSRF;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CSRF::class)]
final class CSRFTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = array();
    }

    protected function tearDown(): void
    {
        $_SESSION = array();
    }

    /**
     * The old implementation read $_SESSION without a guard and only produced a token
     * when $regenerate was true, so the very first call returned null.
     */
    public function testFirstCallProducesAToken(): void
    {
        $token = CSRF::generateToken('login');

        self::assertNotSame('', $token);
        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    public function testTokenIsStableAcrossCallsUntilRegenerated(): void
    {
        $first = CSRF::generateToken('login');

        self::assertSame($first, CSRF::generateToken('login'));
        self::assertNotSame($first, CSRF::generateToken('login', false, true));
    }

    public function testTokensAreScopedPerForm(): void
    {
        self::assertNotSame(CSRF::generateToken('login'), CSRF::generateToken('register'));
    }

    public function testVerifyAcceptsTheIssuedTokenOnly(): void
    {
        $token = CSRF::generateToken('login');

        self::assertTrue(CSRF::verifyToken('login', $token));
        self::assertFalse(CSRF::verifyToken('login', 'wrong'));
        self::assertFalse(CSRF::verifyToken('login', null));
        self::assertFalse(CSRF::verifyToken('login', ''));
        self::assertFalse(CSRF::verifyToken('other-form', $token));
    }

    public function testVerifyFailsWhenNoTokenWasEverIssued(): void
    {
        self::assertFalse(CSRF::verifyToken('never-rendered', 'anything'));
    }

    /**
     * The field the form emits and the field verification reads have to be the same one;
     * they were not before 3.3.0, so form validation never matched.
     */
    public function testEmittedFieldIsTheFieldThatVerifies(): void
    {
        $html = CSRF::generateToken('login', true);

        self::assertMatchesRegularExpression('/name="' . CSRF::FIELD . '"/', $html);

        preg_match('/name="' . CSRF::FIELD . '" value="([0-9a-f]+)"/', $html, $matches);
        self::assertArrayHasKey(1, $matches);
        self::assertTrue(CSRF::verifyToken('login', $matches[1]));
    }

    public function testLegacyFieldNameIsStillEmittedAndAccepted(): void
    {
        $html = CSRF::field('login');
        $token = CSRF::generateToken('login');

        self::assertStringContainsString('name="' . CSRF::LEGACY_FIELD . '"', $html);
        self::assertSame($token, CSRF::tokenFrom(array(CSRF::LEGACY_FIELD => $token)));
    }

    public function testTokenFromPrefersTheCanonicalField(): void
    {
        $data = array(CSRF::FIELD => 'canonical', CSRF::LEGACY_FIELD => 'legacy');

        self::assertSame('canonical', CSRF::tokenFrom($data));
        self::assertNull(CSRF::tokenFrom(array()));
        self::assertNull(CSRF::tokenFrom(array(CSRF::FIELD => '')));
    }

    public function testForgetInvalidatesTheToken(): void
    {
        $token = CSRF::generateToken('login');
        CSRF::forget('login');

        self::assertFalse(CSRF::verifyToken('login', $token));
    }

    public function testFieldEscapesTheTokenValue(): void
    {
        $_SESSION['_csrf__evil'] = '"><script>alert(1)</script>';

        self::assertStringNotContainsString('<script>', CSRF::field('evil'));
    }
}
