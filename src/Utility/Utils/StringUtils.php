<?php

namespace CodeCTRL\Apollo\Utility\Utils;

use Transliterator;

class StringUtils
{
    /**
     * Explicit transliterations applied before any generic one.
     *
     * Kept as the first step on purpose: these exact mappings decided the slugs of every
     * URL generated so far, and a generic transliterator disagrees on some of them
     * ('ß' => 'Ss' here, 'ss' elsewhere). Changing them would silently rewrite existing
     * slugs, so the table stays authoritative and only fills in what it does not cover.
     *
     * @var array<string, string>
     */
    private const ACCENT_MAP = array(
        'Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E',
        'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U',
        'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'o', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y', 'ű' => 'u', 'ő' => 'o', 'Ű' => 'U', 'Ő' => 'O', 'ü' => 'u',
    );

    /**
     * @var Transliterator|null|false Lazily created; false once creation has failed.
     */
    private static Transliterator|null|false $transliterator = null;

    /**
     * A random string in the historical shape: half the random characters, the current
     * unix timestamp, then the other half. The returned length is therefore $length + 10,
     * unchanged from previous releases — callers sizing database columns around it are
     * unaffected.
     *
     * What did change is the source of randomness: rand() is a Mersenne Twister whose
     * output is predictable from a handful of samples, which matters the moment one of
     * these ends up in a token or a one time link. random_int() draws from the system
     * CSPRNG instead.
     *
     * For new code that just needs an unguessable token, prefer secureToken().
     *
     * @param int $length
     * @return string
     */
    public static function generateRandomString(int $length = 20): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $max = strlen($characters) - 1;

        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $max)];
        }

        // (int) rather than $length / 2: an odd length made this a float, which PHP 8.1
        // deprecates as a substr() argument. The truncation result is identical.
        $half = (int)($length / 2);

        return substr($randomString, 0, $half) . time() . substr($randomString, $half, $half);
    }

    /**
     * An unguessable hex token of exactly $bytes * 2 characters.
     *
     * @param int $bytes
     * @return string
     */
    public static function secureToken(int $bytes = 32): string
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Token length must be at least one byte.');
        }

        return bin2hex(random_bytes($bytes));
    }

    /**
     * @param float|int|string $price
     * @param int $decimals
     * @return string
     */
    public static function priceFormatWD($price, int $decimals = 2): string
    {
        return number_format((float)$price, $decimals, ",", ".");
    }

    /**
     * @param float|int|string $price
     * @param int $decimals
     * @return string
     */
    public static function priceFormatWS($price, int $decimals = 2): string
    {
        return number_format((float)$price, $decimals, ",", " ");
    }

    /**
     * @param float|int|string $price
     * @param int $decimals
     * @return string
     */
    public static function priceFormatWC($price, int $decimals = 2): string
    {
        return number_format((float)$price, $decimals, ".", ",");
    }

    /**
     * @param string $string
     * @return string
     */
    public static function slugify($string): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', self::stripAccents($string)), '-'));
    }

    /**
     * Reduce a string to ASCII.
     *
     * The explicit table runs first so existing slugs are reproduced byte for byte. Only
     * what it leaves behind — Polish, Czech, Turkish, Greek, Cyrillic and so on, none of
     * which the table ever covered — is handed to intl's transliterator, or to iconv
     * where intl is unavailable.
     *
     * @param string $stripAccents
     * @return string
     */
    public static function stripAccents($stripAccents): string
    {
        $string = strtr((string)$stripAccents, self::ACCENT_MAP);

        if (!preg_match('/[^\x00-\x7F]/', $string)) {
            return $string;
        }

        $transliterator = self::transliterator();
        if ($transliterator instanceof Transliterator) {
            $converted = $transliterator->transliterate($string);
            if (is_string($converted)) {
                $string = $converted;
            }
        }

        if (preg_match('/[^\x00-\x7F]/', $string) && function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
            if (is_string($converted)) {
                // Some iconv implementations spell transliterations as "a or 'e.
                $string = preg_replace('/[`\'"^~]([A-Za-z])/', '$1', $converted);
            }
        }

        return $string;
    }

    /**
     * @return Transliterator|false
     */
    private static function transliterator(): Transliterator|false
    {
        if (self::$transliterator === null) {
            self::$transliterator = class_exists(Transliterator::class)
                ? (Transliterator::create('Any-Latin; Latin-ASCII') ?? false)
                : false;
        }

        return self::$transliterator;
    }
}
