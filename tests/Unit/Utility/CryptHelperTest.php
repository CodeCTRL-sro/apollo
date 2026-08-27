<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Utility;

use CodeCTRL\Apollo\Core\Env;
use CodeCTRL\Apollo\Utility\Helper\CryptHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CryptHelper::class)]
final class CryptHelperTest extends TestCase
{
    private const SECRET = 'a-test-secret-that-is-long-enough';

    protected function setUp(): void
    {
        Env::set('CRYPT_SECRET', self::SECRET);
        Env::set('CRYPT_ALGO', null);
    }

    protected function tearDown(): void
    {
        Env::clearOverrides();
    }

    public function testRoundTrip(): void
    {
        $plaintext = 'sensitive value with ünïcödé and \x00 bytes';

        self::assertSame($plaintext, CryptHelper::decrypt(CryptHelper::encrypt($plaintext)));
    }

    public function testEmptyStringSurvivesTheRoundTrip(): void
    {
        self::assertSame('', CryptHelper::decrypt(CryptHelper::encrypt('')));
    }

    /**
     * The point of moving off ECB: identical plaintexts must not produce identical
     * ciphertexts, because that leaks which records hold the same value.
     */
    public function testSamePlaintextEncryptsDifferentlyEachTime(): void
    {
        $a = CryptHelper::encrypt('duplicate');
        $b = CryptHelper::encrypt('duplicate');

        self::assertNotSame($a, $b);
        self::assertSame('duplicate', CryptHelper::decrypt($a));
        self::assertSame('duplicate', CryptHelper::decrypt($b));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $payload = CryptHelper::encrypt('do not change me');
        $raw = base64_decode($payload, true);

        // Flip a bit in the ciphertext, past the magic prefix, nonce and tag.
        $offset = strlen($raw) - 1;
        $raw[$offset] = chr(ord($raw[$offset]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/authentication/');

        CryptHelper::decrypt(base64_encode($raw));
    }

    public function testDecryptWithADifferentKeyIsRejected(): void
    {
        $payload = CryptHelper::encrypt('secret');

        Env::set('CRYPT_SECRET', 'a-completely-different-secret-key');

        $this->expectException(RuntimeException::class);
        CryptHelper::decrypt($payload);
    }

    /**
     * Payloads written by 3.2.x and earlier must stay readable, using the old key
     * handling: the secret was passed to OpenSSL verbatim.
     */
    public function testLegacyEcbPayloadIsStillReadable(): void
    {
        $plaintext = 'written by an older release';
        $legacy = base64_encode(openssl_encrypt($plaintext, 'aes-256-ecb', self::SECRET, OPENSSL_RAW_DATA));

        self::assertTrue(CryptHelper::isLegacyPayload($legacy));
        self::assertSame($plaintext, CryptHelper::decrypt($legacy));
    }

    public function testNewPayloadsAreNotReportedAsLegacy(): void
    {
        self::assertFalse(CryptHelper::isLegacyPayload(CryptHelper::encrypt('current')));
    }

    public function testNonAuthenticatedCipherIsRefusedForEncryption(): void
    {
        Env::set('CRYPT_ALGO', 'aes-256-ecb');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not an authenticated cipher/');

        CryptHelper::encrypt('nope');
    }

    public function testInvalidBase64IsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        CryptHelper::decrypt('!!! not base64 !!!');
    }

    public function testBase64PrefixedKeyIsAccepted(): void
    {
        Env::set('CRYPT_SECRET', 'base64:' . base64_encode(random_bytes(32)));

        self::assertSame('via base64 key', CryptHelper::decrypt(CryptHelper::encrypt('via base64 key')));
    }
}
