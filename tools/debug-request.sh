#!/usr/bin/env sh
# Boots the stack and dumps the raw response body for a single path, so a 500
# page can actually be read. Usage: sh tools/debug-request.sh /login
set -e

CT=lrms-debug
DB_PORT=13308
WEB_PORT=8098
PATH_TO_GET=${1:-/login}
ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
mkdir -p "$ROOT_DIR/.verify"

cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
  docker rm -f "$CT" >/dev/null 2>&1 || true
  rm -f "$ROOT_DIR/admin/config/config.php"
}
trap cleanup EXIT

docker rm -f "$CT" >/dev/null 2>&1 || true
docker run -d --name "$CT" -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=lrms \
  -p "$DB_PORT":3306 mysql:8.0 >/dev/null

i=0
while [ "$i" -lt 120 ]; do
  docker exec "$CT" mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot -proot -e "SELECT 1" >/dev/null 2>&1 && break
  i=$((i + 1)); sleep 2
done

docker exec -i "$CT" mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot -proot -e "CREATE DATABASE IF NOT EXISTS lrms;" 2>/dev/null
docker exec -i "$CT" mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot -proot lrms < "$ROOT_DIR/schema.sql" 2>&1 | grep -v 'Using a password' || true

K1=$(php -r 'echo bin2hex(random_bytes(32));')
cat > "$ROOT_DIR/admin/config/config.php" <<PHPEOF
<?php
return [
    'db' => ['host' => '127.0.0.1', 'port' => $DB_PORT, 'name' => 'lrms', 'user' => 'root', 'pass' => 'root', 'charset' => 'utf8mb4'],
    'app_key' => '$K1', 'data_key' => '$K1', 'hash_pepper' => '$K1',
    'app' => ['url' => '', 'base_path' => '', 'env' => 'local', 'debug' => true, 'timezone' => 'Asia/Kolkata'],
    'paths' => ['uploads' => __DIR__ . '/../uploads', 'storage' => __DIR__ . '/../storage'],
    'uploads' => ['max_photo_bytes' => 8388608, 'max_document_bytes' => 12582912, 'max_import_bytes' => 26214400,
        'allowed_image_mime' => ['image/jpeg','image/png','image/webp'], 'allowed_doc_mime' => ['image/jpeg','image/png','image/webp','application/pdf']],
    'session' => ['name' => 'lrms_dbg', 'lifetime' => 7200, 'secure' => false],
];
PHPEOF

php -S 127.0.0.1:"$WEB_PORT" -t "$ROOT_DIR/admin" "$ROOT_DIR/tools/router-dev.php" \
  > "$ROOT_DIR/.verify/debug-server.log" 2>&1 &
SERVER_PID=$!
sleep 2

echo "=== GET $PATH_TO_GET ==="
curl -s -i "http://127.0.0.1:$WEB_PORT$PATH_TO_GET" | head -80
echo ""
echo "=== SERVER LOG ==="
grep -v 'Accepted\|Closing' "$ROOT_DIR/.verify/debug-server.log" | tail -30
echo "=== PHP ERROR LOG ==="
tail -30 "$ROOT_DIR/admin/storage/logs/php-error.log" 2>/dev/null || echo "(none)"
