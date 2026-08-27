<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * BASE_DIR has to exist before any Apollo class is autoloaded.
 *
 * Core\Factory\Factory declares
 *
 *     protected static $config_path = BASE_DIR . DIRECTORY_SEPARATOR . 'config' . ...;
 *
 * and a static property initialiser is evaluated when the class is loaded, so without
 * the constant the very first autoload of that class is a fatal error. Utility\Logger
 * builds its log paths from it too.
 */
$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apollo-tests';

foreach (array($baseDir, $baseDir . '/logs', $baseDir . '/config') as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

if (!defined('BASE_DIR')) {
    define('BASE_DIR', $baseDir);
}
if (!defined('HOME_DIR')) {
    define('HOME_DIR', $baseDir);
}

// Superglobals are read directly in several places (sessions, cookies, server params).
// Starting from a known empty state keeps one test from leaking into the next.
$_SESSION = array();
$_COOKIE = array();
