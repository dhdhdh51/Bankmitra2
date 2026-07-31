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
