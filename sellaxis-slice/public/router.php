<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in dev server (used inside docker-compose
 * for this exercise; a real deployment would sit behind nginx/Apache with
 * standard front-controller rewrite rules instead). Without this, `php -S
 * -t public` would 404 on every path that isn't a literal file on disk --
 * it does not fall back to index.php the way a configured web server
 * would. This five-line script is that fallback.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // let the built-in server serve the static file directly
}

require __DIR__ . '/index.php';
