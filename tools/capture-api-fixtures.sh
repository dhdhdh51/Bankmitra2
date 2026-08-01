#!/bin/sh
# ---------------------------------------------------------------------------
# Captures real API responses into JSON fixtures for the Android contract test.
#
# The app's 69 unit tests are all pure logic - the Android client has never
# deserialised a single real response from this server. Gson does not complain
# about an unknown or missing key: it just leaves the field null or 0. A renamed
# field therefore shows up as a permanently empty screen with no error anywhere,
# which is the hardest possible failure to diagnose from a phone.
#
# These fixtures are captured from a live server and committed, so
# ApiContractTest can deserialise them with the real DTOs on every CI run.
#
#   sh tools/capture-api-fixtures.sh
# ---------------------------------------------------------------------------
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
APP=$ROOT/admin
OUT=$ROOT/android/app/src/test/resources/api
WORK=$ROOT/.verify/fixtures-api
CT=lrms-fixtures
DB_PORT=13310
WEB_PORT=8094

cleanup() {
    [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true
    docker rm -f "$CT" > /dev/null 2>&1 || true
    rm -f "$APP/config/config.php"
}
trap cleanup EXIT INT TERM

cleanup
rm -rf "$WORK"; mkdir -p "$WORK" "$OUT"

echo "==> MySQL on $DB_PORT"
docker run -d --name "$CT" -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=lrms \
    -p "$DB_PORT":3306 mysql:8.0 > /dev/null
i=0
while [ "$i" -lt 120 ]; do
    docker exec "$CT" mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot -proot -e 'SELECT 1' lrms > /dev/null 2>&1 && break
    i=$((i + 1)); sleep 2
done
[ "$i" -lt 120 ] || { echo '!! MySQL never became ready'; exit 1; }
docker exec -i "$CT" mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot -proot lrms < "$ROOT/schema.sql" 2>&1 | grep -v 'Using a password' || true

APP_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
DATA_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
PEPPER=$(php -r 'echo bin2hex(random_bytes(32));')
cat > "$APP/config/config.php" <<PHPEOF
<?php
return [
    'db' => ['host' => '127.0.0.1', 'port' => $DB_PORT, 'name' => 'lrms',
             'user' => 'root', 'pass' => 'root', 'charset' => 'utf8mb4'],
    'app_key' => '$APP_KEY', 'data_key' => '$DATA_KEY', 'hash_pepper' => '$PEPPER',
    'app' => ['url' => 'http://127.0.0.1:$WEB_PORT', 'base_path' => '', 'env' => 'local',
              'debug' => true, 'timezone' => 'Asia/Kolkata'],
    'paths' => ['uploads' => __DIR__ . '/../uploads', 'storage' => __DIR__ . '/../storage'],
    'uploads' => ['max_photo_bytes' => 8388608, 'max_document_bytes' => 12582912,
        'max_import_bytes' => 26214400,
        'allowed_image_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_doc_mime' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']],
    'session' => ['name' => 'lrms_session', 'lifetime' => 7200, 'secure' => false],
];
PHPEOF

LRMS_APP_ROOT="$APP" LRMS_DB_HOST=127.0.0.1 LRMS_DB_PORT="$DB_PORT" LRMS_DB_NAME=lrms \
LRMS_DB_USER=root LRMS_DB_PASS=root php "$ROOT/tools/seed-demo.php" > "$WORK/seed.log" 2>&1 \
    || { cat "$WORK/seed.log"; exit 1; }

php -S 127.0.0.1:"$WEB_PORT" -t "$APP" "$ROOT/tools/router-dev.php" > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
i=0
while [ "$i" -lt 40 ]; do
    curl -s -o /dev/null "http://127.0.0.1:$WEB_PORT/login" && break
    i=$((i + 1)); sleep 0.5
done

B="http://127.0.0.1:$WEB_PORT/api/v1"

pretty() { php -r '$j=stream_get_contents(STDIN); $d=json_decode($j,true);
    echo $d === null ? $j : json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);'; }

save() { # save <name> <json>
    printf '%s' "$2" | pretty > "$OUT/$1.json"
    printf '  %-28s %6s bytes\n' "$1.json" "$(wc -c < "$OUT/$1.json" | tr -d ' ')"
}

echo '==> capturing'
LOGIN=$(curl -s -X POST "$B/auth/login" -H 'Content-Type: application/json' \
    -d '{"employee_code":"AGT001","password":"Agent@123","app_version":"1.0.0"}')
save login "$LOGIN"

TOKEN=$(printf '%s' "$LOGIN" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["access_token"] ?? "";')
[ -n "$TOKEN" ] || { echo '!! login failed'; printf '%s\n' "$LOGIN"; exit 1; }
A="Authorization: Bearer $TOKEN"

save ping        "$(curl -s "$B/ping")"
save me          "$(curl -s -H "$A" "$B/auth/me")"
save meta        "$(curl -s -H "$A" "$B/meta")"
save dashboard   "$(curl -s -H "$A" "$B/dashboard")"
save leads       "$(curl -s -H "$A" "$B/leads?per_page=3")"
# The server rejects a single character, so this must be a realistic query or the
# fixture captures a validation error instead of a result set.
save leads_search "$(curl -s -H "$A" "$B/leads/search?q=ra&per_page=3")"
save promises    "$(curl -s -H "$A" "$B/promises?per_page=3")"
save notifications "$(curl -s -H "$A" "$B/notifications?per_page=3")"
save unread_count "$(curl -s -H "$A" "$B/notifications/unread-count")"
save form_options "$(curl -s -H "$A" "$B/visits/form-options")"

# Pick a lead that actually HAS a visit, taken from the unfiltered visit feed.
# Using simply the first assigned lead produced an empty array, which would have
# made the contract test assert nothing at all about a populated visit list.
FEED=$(curl -s -H "$A" "$B/visits?per_page=5")
save visits_feed "$FEED"
LEAD_ID=$(printf '%s' "$FEED" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    $r=$d["data"] ?? []; $r=$r["data"] ?? $r; echo $r[0]["loan_account_id"] ?? "";')
VISIT_ID=$(printf '%s' "$FEED" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    $r=$d["data"] ?? []; $r=$r["data"] ?? $r; echo $r[0]["id"] ?? "";')

if [ -z "$LEAD_ID" ]; then
    LEAD_ID=$(curl -s -H "$A" "$B/leads?per_page=1" \
        | php -r '$d=json_decode(stream_get_contents(STDIN),true);
                  $r=$d["data"] ?? []; $r=$r["data"] ?? $r; echo $r[0]["id"] ?? "";')
fi

if [ -n "$LEAD_ID" ]; then
    save customer_profile "$(curl -s -H "$A" "$B/customers/$LEAD_ID")"
    save customer_history "$(curl -s -H "$A" "$B/customers/$LEAD_ID/history")"
    VFL=$(curl -s -H "$A" "$B/visits?loan_account_id=$LEAD_ID")
    save visits_for_lead "$VFL"
    N=$(printf '%s' "$VFL" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
        echo count($d["data"] ?? []);')
    [ "$N" -gt 0 ] || echo "  !! visits_for_lead is empty for lead $LEAD_ID"
else
    echo '  !! no lead id found - the agent has no assigned leads'
fi

[ -n "$VISIT_ID" ] && save visit_detail "$(curl -s -H "$A" "$B/visits/$VISIT_ID")" \
    || echo '  !! no visit id found'

# Error shapes matter as much as success shapes - the app parses them too.
save error_401 "$(curl -s "$B/auth/me")"
save error_422 "$(curl -s -X POST "$B/auth/login" -H 'Content-Type: application/json' -d '{"employee_code":"AGT001"}')"
save error_404 "$(curl -s -H "$A" "$B/nope")"

echo
echo "fixtures written to $OUT"
ls -1 "$OUT" | wc -l | xargs printf '  %s files\n'
