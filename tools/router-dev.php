<?php
/**
 * Router script for PHP's built-in development server.
 *
 * The built-in server has no .htaccess support, so this reproduces the two rules
 * that matter: serve real files (assets), send everything else to index.php, and
 * keep uploads/ inaccessible the way the production .htaccess does.
 *
 *   php -S 127.0.0.1:8099 -t admin tools/router-dev.php
 *
 * Development only. Production uses admin/.htaccess.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Use the document root the server was actually started with (`php -S -t DIR`),
// so this router can front any tree - the repository's admin/ during normal
// development, or a built hosting package when verifying a release. Hardcoding
// admin/ here silently served the repo's front controller no matter what -t
// said, which made a package test look catastrophically broken when the package
// was fine.
$root = $_SERVER['DOCUMENT_ROOT'] ?? '';
if (!is_string($root) || $root === '' || !is_file($root . '/index.php')) {
    $root = __DIR__ . '/../admin';
}
$file = realpath($root . $path);

// Mirror the production deny rules for application internals.
foreach (['/app/', '/config/', '/views/', '/storage/', '/uploads/'] as $blocked) {
    if (str_starts_with($path, $blocked)) {
        http_response_code(404);
        echo 'Not Found';
        return true;
    }
}

// Serve existing static files directly (CSS, JS).
if ($file !== false && is_file($file) && str_starts_with($file, realpath($root) ?: $root)) {
    return false;
}

// Everything else goes through the front controller.
require $root . '/index.php';
