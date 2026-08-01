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
// Is this request from the mobile app rather than a browser?
//
// It matters here because bootstrap can fail before the router exists, and a
// setup failure used to answer with an HTML page no matter who asked. The
// Android client cannot parse HTML, so a misconfigured server reached the agent
// as "something went wrong" with no cause - the app was blamed for a server
// problem. API callers get the same envelope the rest of the API uses.
// ---------------------------------------------------------------------------
$lrmsWantsJson = (static function (): bool {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#(^|/)api(/|$)#', $path) === 1) {
        return true;
    }
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        return true;
    }
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
})();

/**
 * Reports a fatal setup problem in whatever format the caller can read, then stops.
 *
 * @param string $apiMessage One line, safe to show to an agent on a phone.
 * @param string $htmlBody   Full explanation for whoever administers the server.
 */
$lrmsSetupFailure = static function (string $title, string $apiMessage, string $htmlBody) use ($lrmsWantsJson): never {
    http_response_code(500);

    if ($lrmsWantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => false, 'data' => null, 'message' => $apiMessage],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>D2 Recovery setup</title>'
       . '<div style="font:15px/1.6 system-ui,sans-serif;max-width:680px;margin:12vh auto;padding:0 24px;color:#1c2128">'
       . '<h1 style="font-size:20px;color:#b3261e;margin:0 0 12px">' . $title . '</h1>'
       . $htmlBody
       . '</div>';
    exit;
};

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
$configFile = ROOT_PATH . '/config/config.php';
if (!is_file($configFile)) {
    $lrmsSetupFailure(
        'Configuration missing',
        'This server has not been set up yet: config/config.php is missing. '
            . 'Please contact your administrator.',
        '<p><code>config/config.php</code> was not found.</p>'
        . '<p>Copy <code>config/config.sample.php</code> to <code>config/config.php</code>, '
        . 'fill in the database credentials, and generate the three keys as described in that file.</p>',
    );
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
    if (PHP_SAPI === 'cli') {
        http_response_code(500);
        fwrite(STDERR, "D2 Recovery configuration is incomplete:\n");
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

    // The app shows this verbatim to whoever is holding the phone, so it names
    // the fault and who can fix it instead of blaming the network.
    $apiMessage = 'This server is not set up correctly yet: '
        . implode(', ', array_column($configProblems, 0))
        . ' not usable in config/config.php. Your administrator needs to fix it '
        . '(open setup-keys.php on the server). This is not a problem with your phone.';

    $lrmsSetupFailure(
        'Configuration incomplete',
        $apiMessage,
        '<p><code>config/config.php</code> was found, but these entries are not usable:</p>'
        . '<ul style="line-height:1.9">' . $rows . '</ul>'
        . (is_file(dirname(__DIR__) . '/setup-keys.php')
            ? '<p><strong>Easiest fix:</strong> open <a href="setup-keys.php">setup-keys.php</a> - it '
              . 'generates the missing keys and writes them for you. Keys that are already set are left '
              . 'alone. Delete that file afterwards.</p>'
              . '<p style="color:#6b7280;font-size:13px">Prefer to do it by hand? Generate each key with:</p>'
            : '<p>Generate each key with:</p>')
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
        . 'server error.</p>',
    );
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
