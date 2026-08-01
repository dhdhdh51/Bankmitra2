<?php
/**
 * D2 Recovery one-time key setup.
 *
 * Ships in the hosting package as setup-keys.php. Its only job is to fill the
 * three cryptographic keys in config/config.php so nobody has to hand-edit PHP
 * source over FTP - a step that has silently produced a broken install (login
 * 500s, "user could not be created") more than once.
 *
 *   https://your-domain/setup-keys.php              shows what is missing
 *   https://your-domain/setup-keys.php?generate=1   writes the missing keys
 *
 * WHY WRITING BLANK KEYS IS SAFE
 *   Crypto::deriveKey() throws when a key is blank, so any column that would
 *   have been encrypted with it could never have been written. A blank key
 *   therefore proves no data depends on it, and filling it in cannot orphan
 *   anything. A key that is ALREADY set is never touched, for the opposite
 *   reason: stored ciphertext and search hashes depend on its exact value.
 *
 * Delete this file once the panel loads.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$root       = __DIR__;
$configFile = $root . '/config/config.php';
$sampleFile = $root . '/config/config.sample.php';
$keys       = ['app_key', 'data_key', 'hash_pepper'];

echo "D2 Recovery key setup\n";
echo str_repeat('=', 66), "\n\n";

function bail(string $msg): never
{
    echo "!! $msg\n";
    exit(1);
}

if (!is_file($configFile)) {
    echo "config/config.php does not exist yet.\n\n";
    if (is_file($sampleFile)) {
        echo "Copy the template first, then reload this page:\n";
        echo "  cp config/config.sample.php config/config.php\n\n";
        echo "In cPanel File Manager: open config/, right-click config.sample.php,\n";
        echo "Copy, and name the copy config.php. Then fill in the database\n";
        echo "credentials (host/name/user/pass) and reload this page.\n";
    } else {
        echo "config/config.sample.php is missing too - the upload is incomplete.\n";
    }
    exit(1);
}

$cfg = @include $configFile;
if (!is_array($cfg)) {
    bail('config/config.php does not return an array. It must start with "<?php return [".');
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$isSet = static fn (string $k): bool =>
    isset($cfg[$k]) && is_string($cfg[$k]) && strlen($cfg[$k]) >= 32;

$missing = [];
foreach ($keys as $k) {
    $ok = $isSet($k);
    printf("  %-12s %s\n", $k, $ok ? 'already set  (will not be touched)' : 'EMPTY');
    if (!$ok) {
        $missing[] = $k;
    }
}
echo "\n";

if ($missing === []) {
    echo "All three keys are set. Nothing to do.\n\n";
    echo "DELETE THIS FILE NOW: setup-keys.php\n";
    exit(0);
}

if (($_GET['generate'] ?? '') !== '1') {
    echo count($missing), " key(s) need to be generated: ", implode(', ', $missing), "\n\n";
    echo "To generate and write them, load:\n";
    echo "  setup-keys.php?generate=1\n\n";
    echo "This is safe right now: a blank key means nothing in the database was\n";
    echo "ever encrypted with it. Keys that are already set stay untouched.\n\n";
    echo "AFTERWARDS: data_key and hash_pepper must NEVER change again - they\n";
    echo "decrypt stored mobile/Aadhaar numbers and derive their search hashes.\n";
    echo "Back up config/config.php somewhere safe once this succeeds.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------
if (!is_writable($configFile)) {
    bail('config/config.php is not writable by PHP. chmod 644 config/config.php and retry.');
}

$source = file_get_contents($configFile);
if ($source === false || $source === '') {
    bail('could not read config/config.php');
}

$updated  = $source;
$generated = [];
foreach ($missing as $k) {
    $value = bin2hex(random_bytes(32));
    // Match  'app_key' => '',   "app_key"=>"" ,  with any spacing.
    $pattern = '/([\'"]' . preg_quote($k, '/') . '[\'"]\s*=>\s*)([\'"])\2/';
    $count = 0;
    $updated = preg_replace($pattern, '${1}\'' . $value . '\'', $updated, 1, $count);
    if ($updated === null) {
        bail("failed while rewriting '$k'");
    }
    if ($count !== 1) {
        bail("could not find an empty '$k' entry in config/config.php. Add this line "
            . "inside the returned array by hand:\n     '$k' => '" . $value . "',");
    }
    $generated[] = $k;
}

// Prove the rewritten file is valid and carries what we intended BEFORE it
// replaces a working config. A unique temp name dodges the opcache.
$tmp = $root . '/config/.config-new-' . bin2hex(random_bytes(6)) . '.php';
if (file_put_contents($tmp, $updated, LOCK_EX) === false) {
    bail('could not write to config/ - check permissions on the directory.');
}

$check = @include $tmp;
$valid = is_array($check);
if ($valid) {
    foreach ($keys as $k) {
        if (!isset($check[$k]) || !is_string($check[$k]) || strlen($check[$k]) < 32) {
            $valid = false;
            break;
        }
    }
}
// The database block must survive the edit untouched.
if ($valid && (($check['db'] ?? null) != ($cfg['db'] ?? null))) {
    $valid = false;
}
if (!$valid) {
    @unlink($tmp);
    bail('the rewritten config did not validate, so nothing was changed. '
        . 'Set the keys by hand instead - see README.md.');
}

// Keep a copy of the original. config/.htaccess denies web access to this dir,
// so the backup is not downloadable.
$backup = $root . '/config/config.php.bak-' . date('Ymd-His');
@copy($configFile, $backup);

if (!@rename($tmp, $configFile)) {
    @unlink($tmp);
    bail('could not replace config/config.php. Nothing was changed.');
}
@chmod($configFile, 0644);
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate($configFile, true);
}

echo "WROTE ", count($generated), " key(s): ", implode(', ', $generated), "\n";
echo "  backup of the previous file: config/", basename($backup), "\n\n";
echo "The values are not shown here on purpose. They are in config/config.php.\n\n";
echo str_repeat('=', 66), "\n";
echo "NEXT\n";
echo "  1. Open the panel and sign in.\n";
echo "  2. Download config/config.php and keep it somewhere safe. If you lose\n";
echo "     data_key you lose every stored mobile and Aadhaar number.\n";
echo "  3. DELETE THIS FILE: setup-keys.php   (and diag.php)\n";
