#!/bin/sh
# ---------------------------------------------------------------------------
# Regression tests for diag.php.
#
# It exists to tell someone what is wrong with their install, so a FALSE alarm
# is nearly as harmful as a missed one - it sends them chasing a non-problem.
# The 0750 document-root check did exactly that on a cPanel/LiteSpeed host,
# where PHP runs as the account owner and 0750 is correct.
#
#   sh tools/verify-hosting-diag.sh
# ---------------------------------------------------------------------------
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK="$ROOT/.verify/diagcheck"
pass=0
fail=0

ok()  { pass=$((pass + 1)); printf '  ok    %s\n' "$1"; }
bad() { fail=$((fail + 1)); printf '  FAIL  %s\n' "$1"; }
# diag.php word-wraps its advice, so a phrase can straddle a line break.
# Collapse whitespace on both sides before matching.
flat()  { printf '%s' "$1" | tr '\n' ' ' | tr -s ' '; }
has()   { if flat "$2" | grep -qF "$(flat "$3")"; then ok "$1"; else bad "$1 (missing '$3')"; fi; }
hasnt() { if flat "$2" | grep -qF "$(flat "$3")"; then bad "$1 (found '$3')"; else ok "$1"; fi; }

# A minimal install tree.
fresh() {
    rm -rf "$WORK"
    mkdir -p "$WORK/config" "$WORK/app" "$WORK/views" "$WORK/assets" \
             "$WORK/storage/logs" "$WORK/storage/backups" "$WORK/storage/imports" \
             "$WORK/storage/tmp" "$WORK/uploads"
    cp "$ROOT/tools/hosting-diag.php" "$WORK/diag.php"
    printf '<?php\n' > "$WORK/index.php"
    printf '# rules\n' > "$WORK/.htaccess"
    printf '<?php\n' > "$WORK/app/bootstrap.php"
    printf -- '-- sql\n' > "$WORK/schema.sql"
    cp "$ROOT/admin/config/config.sample.php" "$WORK/config/config.sample.php"
}

