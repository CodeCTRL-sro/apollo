<?php

declare(strict_types=1);

/**
 * Constants Apollo expects the application entry point to have defined.
 *
 * Core\Factory\Factory evaluates BASE_DIR in a static property initialiser, so without
 * it the analyser reports "Constant BASE_DIR not found" in every file that touches the
 * class. Defining it here is more precise than the blanket ignore pattern this replaces.
 */
if (!defined('BASE_DIR')) {
    define('BASE_DIR', __DIR__);
}

if (!defined('HOME_DIR')) {
    define('HOME_DIR', __DIR__);
}
