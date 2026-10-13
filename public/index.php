<?php

declare(strict_types=1);

/*
 * KaninPHP front controller: every HTTP request enters the app here.
 *
 * With Apache, public/.htaccess sends every non-file request to this file.
 * With `php kanin serve` (PHP's built-in server) this file is the router
 * script, so it must hand real files (images, robots.txt...) back to the
 * server: returning false tells the built-in server "serve it yourself".
 */

use Kanin\Application;

if (PHP_SAPI === 'cli-server') {
    $publicDir = (string) realpath(__DIR__);
    $requested = realpath($publicDir . rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)));

    // Only plain files that really live inside public/: never "../" escapes,
    // never hidden dotfiles such as .htaccess, never this script itself.
    if (
        $requested !== false
        && is_file($requested)
        && ! str_starts_with(basename($requested), '.')
        && str_starts_with($requested, $publicDir . DIRECTORY_SEPARATOR)
        && $requested !== realpath(__FILE__)
    ) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

(new Application(dirname(__DIR__)))->run();
