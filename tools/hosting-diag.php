<?php
/**
 * LRMS hosting diagnostic.
 *
 * Upload this next to index.php as diag.php, open https://your-domain/diag.php,
 * fix whatever it reports, then DELETE IT. It is not part of the application and
 * must not stay on a live site.
 *
 * It never prints the contents of config.php, so no credentials or keys can leak
 * through it - but it does reveal paths and PHP versions, which is reason enough
 * to remove it once you are done.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

// Small gate so a passing scanner or crawler does not get a free readout of PHP
// versions, absolute paths and module lists. It is not access control - it just
// means the page says nothing useful unless you asked for it on purpose.
if (!isset($_GET['i-understand'])) {
    echo "LRMS diagnostic.\n\n";
    echo "Add ?i-understand=1 to the URL to run it, then delete this file.\n";
    exit;
}

$root = __DIR__;
$rows = [];
$problems = [];

function row(string $label, string $value, ?bool $ok = null): void
{
    global $rows;
    $mark = $ok === null ? '   ' : ($ok ? ' ok' : ' !!');
    $rows[] = sprintf('%s  %-34s %s', $mark, $label, $value);
}

function problem(string $text): void
{
    global $problems;
    $problems[] = $text;
}

function perms(string $path): string
{
    if (!file_exists($path)) {
        return 'MISSING';
    }
    $p = substr(sprintf('%o', fileperms($path)), -4);
    $owner = function_exists('posix_getpwuid')
        ? (posix_getpwuid(fileowner($path))['name'] ?? (string) fileowner($path))
        : (string) fileowner($path);
    return $p . ' owner=' . $owner;
}

echo "LRMS hosting diagnostic\n";
echo str_repeat('=', 66), "\n\n";

// ---------------------------------------------------------------------------
echo "PHP\n";
// ---------------------------------------------------------------------------
$phpOk = PHP_VERSION_ID >= 80100;
row('PHP version', PHP_VERSION, $phpOk);
if (!$phpOk) {
    problem('PHP ' . PHP_VERSION . ' is too old. LRMS needs 8.1 or newer. In cPanel: MultiPHP Manager.');
}
row('SAPI', PHP_SAPI);
row('running as user', function_exists('posix_geteuid') && function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : (getenv('USER') ?: '?'));

foreach (['pdo_mysql', 'mbstring', 'openssl', 'zip', 'gd', 'fileinfo', 'json'] as $ext) {
    $has = extension_loaded($ext);
    row('extension ' . $ext, $has ? 'loaded' : 'MISSING', $has);
    if (!$has) {
        problem("PHP extension '$ext' is missing. Enable it in cPanel -> Select PHP Version -> Extensions.");
    }
}

// ---------------------------------------------------------------------------
echo "\nLayout\n";
// ---------------------------------------------------------------------------
row('this file is in', $root);
foreach (['index.php', '.htaccess', 'app/bootstrap.php', 'views', 'assets', 'schema.sql'] as $f) {
    $exists = file_exists($root . '/' . $f);
    row($f, $exists ? 'present' : 'MISSING', $exists);
}
if (!file_exists($root . '/index.php')) {
    problem('index.php is not here. You uploaded the folder instead of its CONTENTS. '
        . 'Everything that was inside admin/ (or inside the hosting branch) must sit directly '
        . 'in public_html, not in a sub-folder.');
}
if (!file_exists($root . '/.htaccess')) {
    problem('.htaccess is missing - hidden files were skipped by your FTP client or ZIP '
        . 'extractor. Without it, pretty URLs break and app internals become downloadable.');
}

// ---------------------------------------------------------------------------
echo "\nPermissions  (403 Forbidden almost always lives here)\n";
// ---------------------------------------------------------------------------
foreach (['.' => $root, 'index.php' => $root . '/index.php', 'storage' => $root . '/storage',
          'uploads' => $root . '/uploads', 'config' => $root . '/config'] as $label => $path) {
    row($label, perms($path));
}

$dirMode = fileperms($root) & 0777;
if (($dirMode & 0005) !== 0005) {
    problem(sprintf(
        'The document root is %o - the web server cannot read and enter it, which is exactly '
        . 'what produces a bare 403. Set directories to 755: chmod 755 . and chmod -R 755 storage uploads',
        $dirMode
    ));
}
if (($dirMode & 0022) === 0022) {
    problem(sprintf(
        'The document root is %o (group- and world-writable). cPanel hosts running suPHP or '
        . 'suexec REFUSE to serve such a directory and return 403. Never use 777 - use 755.',
        $dirMode
    ));
}
$indexMode = file_exists($root . '/index.php') ? (fileperms($root . '/index.php') & 0777) : 0;
if ($indexMode !== 0 && ($indexMode & 0004) !== 0004) {
    problem(sprintf('index.php is %o and unreadable by the web server. Files should be 644.', $indexMode));
}

foreach (['storage', 'storage/logs', 'storage/backups', 'storage/imports', 'storage/tmp', 'uploads'] as $dir) {
    $path = $root . '/' . $dir;
    $writable = is_dir($path) && is_writable($path);
    row('writable: ' . $dir, is_dir($path) ? ($writable ? 'yes' : 'NO') : 'MISSING', $writable);
    if (is_dir($path) && !$writable) {
        problem("$dir is not writable by PHP. chmod -R 755 storage uploads (and check the owner).");
    }
}

// ---------------------------------------------------------------------------
echo "\nApache / rewrite\n";
// ---------------------------------------------------------------------------
$mods = function_exists('apache_get_modules') ? apache_get_modules() : null;
if ($mods === null) {
    row('mod_rewrite', 'cannot detect (PHP is not the Apache module)');
} else {
    $has = in_array('mod_rewrite', $mods, true);
    row('mod_rewrite', $has ? 'loaded' : 'MISSING', $has);
    if (!$has) {
        problem('mod_rewrite is not loaded, so pretty URLs cannot work. Ask your host to enable it.');
    }
}
row('HTTPS', (($_SERVER['HTTPS'] ?? '') === 'on'
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'yes' : 'no (plain HTTP)');

$auth = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? null;
row('Authorization reaches PHP', $auth === null ? 'not tested - see note below' : 'yes');

// ---------------------------------------------------------------------------
echo "\nConfiguration\n";
// ---------------------------------------------------------------------------
$configFile = $root . '/config/config.php';
$hasConfig = is_file($configFile);
row('config/config.php', $hasConfig ? 'present' : 'MISSING', $hasConfig);
if (!$hasConfig) {
    problem('config/config.php does not exist yet. Copy config/config.sample.php to '
        . 'config/config.php and fill in the database credentials and the three keys.');
} else {
    // Load it only to check shape. Values are never printed.
    $cfg = @include $configFile;
    if (!is_array($cfg)) {
        problem('config/config.php does not return an array. It must start with "<?php return [".');
    } else {
        foreach (['app_key', 'data_key', 'hash_pepper'] as $key) {
            $set = isset($cfg[$key]) && is_string($cfg[$key]) && strlen($cfg[$key]) >= 32;
            row('key ' . $key, $set ? 'set' : 'EMPTY or too short', $set);
            if (!$set) {
                problem("$key is not set. Generate one with: php -r \"echo bin2hex(random_bytes(32));\"");
            }
        }
        $db = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
        row('db name', ($db['name'] ?? '') !== '' ? (string) $db['name'] : 'EMPTY', ($db['name'] ?? '') !== '');
        if (extension_loaded('pdo_mysql') && ($db['name'] ?? '') !== '') {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    (string) ($db['host'] ?? 'localhost'),
                    (int) ($db['port'] ?? 3306),
                    (string) $db['name']
                );
                $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                $tables = (int) $pdo->query(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
                )->fetchColumn();
                row('database connection', 'ok', true);
                row('tables found', (string) $tables, $tables >= 20);
                if ($tables < 20) {
                    problem("Only $tables tables found - schema.sql was not imported. "
                        . 'Import it in phpMyAdmin with the database selected.');
                }
            } catch (Throwable $e) {
                row('database connection', 'FAILED', false);
                problem('Database connection failed: ' . $e->getMessage()
                    . ' - on cPanel the user and database names are prefixed, e.g. myacct_lrms.');
            }
        }
    }
}

// ---------------------------------------------------------------------------
echo "\n", str_repeat('=', 66), "\n";
foreach ($rows as $r) {
    echo $r, "\n";
}

echo "\n", str_repeat('=', 66), "\n";
if ($problems === []) {
    echo "No problems found. If the site still misbehaves, check the host's error log\n";
    echo "(cPanel -> Metrics -> Errors) and storage/logs/php-error.log.\n";
} else {
    echo count($problems), " problem(s) found:\n\n";
    foreach ($problems as $i => $p) {
        echo $i + 1, '. ', wordwrap($p, 62, "\n   "), "\n\n";
    }
}

echo str_repeat('=', 66), "\n";
echo "To test Bearer auth pass-through (the Android app depends on it), run\n";
echo "this from your own machine and expect \"yes\":\n\n";
echo "  curl -s -H 'Authorization: Bearer test' 'https://your-domain/diag.php?i-understand=1' | grep Authorization\n\n";
echo "NOW DELETE THIS FILE.\n";
