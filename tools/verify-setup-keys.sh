#!/bin/sh
# ---------------------------------------------------------------------------
# Proves setup-keys.php does the right thing in every case that matters,
# because it edits PHP source in production and a bad rewrite would take the
# whole site down.
#
#   sh tools/verify-setup-keys.sh
# ---------------------------------------------------------------------------
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK="$ROOT/.verify/setupkeys"
pass=0
fail=0

ok()   { pass=$((pass + 1)); printf '  ok    %s\n' "$1"; }
bad()  { fail=$((fail + 1)); printf '  FAIL  %s\n' "$1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (want '$3', got '$2')"; fi; }
has()  { if printf '%s' "$2" | grep -qF "$3"; then ok "$1"; else bad "$1 (missing '$3')"; fi; }
hasnt() { if printf '%s' "$2" | grep -qF "$3"; then bad "$1 (unexpectedly found '$3')"; else ok "$1"; fi; }

# A throwaway docroot holding just what setup-keys.php touches.
fresh_root() {
    rm -rf "$WORK"
    mkdir -p "$WORK/config"
    cp "$ROOT/tools/hosting-setup-keys.php" "$WORK/setup-keys.php"
    cp "$ROOT/admin/config/config.sample.php" "$WORK/config/config.sample.php"
}

# Run it as the web server would, capturing stdout and the exit status.
run() {
    ( cd "$WORK" && QUERY_STRING="${1:-}" php -r '
        parse_str(getenv("QUERY_STRING") ?: "", $_GET);
        require "setup-keys.php";
    ' 2>&1 ) || return $?
}

keyval() {  # keyval <name> -> the value stored in config.php
    php -r '$c = include $argv[1]; echo $c[$argv[2]] ?? "";' "$WORK/config/config.php" "$1"
}

echo 'setup-keys.php'
echo '=============='

# ---------------------------------------------------------------------------
echo
echo '1. no config.php yet'
# ---------------------------------------------------------------------------
fresh_root
out=$(run 'generate=1' || true)
has 'tells you to copy the template' "$out" 'cp config/config.sample.php config/config.php'
[ -f "$WORK/config/config.php" ] && bad 'must not invent a config.php' || ok 'does not invent a config.php'

# ---------------------------------------------------------------------------
echo
echo '2. all three keys blank - the state the live server is in'
# ---------------------------------------------------------------------------
fresh_root
cp "$WORK/config/config.sample.php" "$WORK/config/config.php"
# Give it real-looking db credentials so we can prove they survive.
php -r '
$f = $argv[1];
$s = file_get_contents($f);
$s = str_replace("\x27name\x27    => \x27lrms\x27", "\x27name\x27    => \x27contlxlu_has\x27", $s);
$s = str_replace("\x27pass\x27    => \x27change-me\x27", "\x27pass\x27    => \x27s3cret!pass\x27", $s);
file_put_contents($f, $s);
' "$WORK/config/config.php"

out=$(run || true)
has 'dry run lists the blank keys' "$out" 'app_key      EMPTY'
has 'dry run names data_key' "$out" 'data_key     EMPTY'
has 'dry run names hash_pepper' "$out" 'hash_pepper  EMPTY'
has 'dry run explains how to proceed' "$out" 'setup-keys.php?generate=1'
check 'dry run changes nothing' "$(keyval app_key)" ''

out=$(run 'generate=1' || true)
has 'reports what it wrote' "$out" 'WROTE 3 key(s)'
hasnt 'never prints a key value' "$out" "$(keyval data_key)"

for k in app_key data_key hash_pepper; do
    v=$(keyval "$k")
    check "$k is 64 characters" "${#v}" '64'
    if printf '%s' "$v" | grep -qE '^[0-9a-f]{64}$'; then ok "$k is hex"; else bad "$k is not hex"; fi
done

a=$(keyval app_key); d=$(keyval data_key); p=$(keyval hash_pepper)
if [ "$a" != "$d" ] && [ "$d" != "$p" ] && [ "$a" != "$p" ]; then
    ok 'the three keys differ from each other'
else
    bad 'keys are not independent'
fi

check 'db name survived the rewrite' "$(php -r '$c=include $argv[1]; echo $c["db"]["name"];' "$WORK/config/config.php")" 'contlxlu_has'
check 'db password survived the rewrite' "$(php -r '$c=include $argv[1]; echo $c["db"]["pass"];' "$WORK/config/config.php")" 's3cret!pass'
check 'unrelated settings survived' "$(php -r '$c=include $argv[1]; echo $c["app"]["timezone"];' "$WORK/config/config.php")" 'Asia/Kolkata'
check 'file still parses' "$(php -l "$WORK/config/config.php" > /dev/null 2>&1 && echo yes || echo no)" 'yes'

if ls "$WORK"/config/config.php.bak-* > /dev/null 2>&1; then
    ok 'kept a backup of the original'
else
    bad 'no backup was written'
fi

# ---------------------------------------------------------------------------
echo
echo '3. run again - existing keys must be untouched (this is the dangerous case)'
# ---------------------------------------------------------------------------
before_d=$(keyval data_key)
before_p=$(keyval hash_pepper)
out=$(run 'generate=1' || true)
has 'says there is nothing to do' "$out" 'All three keys are set'
check 'data_key unchanged' "$(keyval data_key)" "$before_d"
check 'hash_pepper unchanged' "$(keyval hash_pepper)" "$before_p"
has 'still tells you to delete it' "$out" 'DELETE THIS FILE'

# ---------------------------------------------------------------------------
echo
echo '4. partially configured - fill only the blank one'
# ---------------------------------------------------------------------------
fresh_root
cp "$WORK/config/config.sample.php" "$WORK/config/config.php"
KEPT='aaaabbbbccccddddeeeeffff00001111aaaabbbbccccddddeeeeffff00002222'
php -r '
$f = $argv[1]; $k = $argv[2];
$s = file_get_contents($f);
$s = preg_replace("/(\x27app_key\x27\s*=> )\x27\x27/", "$1\x27$k\x27", $s, 1);
$s = preg_replace("/(\x27data_key\x27\s*=> )\x27\x27/", "$1\x27$k\x27", $s, 1);
file_put_contents($f, $s);
' "$WORK/config/config.php" "$KEPT"

out=$(run 'generate=1' || true)
has 'only the blank key is written' "$out" 'WROTE 1 key(s): hash_pepper'
check 'pre-existing app_key preserved exactly' "$(keyval app_key)" "$KEPT"
check 'pre-existing data_key preserved exactly' "$(keyval data_key)" "$KEPT"
newp=$(keyval hash_pepper)
check 'hash_pepper now 64 chars' "${#newp}" '64'
if [ "$newp" = "$KEPT" ]; then bad 'hash_pepper reused another key'; else ok 'hash_pepper is its own value'; fi

# ---------------------------------------------------------------------------
echo
echo '5. malformed config.php is refused, not mangled'
# ---------------------------------------------------------------------------
fresh_root
printf '<?php\n$x = 1;\n' > "$WORK/config/config.php"
sum_before=$(sha256sum "$WORK/config/config.php" | cut -d' ' -f1)
out=$(run 'generate=1' || true)
has 'explains the file must return an array' "$out" 'must start with'
check 'the broken file was left alone' "$(sha256sum "$WORK/config/config.php" | cut -d' ' -f1)" "$sum_before"

# A config that returns an array but has no key entries at all.
printf "<?php return ['db' => ['host' => 'localhost', 'name' => 'x', 'user' => 'y']];\n" \
    > "$WORK/config/config.php"
sum_before=$(sha256sum "$WORK/config/config.php" | cut -d' ' -f1)
out=$(run 'generate=1' || true)
has 'offers a line to paste when the entry is absent' "$out" 'by hand'
check 'nothing was written to an unrecognised config' "$(sha256sum "$WORK/config/config.php" | cut -d' ' -f1)" "$sum_before"

# ---------------------------------------------------------------------------
echo
echo '6. read-only config.php fails safely'
# ---------------------------------------------------------------------------
fresh_root
cp "$WORK/config/config.sample.php" "$WORK/config/config.php"
chmod 444 "$WORK/config/config.php"
if [ "$(id -u)" = '0' ]; then
    ok 'skipped: running as root, which ignores the write bit'
else
    out=$(run 'generate=1' || true)
    has 'names the permission problem' "$out" 'not writable'
    check 'still blank afterwards' "$(keyval app_key)" ''
fi
chmod 644 "$WORK/config/config.php"

# ---------------------------------------------------------------------------
echo
echo '7. the generated config satisfies the real bootstrap validator'
# ---------------------------------------------------------------------------
fresh_root
cp "$WORK/config/config.sample.php" "$WORK/config/config.php"
out=$(run 'generate=1' || true)
has 'keys written' "$out" 'WROTE 3 key(s)'
# Replay bootstrap's own rules against the result.
verdict=$(php -r '
$c = include $argv[1];
$bad = [];
foreach (["app_key", "data_key", "hash_pepper"] as $k) {
    $v = (string) ($c[$k] ?? "");
    if ($v === "" || strlen($v) < 16 || str_contains($v, "PASTE") || str_contains($v, "CHANGE")) {
        $bad[] = $k;
    }
}
echo $bad === [] ? "accepted" : "rejected: " . implode(",", $bad);
' "$WORK/config/config.php")
check 'bootstrap would accept these keys' "$verdict" 'accepted'

# And Crypto must actually round-trip with them.
roundtrip=$(cd "$ROOT/admin" && LRMS_CONFIG="$WORK/config/config.php" php -r '
require __DIR__ . "/app/Core/Config.php";
require __DIR__ . "/app/Core/Crypto.php";
$c = include getenv("LRMS_CONFIG");
App\Core\Config::load($c);
App\Core\Crypto::reset();
$enc = App\Core\Crypto::encrypt("9876543210");
echo App\Core\Crypto::decrypt($enc) === "9876543210" && strlen((string) App\Core\Crypto::searchHash("9876543210")) > 0
    ? "works" : "broken";
' 2>&1 | tail -1)
check 'Crypto encrypts and hashes with the generated keys' "$roundtrip" 'works'

rm -rf "$WORK"

echo
echo "=============="
printf 'passed %d, failed %d\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
