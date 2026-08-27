<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Core;

use RuntimeException;

/**
 * Typed, validated access to environment variables.
 *
 * Reading $_ENV directly spreads untyped, unchecked lookups across the codebase: a
 * missing key surfaces late and far from its cause, usually as a TypeError inside a
 * third party library. Env resolves a value once (from $_ENV, then $_SERVER, then
 * getenv()), casts it, and fails fast with a message that names the key.
 *
 * Laravel restricts env() to config files; Symfony wraps the same idea in env var
 * processors (%env(int:PORT)%). This is the small version of that idea.
 *
 * Omitting the $default argument makes the key mandatory:
 *
 *     Env::string('CRYPT_SECRET');          // throws when unset
 *     Env::string('MAIL_SMTP_HOST', null);  // optional, null when unset
 *     Env::int('MAIL_SMTP_PORT', 587);
 *     Env::bool('APP_DEBUG', false);
 */
final class Env
{
    /** Sentinel meaning "no default was given", so the key is mandatory. */
    private const REQUIRED = "\0apollo:required\0";

    /** @var array<string, string|null> Explicit values, primarily a test seam. */
    private static array $overrides = array();

    private function __construct()
    {
    }

    /**
     * Override a value for the current process. Passing null marks the key as unset,
     * which is how a test hides a variable the real environment happens to define.
     *
     * @param string $key
     * @param string|null $value
     */
    public static function set(string $key, ?string $value): void
    {
        self::$overrides[$key] = $value;
    }

    /**
     * Drop every override, restoring the real environment.
     */
    public static function clearOverrides(): void
    {
        self::$overrides = array();
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return self::raw($key) !== null;
    }

    /**
     * The uncast value, or null when the key is unset or blank.
     *
     * @param string $key
     * @return string|null
     */
    public static function raw(string $key): ?string
    {
        if (array_key_exists($key, self::$overrides)) {
            $value = self::$overrides[$key];
            if ($value === null) {
                return null;
            }
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        foreach (array($_ENV, $_SERVER) as $source) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $value = trim((string)$source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $value = getenv($key);

        return ($value === false || trim($value) === '') ? null : trim($value);
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return string|null
     */
    public static function string(string $key, mixed $default = self::REQUIRED): ?string
    {
        $value = self::raw($key);

        return $value ?? self::fallback($key, $default, 'string');
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return int|null
     */
    public static function int(string $key, mixed $default = self::REQUIRED): ?int
    {
        $value = self::raw($key);
        if ($value === null) {
            return self::fallback($key, $default, 'int');
        }
        if (!is_numeric($value)) {
            throw new RuntimeException(sprintf('Environment variable "%s" must be an integer, got "%s".', $key, $value));
        }

        return (int)$value;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return float|null
     */
    public static function float(string $key, mixed $default = self::REQUIRED): ?float
    {
        $value = self::raw($key);
        if ($value === null) {
            return self::fallback($key, $default, 'float');
        }
        if (!is_numeric($value)) {
            throw new RuntimeException(sprintf('Environment variable "%s" must be numeric, got "%s".', $key, $value));
        }

        return (float)$value;
    }

    /**
     * Accepts 1/true/yes/on and 0/false/no/off, case insensitively.
     *
     * @param string $key
     * @param mixed $default
     * @return bool|null
     */
    public static function bool(string $key, mixed $default = self::REQUIRED): ?bool
    {
        $value = self::raw($key);
        if ($value === null) {
            return self::fallback($key, $default, 'bool');
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new RuntimeException(
                sprintf('Environment variable "%s" must be a boolean, got "%s".', $key, $value)
            ),
        };
    }

    /**
     * A comma separated value as a list of trimmed, non empty strings.
     *
     * @param string $key
     * @param mixed $default
     * @return array<int, string>|null
     */
    public static function list(string $key, mixed $default = self::REQUIRED): ?array
    {
        $value = self::raw($key);
        if ($value === null) {
            return self::fallback($key, $default, 'list');
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($item) => $item !== ''));
    }

    /**
     * A binary secret of an exact byte length, for keys that feed a cipher.
     *
     * A "base64:" prefix is decoded, so a key can hold bytes outside the printable
     * range. Anything else is taken as raw bytes, which keeps existing plain text
     * secrets working. The length is validated here rather than inside openssl, where
     * a wrong length is silently padded or truncated.
     *
     * @param string $key
     * @param int $bytes
     * @return string
     */
    public static function binaryKey(string $key, int $bytes = 32): string
    {
        $value = self::string($key);

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                throw new RuntimeException(sprintf('Environment variable "%s" is not valid base64.', $key));
            }
            $value = $decoded;
        }

        if (strlen($value) !== $bytes) {
            throw new RuntimeException(sprintf(
                'Environment variable "%s" must decode to exactly %d bytes, got %d. '
                . 'Generate one with: php -r "echo \'base64:\' . base64_encode(random_bytes(%d)), PHP_EOL;"',
                $key,
                $bytes,
                strlen($value),
                $bytes
            ));
        }

        return $value;
    }

    /**
     * Fail fast at boot when a mandatory variable is missing, listing every offender at
     * once instead of surfacing them one deploy at a time.
     *
     * @param array<int, string> $keys
     */
    public static function assertRequired(array $keys): void
    {
        $missing = array();
        foreach ($keys as $key) {
            if (!self::has($key)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new RuntimeException(sprintf(
                'Missing required environment variable%s: %s. Check your .env against .env.example.',
                count($missing) === 1 ? '' : 's',
                implode(', ', $missing)
            ));
        }
    }

    /**
     * @param string $key
     * @param mixed $default
     * @param string $type
     * @return mixed
     */
    private static function fallback(string $key, mixed $default, string $type): mixed
    {
        if ($default === self::REQUIRED) {
            throw new RuntimeException(sprintf(
                'Missing required environment variable "%s" (expected %s). Check your .env against .env.example.',
                $key,
                $type
            ));
        }

        return $default;
    }
}
