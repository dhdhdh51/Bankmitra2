#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# Boots the admin panel on PHP's built-in server against a throwaway MySQL 8,
# logs in as the seeded super admin, and requests every route to prove the
# pages actually render (no 500s, no missing views, no undefined variables).
#
#   sh tools/smoke-panel.sh
# ---------------------------------------------------------------------------
set -e

CT=lrms-smoke
DB_PORT=13307
WEB_PORT=8099
ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
mkdir -p "$ROOT_DIR/.verify"

# Which directory to serve. Defaults to the repository's admin/, but can be
# pointed at a built hosting package so the exact tree that gets uploaded to
# shared hosting is the tree these tests exercise:
#   LRMS_DOCROOT=.verify/hosting sh tools/smoke-panel.sh
DOCROOT=${LRMS_DOCROOT:-$ROOT_DIR/admin}
DOCROOT=$(cd "$DOCROOT" && pwd)
[ -f "$DOCROOT/index.php" ] || { echo "!! $DOCROOT has no index.php"; exit 1; }
echo "==> document root: $DOCROOT"

cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
  docker rm -f "$CT" >/dev/null 2>&1 || true
  rm -f "$DOCROOT/config/config.php"
}
trap cleanup EXIT

docker rm -f "$CT" >/dev/null 2>&1 || true

echo "==> starting MySQL 8 on port $DB_PORT"
docker run -d --name "$CT" \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=lrms \
  -p "$DB_PORT":3306 \
  mysql:8.0 >/dev/null

# Probed over TCP, not the socket: the entrypoint's temporary initialisation server
# answers on the socket and is then shut down, so a socket probe can report ready
# moments before the server disappears and the schema import hits nothing.
echo "==> waiting for the real server to accept TCP connections"
READY=0
i=0
while [ "$i" -lt 120 ]; do
  if docker exec "$CT" mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot -proot -e "SELECT 1" >/dev/null 2>&1; then
    READY=1
    break
  fi
  i=$((i + 1))
  sleep 2
done
[ "$READY" -eq 1 ] || { echo "!! MySQL not ready"; docker logs --tail 30 "$CT"; exit 1; }

docker exec -i "$CT" mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot -proot -e \
  "CREATE DATABASE IF NOT EXISTS lrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
docker exec -i "$CT" mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot -proot lrms < "$ROOT_DIR/schema.sql" 2>&1 | grep -v 'Using a password' || true

echo "==> writing a temporary config.php"
APP_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
DATA_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
PEPPER=$(php -r 'echo bin2hex(random_bytes(32));')

cat > "$DOCROOT/config/config.php" <<PHPEOF
<?php
return [
    'db' => [
        'host' => '127.0.0.1', 'port' => $DB_PORT, 'name' => 'lrms',
        'user' => 'root', 'pass' => 'root', 'charset' => 'utf8mb4',
    ],
    'app_key' => '$APP_KEY',
    'data_key' => '$DATA_KEY',
    'hash_pepper' => '$PEPPER',
    'app' => ['url' => 'http://127.0.0.1:$WEB_PORT', 'base_path' => '', 'env' => 'local', 'debug' => true, 'timezone' => 'Asia/Kolkata'],
    'paths' => ['uploads' => __DIR__ . '/../uploads', 'storage' => __DIR__ . '/../storage'],
    'uploads' => [
        'max_photo_bytes' => 8388608, 'max_document_bytes' => 12582912, 'max_import_bytes' => 26214400,
        'allowed_image_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_doc_mime' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],
    'session' => ['name' => 'lrms_session', 'lifetime' => 7200, 'secure' => false],
];
PHPEOF

echo "==> seeding demo data"
# LRMS_APP_ROOT so the seeder loads the same config.php - and therefore the same
# data_key and hash_pepper - as the server under test. Without it the seeder
# invents random keys and every encrypted field reads back as null.
LRMS_APP_ROOT="$DOCROOT" \
LRMS_DB_HOST=127.0.0.1 LRMS_DB_PORT="$DB_PORT" LRMS_DB_NAME=lrms \
LRMS_DB_USER=root LRMS_DB_PASS=root \
php "$ROOT_DIR/tools/seed-demo.php"

echo "==> starting PHP built-in server on port $WEB_PORT"
php -S 127.0.0.1:"$WEB_PORT" -t "$DOCROOT" "$ROOT_DIR/tools/router-dev.php" \
  > "$ROOT_DIR/.verify/server.log" 2>&1 &
SERVER_PID=$!

i=0
while [ "$i" -lt 40 ]; do
  if curl -s -o /dev/null "http://127.0.0.1:$WEB_PORT/login"; then break; fi
  i=$((i + 1))
  sleep 0.5
done

PANEL_STATUS=0
API_STATUS=0

echo "==> requesting admin panel routes"
LRMS_BASE="http://127.0.0.1:$WEB_PORT" php "$ROOT_DIR/tools/smoke-panel.php" || PANEL_STATUS=$?

echo ""
echo "==> requesting REST API endpoints"
LRMS_BASE="http://127.0.0.1:$WEB_PORT" php "$ROOT_DIR/tools/smoke-api.php" || API_STATUS=$?

if [ "$PANEL_STATUS" -ne 0 ] || [ "$API_STATUS" -ne 0 ]; then
  echo ""
  echo "==> server error log (last 30 lines)"
  grep -v 'Accepted\|Closing' "$ROOT_DIR/.verify/server.log" | tail -30 || true
  exit 1
fi

echo ""
echo "ALL SMOKE TESTS PASSED"
