<?php
/**
 * Temporary server health check / diagnostic tool.
 * Protected by a simple token query parameter.
 *
 * Usage: https://your-domain.com/check.php?token=d2r-check-2024
 *
 * DELETE THIS FILE once debugging is complete.
 */

// Token protection
if (!isset($_GET['token']) || $_GET['token'] !== 'd2r-check-2024') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== D2R Server Health Check ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

// ---------------------------------------------------------------------------
// 1. PHP Version
// ---------------------------------------------------------------------------
echo "--- PHP Version ---\n";
$phpVersion = PHP_VERSION;
$versionOk = version_compare($phpVersion, '8.1.0', '>=');
echo "PHP Version: {$phpVersion} " . ($versionOk ? '[OK]' : '[FAIL - needs 8.1+]') . "\n\n";

// ---------------------------------------------------------------------------
// 2. Required Extensions
// ---------------------------------------------------------------------------
echo "--- Required Extensions ---\n";
$required = ['zip', 'mbstring', 'pdo_mysql', 'json', 'openssl'];
foreach ($required as $ext) {
    $loaded = extension_loaded($ext);
    echo "  {$ext}: " . ($loaded ? 'loaded [OK]' : 'MISSING [FAIL]') . "\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 3. Storage/logs directory
// ---------------------------------------------------------------------------
echo "--- Storage Logs ---\n";
$rootPath = __DIR__;
$storageLogsDir = $rootPath . '/storage/logs';

if (is_dir($storageLogsDir)) {
    echo "  storage/logs exists: YES\n";
    echo "  storage/logs writable: " . (is_writable($storageLogsDir) ? 'YES [OK]' : 'NO [FAIL]') . "\n";
} else {
    echo "  storage/logs exists: NO [FAIL]\n";
    echo "  Attempting to create...\n";
    $created = @mkdir($storageLogsDir, 0755, true);
    echo "  Created: " . ($created ? 'YES' : 'NO - check permissions') . "\n";
}

$primaryLog = $storageLogsDir . '/php-error.log';
$fallbackLog = sys_get_temp_dir() . '/d2r-error.log';

echo "  Primary log path: {$primaryLog}\n";
echo "  Primary log exists: " . (file_exists($primaryLog) ? 'YES (' . filesize($primaryLog) . ' bytes)' : 'NO') . "\n";
echo "  Fallback log path: {$fallbackLog}\n";
echo "  Fallback log exists: " . (file_exists($fallbackLog) ? 'YES (' . filesize($fallbackLog) . ' bytes)' : 'NO') . "\n";
echo "  sys_get_temp_dir(): " . sys_get_temp_dir() . "\n";
echo "\n";

// ---------------------------------------------------------------------------
// 4. Config file
// ---------------------------------------------------------------------------
echo "--- Configuration ---\n";
$configPath = $rootPath . '/config.php';
echo "  config.php exists: " . (file_exists($configPath) ? 'YES [OK]' : 'NO [FAIL]') . "\n\n";

// ---------------------------------------------------------------------------
// 5. Database connection
// ---------------------------------------------------------------------------
echo "--- Database ---\n";
if (file_exists($configPath)) {
    $config = require $configPath;
    $dbConfig = $config['database'] ?? [];
    $host = $dbConfig['host'] ?? '127.0.0.1';
    $port = $dbConfig['port'] ?? 3306;
    $name = $dbConfig['name'] ?? '';
    $user = $dbConfig['user'] ?? '';
    $pass = $dbConfig['pass'] ?? '';

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        echo "  Connection: SUCCESS [OK]\n";
        echo "  Database: {$name}\n";

        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "  Tables (" . count($tables) . "): " . implode(', ', $tables) . "\n";
    } catch (PDOException $e) {
        echo "  Connection: FAILED [FAIL]\n";
        echo "  Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "  Skipped - config.php not found\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 6. Last 20 lines of error logs
// ---------------------------------------------------------------------------
echo "--- Error Log (last 20 lines) ---\n";

$logFiles = [];
if (file_exists($primaryLog)) {
    $logFiles['Primary (storage/logs/php-error.log)'] = $primaryLog;
}
if (file_exists($fallbackLog)) {
    $logFiles['Fallback (' . $fallbackLog . ')'] = $fallbackLog;
}

if (empty($logFiles)) {
    echo "  No error log files found.\n";
} else {
    foreach ($logFiles as $label => $path) {
        echo "\n  [{$label}]\n";
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            echo "  Could not read file.\n";
            continue;
        }
        $last20 = array_slice($lines, -20);
        foreach ($last20 as $line) {
            echo "  " . $line . "\n";
        }
    }
}

echo "\n=== End of Health Check ===\n";
