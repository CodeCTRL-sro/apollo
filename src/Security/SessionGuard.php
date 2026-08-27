<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Security;

/**
 * Session cookie hardening, applied before the session is started.
 *
 * PHP's defaults leave the session cookie readable from JavaScript, sent on cross-site
 * requests, and accepting a session id the client made up. Symfony sets these through
 * framework.session.*, Laravel through config/session.php; Apollo previously set none of
 * them and inherited whatever php.ini happened to say.
 *
 * Everything here is a no-op once a session exists, so calling it late cannot break a
 * request that already started one — but it also will not protect it, which is why
 * Apollo::run() calls it during boot.
 *
 * Config (the `session` dimension of the route config):
 *
 *     'session' => array(
 *         'harden'   => true,       // set false to keep php.ini settings
 *         'samesite' => 'Lax',      // Lax | Strict | None
 *         'secure'   => null,       // null = auto-detect HTTPS
 *         'httponly' => true,
 *         'lifetime' => 0,
 *         'path'     => '/',
 *         'domain'   => '',
 *         'name'     => null,       // e.g. 'APOLLOSESSID'
 *     ),
 */
final class SessionGuard
{
    /**
     * @param array<string, mixed> $options
     * @return bool True when the settings were applied.
     */
    public static function harden(array $options = array()): bool
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return false;
        }

        $secure = $options['secure'] ?? null;
        if ($secure === null) {
            $secure = self::isHttps();
        }

        $samesite = (string)($options['samesite'] ?? 'Lax');
        if (!in_array($samesite, array('Lax', 'Strict', 'None'), true)) {
            $samesite = 'Lax';
        }
        // SameSite=None without Secure is rejected by browsers, which drops the cookie
        // altogether — silently logging everyone out rather than loosening anything.
        if ($samesite === 'None') {
            $secure = true;
        }

        session_set_cookie_params(array(
            'lifetime' => (int)($options['lifetime'] ?? 0),
            'path' => (string)($options['path'] ?? '/'),
            'domain' => (string)($options['domain'] ?? ''),
            'secure' => (bool)$secure,
            'httponly' => (bool)($options['httponly'] ?? true),
            'samesite' => $samesite,
        ));

        if (!empty($options['name'])) {
            session_name((string)$options['name']);
        }

        // Refuse a session id the client invented: without strict mode an attacker can
        // fixate one before the victim logs in and then reuse it.
        @ini_set('session.use_strict_mode', '1');
        // Never accept or emit the session id in the URL.
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');

        return true;
    }

    /**
     * @return bool
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

        return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