run() { ( cd "$WORK" && QUERY_STRING='i-understand=1' php -r '
    parse_str(getenv("QUERY_STRING"), $_GET);
    require "diag.php";
' 2>&1 ); }

echo 'diag.php'
echo '========'

# ---------------------------------------------------------------------------
echo
echo '1. the gate'
# ---------------------------------------------------------------------------
fresh
gated=$( cd "$WORK" && php -r '$_GET = []; require "diag.php";' 2>&1 )
has 'says nothing useful without the flag' "$gated" 'Add ?i-understand=1'
hasnt 'no PHP version leaked to a crawler' "$gated" 'PHP version'

# ---------------------------------------------------------------------------
echo
echo '2. 0750 root served by its owner is NOT a problem (the false alarm)'
# ---------------------------------------------------------------------------
fresh
chmod 750 "$WORK"
out=$(run)
has 'reports the root as readable' "$out" 'document root readable by the web server'
has 'explains the suexec serving model' "$out" 'suexec-style'
hasnt 'does not claim a 403 permission fault' "$out" 'which is exactly what produces a bare 403'
hasnt 'does not tell you to chmod 755' "$out" 'chmod 755 .'

# ---------------------------------------------------------------------------
echo
echo '3. a root PHP cannot enter IS still reported'
# ---------------------------------------------------------------------------
> "$WORK/.keep" 2>/dev/null || true
if chown 65534:65534 "$WORK" 2>/dev/null; then
    # Hand the root to another uid so PHP is no longer its owner - the mod_php
    # /www-data situation. Keeping our own uid for the run means we can still
    # read the tree, which is what lets us observe the verdict.
    fresh
    chown 65534:65534 "$WORK"
    chmod 750 "$WORK"
    out=$(run)
    has 'flags a root the web server cannot enter' "$out" 'produces a bare 403'
    has 'gives the chmod fix' "$out" 'chmod 755 .'
    hasnt 'does not claim the suexec model' "$out" 'suexec-style'

    chmod 755 "$WORK"
    out=$(run)
    hasnt '0755 under a foreign owner is accepted' "$out" 'produces a bare 403'
    chown "$(id -u):$(id -g)" "$WORK" 2>/dev/null || true
else
    ok 'skipped: cannot chown, so the non-owner branch is unreachable here'
fi

# ---------------------------------------------------------------------------
echo
echo '4. world-writable root is still refused'
# ---------------------------------------------------------------------------
fresh
chmod 777 "$WORK"
out=$(run)
has 'flags group- and world-writable' "$out" 'world-writable'
has 'says never use 777' "$out" 'Never use 777'
chmod 755 "$WORK"

# ---------------------------------------------------------------------------
echo
echo '5. blank keys are still caught, values never printed'
# ---------------------------------------------------------------------------
fresh
cp "$WORK/config/config.sample.php" "$WORK/config/config.php"
out=$(run)
has 'app_key flagged' "$out" 'key app_key'
has 'data_key flagged' "$out" 'data_key is not set'
has 'hash_pepper flagged' "$out" 'hash_pepper is not set'

SECRET='deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
php -r '
$f = $argv[1]; $k = $argv[2];
$s = file_get_contents($f);
$s = preg_replace("/(\x27(?:app_key|data_key|hash_pepper)\x27\s*=> )\x27\x27/", "$1\x27$k\x27", $s);
$s = str_replace("\x27pass\x27    => \x27change-me\x27", "\x27pass\x27    => \x27topsecretpw\x27", $s);
file_put_contents($f, $s);
' "$WORK/config/config.php" "$SECRET"
out=$(run)
has 'keys now reported as set' "$out" 'set'
hasnt 'never prints a key value' "$out" "$SECRET"
hasnt 'never prints the database password' "$out" 'topsecretpw'

# ---------------------------------------------------------------------------
echo
echo '6. a wrong upload shape is diagnosed'
# ---------------------------------------------------------------------------
fresh
rm "$WORK/index.php"
out=$(run)
has 'catches the folder-instead-of-contents mistake' "$out" 'uploaded the folder instead of its CONTENTS'
fresh
rm "$WORK/.htaccess"
out=$(run)
has 'catches a skipped .htaccess' "$out" 'hidden files were skipped'

# ---------------------------------------------------------------------------
echo
echo '7. an unwritable storage directory is reported'
# ---------------------------------------------------------------------------
fresh
if [ "$(id -u)" = '0' ]; then
    ok 'skipped: root ignores the write bit'
else
    chmod 555 "$WORK/storage/logs"
    out=$(run)
    has 'names the directory' "$out" 'storage/logs is not writable'
    chmod 755 "$WORK/storage/logs"
fi

# ---------------------------------------------------------------------------
echo
echo '8. a clean install reports no problems'
# ---------------------------------------------------------------------------
fresh
php -r '
$f = $argv[1];
$s = file_get_contents($argv[2]);
$s = preg_replace("/(\x27(?:app_key|data_key|hash_pepper)\x27\s*=> )\x27\x27/",
    "$1\x27" . str_repeat("ab", 32) . "\x27", $s);
file_put_contents($f, $s);
' "$WORK/config/config.php" "$WORK/config/config.sample.php"
out=$(run)
# The database is not reachable from this throwaway root, so ignore that one.
problems=$(printf '%s' "$out" | grep -c 'problem(s) found' || true)
hasnt 'no permission complaint on a clean tree' "$out" 'produces a bare 403'
hasnt 'no layout complaint on a clean tree' "$out" 'uploaded the folder'
hasnt 'no key complaint once keys are set' "$out" 'is not set. Generate one'
has 'still reminds you to delete the file' "$out" 'NOW DELETE THIS FILE'

rm -rf "$WORK"

echo
echo '========'
printf 'passed %d, failed %d\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
