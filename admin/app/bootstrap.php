<?php
/**
 * Application bootstrap: autoloader, config, error handling, timezone.
 * Included by the single front controller (public index.php).
 */

declare(strict_types=1);

define('LRMS_START', microtime(true));
define('APP_PATH', __DIR__);
define('ROOT_PATH', dirname(__DIR__));

// ---------------------------------------------------------------------------
// PSR-4 style autoloader for the App\ namespace -> admin/app/
// ---------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
$configFile = ROOT_PATH . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>LRMS setup</title>'
       . '<div style="font:15px/1.6 system-ui,sans-serif;max-width:640px;margin:14vh auto;padding:0 24px;color:#1c2128">'
       . '<h1 style="font-size:20px;color:#b3261e;margin:0 0 12px">Configuration missing</h1>'
       . '<p><code>config/config.php</code> was not found.</p>'
       . '<p>Copy <code>config/config.sample.php</code> to <code>config/config.php</code>, '
       . 'fill in the database credentials, and generate the three keys as described in that file.</p>'
       . '</div>';
    exit;
}

/** @var array<string,mixed> $config */
$config = require $configFile;

\App\Core\Config::load($config);

// ---------------------------------------------------------------------------
// Configuration must be COMPLETE, not merely present.
//
// This check exists because its absence cost a live deployment a debugging
// session. config.php was there, so the app booted and the panel and the API's
// /ping both answered normally - but the crypto keys were blank. Nothing failed
// until an action happened to touch encryption, and then it failed as a bare
// HTTP 500 with no hint of the cause:
//
//   * creating a user with a mobile number  -> encrypt() throws  -> 500
//   * signing in with an unknown identifier containing a digit, which falls
//     through to the mobile-hash lookup     -> searchHash() throws -> 500
//
// The same login worked for an identifier with no digits, because that path
// never reaches the crypto. A fault that depends on whether the text you typed
// contains a digit is close to undiagnosable from the outside.
//
// So: fail at the front door, name the exact keys, and say how to generate them.
// ---------------------------------------------------------------------------
$configProblems = [];

foreach (['db.host', 'db.name', 'db.user'] as $required) {
    if ((string) \App\Core\Config::get($required, '') === '') {
        $configProblems[] = [$required, 'is empty - fill in your database details'];
    }
}

// deriveKey() accepts 64 hex characters (what the documented generator produces)
// or any passphrase of 16+ characters, which it hashes. Anything shorter is
// rejected there, so it is rejected here too rather than at first use.
foreach (['app_key', 'data_key', 'hash_pepper'] as $keyName) {
    $value = (string) \App\Core\Config::get($keyName, '');

    if ($value === '') {
        $configProblems[] = [$keyName, 'is empty'];
    } elseif (strlen($value) < 16) {
        $configProblems[] = [$keyName, 'is too short (' . strlen($value) . ' characters; use 64 hex)'];
    } elseif (str_contains($value, 'PASTE') || str_contains($value, 'CHANGE')) {
        $configProblems[] = [$keyName, 'still holds the placeholder text from config.sample.php'];
    }
}

if ($configProblems !== []) {
    http_response_code(500);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "LRMS configuration is incomplete:\n");
        foreach ($configProblems as [$key, $why]) {
            fwrite(STDERR, sprintf("  - %s %s\n", $key, $why));
        }
        fwrite(STDERR, "\nGenerate a key with: php -r \"echo bin2hex(random_bytes(32));\"\n");
        exit(1);
    }

    $rows = '';
    foreach ($configProblems as [$key, $why]) {
        $rows .= '<li><code>' . htmlspecialchars($key, ENT_QUOTES) . '</code> '
            . htmlspecialchars($why, ENT_QUOTES) . '</li>';
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>LRMS setup</title>'
       . '<div style="font:15px/1.6 system-ui,sans-serif;max-width:680px;margin:12vh auto;padding:0 24px;color:#1c2128">'
       . '<h1 style="font-size:20px;color:#b3261e;margin:0 0 12px">Configuration incomplete</h1>'
       . '<p><code>config/config.php</code> was found, but these entries are not usable:</p>'
       . '<ul style="line-height:1.9">' . $rows . '</ul>'
       . '<p>Generate each key with:</p>'
       . '<pre style="background:#f5f7fa;border:1px solid #e2e5ea;border-radius:8px;padding:12px;overflow:auto">'
       . 'php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"</pre>'
       . '<p style="color:#b3261e"><strong>Once real borrower data exists, <code>data_key</code> and '
       . '<code>hash_pepper</code> can never be changed.</strong> data_key decrypts stored mobile and '
       . 'Aadhaar numbers and hash_pepper derives their search hashes, so altering either makes existing '
       . 'records unreadable. Set them once, then back them up somewhere separate from your database '
       . 'backups.</p>'
       . '<p style="color:#6b7280;font-size:13px">Until these are set the panel will appear to work: pages '
       . 'load and sign-in succeeds, but anything touching encryption - creating a user with a mobile '
       . 'number, importing leads, an app login that falls through to the mobile lookup - fails with a '
       . 'server error.</p>'
       . '</div>';
    exit;
}

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------
$debug = (bool) \App\Core\Config::get('app.debug', false);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logDir = \App\Core\Config::get('paths.storage', ROOT_PATH . '/storage') . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php-error.log');

date_default_timezone_set((string) \App\Core\Config::get('app.timezone', 'Asia/Kolkata'));

set_exception_handler([\App\Core\ErrorHandler::class, 'handleException']);
set_error_handler([\App\Core\ErrorHandler::class, 'handleError']);
register_shutdown_function([\App\Core\ErrorHandler::class, 'handleShutdown']);

// ---------------------------------------------------------------------------
// Security headers (applied to every response)
// ---------------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0'); // superseded by CSP; explicit to avoid legacy filter bugs
    header_remove('X-Powered-By');
}
