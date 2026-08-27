<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Utility\Helper;

use CodeCTRL\Apollo\Core\Env;
use RuntimeException;

/**
 * Authenticated symmetric encryption.
 *
 * Payloads written by this class use AES-256-GCM: a random 96 bit nonce per message and
 * a 128 bit authentication tag, laid out as
 *
 *     base64( "APL2" | nonce[12] | tag[16] | ciphertext )
 *
 * The tag is what makes the difference from the previous format. Up to Apollo 3.2 this
 * class used aes-256-ecb, which has no IV (so equal plaintexts produce equal ciphertexts,
 * leaking structure) and no integrity check (so stored ciphertext can be altered without
 * detection). Those payloads are still *readable* — decrypt() recognises them by the
 * absence of the magic prefix and falls back — but nothing new is ever written in that
 * format. Re-encrypt at rest when convenient; isLegacyPayload() exists for exactly that.
 *
 * @see \CodeCTRL\Apollo\Core\Env::binaryKey() for the key format
 */
class CryptHelper
{
    /**
     * Distinguishes the authenticated format from raw legacy ciphertext. Four bytes
     * rather than one: legacy ciphertext is uniformly random, so a shorter marker would
     * collide often enough to matter.
     */
    private const MAGIC_V2 = 'APL2';

    private const CIPHER = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    /**
     * Kept under the original (misspelled) name so any application referencing it keeps
     * working. Only ever used to read pre-3.3 payloads.
     *
     * @deprecated 3.3.0 Legacy read path only; never used to encrypt.
     */
    private const CHIPER_ALGO = 'aes-256-ecb';

    /**
     * @param string $data
     * @return string
     * @throws RuntimeException
     */
    public static function encrypt($data): string
    {
        $cipher = self::cipher();
        $nonceLength = openssl_cipher_iv_length($cipher);
        if ($nonceLength === false || $nonceLength < 1) {
            throw new RuntimeException(sprintf('Cipher "%s" is not usable for encryption.', $cipher));
        }

        $nonce = random_bytes($nonceLength);
        $tag = '';
        $ciphertext = openssl_encrypt((string)$data, $cipher, self::key(), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new RuntimeException(openssl_error_string() ?: 'Encryption failed.');
        }

        return base64_encode(self::MAGIC_V2 . $nonce . $tag . $ciphertext);
    }

    /**
     * @param string $data
     * @return string
     * @throws RuntimeException
     */
    public static function decrypt($data): string
    {
        $raw = base64_decode((string)$data, true);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Ciphertext is not valid base64.');
        }

        if (!str_starts_with($raw, self::MAGIC_V2)) {
            return self::decryptLegacyEcb((string)$data);
        }

        // Not <=: GCM is a stream mode, so an empty plaintext yields an empty
        // ciphertext and a payload of exactly the header length.
        $minimum = strlen(self::MAGIC_V2) + self::NONCE_BYTES + self::TAG_BYTES;
        if (strlen($raw) < $minimum) {
            throw new RuntimeException('Ciphertext is truncated.');
        }

        $offset = strlen(self::MAGIC_V2);
        $nonce = substr($raw, $offset, self::NONCE_BYTES);
        $tag = substr($raw, $offset + self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, $minimum);

        $plaintext = openssl_decrypt($ciphertext, self::cipher(), self::key(), OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plaintext === false) {
            // With GCM this is not "wrong padding" — the tag did not verify, so the
            // payload was either produced with a different key or altered in storage.
            throw new RuntimeException('Ciphertext failed authentication: wrong key or tampered payload.');
        }

        return $plaintext;
    }

    /**
     * Whether a payload still uses the unauthenticated pre-3.3 format, so an application
     * can migrate stored values:
     *
     *     if (CryptHelper::isLegacyPayload($row['secret'])) {
     *         $row['secret'] = CryptHelper::encrypt(CryptHelper::decrypt($row['secret']));
     *     }
     *
     * @param string $data
     * @return bool
     */
    public static function isLegacyPayload(string $data): bool
    {
        $raw = base64_decode($data, true);

        return $raw !== false && $raw !== '' && !str_starts_with($raw, self::MAGIC_V2);
    }

    /**
     * Reads a payload written before 3.3.0, using the exact key handling the old code
     * used: the secret is handed to OpenSSL verbatim, which pads or truncates it to the
     * cipher's key length.
     *
     * @param string $data
     * @return string
     * @throws RuntimeException
     */
    private static function decryptLegacyEcb(string $data): string
    {
        $cipher = Env::string('CRYPT_ALGO', self::CHIPER_ALGO);
        $plaintext = openssl_decrypt(base64_decode($data), $cipher, self::legacyKey(), OPENSSL_RAW_DATA);

        if ($plaintext === false) {
            throw new RuntimeException(openssl_error_string() ?: 'Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * The cipher used for new payloads. CRYPT_ALGO may still override it, but only with
     * another authenticated mode: allowing a non-AEAD value here would quietly reproduce
     * the very problem this class was changed to fix.
     *
     * @return string
     */
    private static function cipher(): string
    {
        $cipher = strtolower(Env::string('CRYPT_ALGO', self::CIPHER));

        if (!in_array($cipher, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException(sprintf('Unknown cipher "%s" in CRYPT_ALGO.', $cipher));
        }

        if (!str_ends_with($cipher, '-gcm') && !str_ends_with($cipher, '-ccm')) {
            throw new RuntimeException(sprintf(
                'CRYPT_ALGO is set to "%s", which is not an authenticated cipher. Use an -gcm or '
                . '-ccm mode (the default is %s). Legacy payloads are still readable without this.',
                $cipher,
                self::CIPHER
            ));
        }

        return $cipher;
    }

    /**
     * A 32 byte key. A "base64:" prefixed secret is decoded and length checked; a plain
     * secret is stretched with SHA-256 so that short or long values still yield a
     * full length key instead of being silently padded by OpenSSL.
     *
     * @return string
     */
    private static function key(): string
    {
        $secret = Env::string('CRYPT_SECRET');

        if (str_starts_with($secret, 'base64:')) {
            return Env::binaryKey('CRYPT_SECRET', 32);
        }

        return hash('sha256', $secret, true);
    }

    /**
     * @return string
     */
    private static function legacyKey(): string
    {
        return Env::string('CRYPT_SECRET');
    }
}
