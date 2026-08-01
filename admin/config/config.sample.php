<?php
/**
 * D2 Recovery configuration template.
 *
 * SETUP
 *   1. Copy this file to config.php  (config.php is git-ignored)
 *   2. Fill in the database credentials from cPanel
 *   3. Generate the two keys below with:
 *        php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *
 * Everything else (SMTP, SMS, Maps, Firebase, app version) lives in the
 * `settings` database table and is editable from Settings in the admin panel.
 * Never put those secrets here.
 */

declare(strict_types=1);

return [

    // ---------------------------------------------------------------------
    // Database
    // ---------------------------------------------------------------------
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'lrms',
        'user'    => 'lrms_user',
        'pass'    => 'change-me',
        'charset' => 'utf8mb4',
    ],

    // ---------------------------------------------------------------------
    // Cryptographic keys - 64 hex characters (32 bytes) each.
    //
    // app_key     signs JWTs and session-bound tokens
    // data_key    encrypts mobile / Aadhaar (*_enc columns), AES-256-GCM
    // hash_pepper derives the *_hash search columns, HMAC-SHA256
    //
    // WARNING: changing data_key or hash_pepper makes existing encrypted
    // values unreadable and existing hashes unsearchable. Set once, back up.
    // ---------------------------------------------------------------------
    'app_key'     => '',
    'data_key'    => '',
    'hash_pepper' => '',

    // ---------------------------------------------------------------------
    // Application
    // ---------------------------------------------------------------------
    'app' => [
        // Absolute base URL of the admin panel, no trailing slash.
        // Leave empty to auto-detect from the request.
        'url'      => '',
        // Sub-directory the app is served from, e.g. '/lrms'. Empty for domain root.
        'base_path' => '',
        'env'      => 'production',   // 'production' hides error details
        'debug'    => false,
        'timezone' => 'Asia/Kolkata',
    ],

    // ---------------------------------------------------------------------
    // Filesystem paths (absolute, no trailing slash).
    // Defaults sit next to this config; override if you move them outside
    // the web root, which is recommended when the host allows it.
    // ---------------------------------------------------------------------
    'paths' => [
        'uploads' => __DIR__ . '/../uploads',
        'storage' => __DIR__ . '/../storage',
    ],

    // ---------------------------------------------------------------------
    // Uploads
    // ---------------------------------------------------------------------
    'uploads' => [
        'max_photo_bytes'    => 8 * 1024 * 1024,   // 8 MB
        'max_document_bytes' => 12 * 1024 * 1024,  // 12 MB
        'max_import_bytes'   => 25 * 1024 * 1024,  // 25 MB
        'allowed_image_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_doc_mime'   => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],

    // ---------------------------------------------------------------------
    // Session cookie
    // ---------------------------------------------------------------------
    'session' => [
        'name'     => 'lrms_session',
        'lifetime' => 7200,   // seconds
        'secure'   => true,   // set false only if the site has no HTTPS yet
    ],
];
