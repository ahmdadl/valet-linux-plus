<?php

/**
 * Check the system's compatibility with Valet.
 *
 * This guard must only run for the Valet CLI (and tests). It is skipped when
 * server.php is executed by php-fpm, because isolated sites intentionally serve
 * the Valet router under an older PHP version than Valet's own minimum.
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    return;
}

$inTestingEnvironment = strpos($_SERVER['SCRIPT_NAME'] ?? '', 'phpunit') !== false;

if (PHP_OS != 'Linux' && !$inTestingEnvironment) {
    echo 'Valet only supports Linux.'.PHP_EOL;

    exit(1);
}

if (version_compare(PHP_VERSION, '8.2', '<')) {
    echo 'Valet requires PHP 8.2 or later.';

    exit(1);
}
