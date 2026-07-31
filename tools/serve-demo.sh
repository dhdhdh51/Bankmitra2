#!/bin/sh
# ---------------------------------------------------------------------------
# Boots a seeded panel and LEAVES IT RUNNING, for looking at the UI in a real
# browser. Every other harness tears its stack down; a design change can only be
# checked by actually rendering it.
#
#   sh tools/serve-demo.sh          # http://127.0.0.1:8092
#   sh tools/serve-demo.sh --stop
#
# Sign in with ADMIN001 / Admin@123 (it will force a password change) or use the
# demo manager MGR001 / Manager@123, which does not.
# ---------------------------------------------------------------------------
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
APP=$ROOT/admin
CT=lrms-demo
DB_PORT=13312
WEB_PORT=8092
PIDFILE=$ROOT/.verify/demo-web.pid

if [ "${1:-}" = '--stop' ]; then
    [ -f "$PIDFILE" ] && kill "$(cat "$PIDFILE")" 2>/dev/null || true
    rm -f "$PIDFILE" "$APP/config/config.php"
    docker rm -f "$CT" > /dev/null 2>&1 || true
    echo 'demo stopped'
    exit 0
fi

mkdir -p "$ROOT/.verify"
docker rm -f "$CT" > /dev/null 2>&1 || true
[ -f "$PIDFILE" ] && kill "$(cat "$PIDFILE")" 2>/dev/null || true

echo "==> MySQL on $DB_PORT"
docker run -d --name "$CT" -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=lrms \
    -p "$DB_PORT":3306 mysql:8.0 > /dev/null
i=0
while [ "$i" -lt 120 ]; do
    docker exec "$CT" mysql -uroot -proot -e 'SELECT 1' lrms > /dev/null 2>&1 && break
    i=$((i + 1)); sleep 2
done
[ "$i" -lt 120 ] || { echo '!! MySQL never became ready'; exit 1; }
docker exec -i "$CT" mysql -uroot -proot lrms < "$ROOT/schema.sql" 2>&1 | grep -v 'Using a password' || true

K1=$(php -r 'echo bin2hex(random_bytes(32));')
K2=$(php -r 'echo bin2hex(random_bytes(32));')
K3=$(php -r 'echo bin2hex(random_bytes(32));')
cat > "$APP/config/config.php" <<PHPEOF
<?php
return [
    'db' => ['host' => '127.0.0.1', 'port' => $DB_PORT, 'name' => 'lrms',
             'user' => 'root', 'pass' => 'root', 'charset' => 'utf8mb4'],
    'app_key' => '$K1', 'data_key' => '$K2', 'hash_pepper' => '$K3',
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

echo '==> seeding'
LRMS_APP_ROOT="$APP" LRMS_DB_HOST=127.0.0.1 LRMS_DB_PORT="$DB_PORT" LRMS_DB_NAME=lrms \
LRMS_DB_USER=root LRMS_DB_PASS=root php "$ROOT/tools/seed-demo.php" 2>&1 | tail -2

php -S 127.0.0.1:"$WEB_PORT" -t "$APP" "$ROOT/tools/router-dev.php" \
    > "$ROOT/.verify/demo-web.log" 2>&1 &
echo $! > "$PIDFILE"
sleep 2

echo
echo "panel:  http://127.0.0.1:$WEB_PORT"
echo "login:  MGR001 / Manager@123   (no forced password change)"
echo "stop:   sh tools/serve-demo.sh --stop"
