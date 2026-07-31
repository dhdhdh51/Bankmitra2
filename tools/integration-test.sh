#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# Provisions a throwaway MySQL 8 instance, imports schema.sql, and runs the
# end-to-end integration test against it.
#
#   sh tools/integration-test.sh
#
# Requires docker (or podman aliased to docker) and PHP with pdo_mysql.
# ---------------------------------------------------------------------------
set -e

CT=lrms-itest
PORT=13306
ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)

cleanup() {
  docker rm -f "$CT" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker rm -f "$CT" >/dev/null 2>&1 || true

echo "==> starting MySQL 8 on port $PORT"
docker run -d --name "$CT" \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=lrms \
  -p "$PORT":3306 \
  mysql:8.0 >/dev/null

# Neither `mysqladmin ping` nor the "ready for connections" log line is a
# reliable readiness signal: the entrypoint runs a *temporary* server during
# initialisation that answers both. Poll for the thing we actually need instead
# - an authenticated connection using the configured root password.
echo "==> waiting for server to accept authenticated connections"
READY=0
i=0
while [ "$i" -lt 120 ]; do
  if docker exec "$CT" mysql -uroot -proot -e "SELECT 1" >/dev/null 2>&1; then
    READY=1
    break
  fi
  i=$((i + 1))
  sleep 2
done

if [ "$READY" -ne 1 ]; then
  echo "!! MySQL did not become ready"
  docker logs --tail 40 "$CT" || true
  exit 1
fi

echo "==> importing schema.sql"
docker exec -i "$CT" mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS lrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1 | grep -v 'Using a password' || true
docker exec -i "$CT" mysql -uroot -proot lrms < "$ROOT_DIR/schema.sql" 2>&1 | grep -v 'Using a password' || true

# Fail fast if the schema did not land, rather than reporting confusing
# "table doesn't exist" errors from inside the test.
TABLE_COUNT=$(docker exec -i "$CT" mysql -uroot -proot -N -B lrms \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='lrms';" 2>/dev/null | tr -d '[:space:]')
echo "==> tables present: ${TABLE_COUNT:-0}"
if [ "${TABLE_COUNT:-0}" -lt 20 ]; then
  echo "!! schema import failed"
  exit 1
fi

# The server accepts connections inside the container before the TCP mapping is
# reliably usable from the host, so poll the actual PHP connection.
echo "==> waiting for host connectivity on 127.0.0.1:$PORT"
i=0
while [ "$i" -lt 45 ]; do
  if php -r '
    try {
      new PDO("mysql:host=127.0.0.1;port=" . getenv("PORT") . ";dbname=lrms", "root", "root");
      exit(0);
    } catch (Throwable $e) { exit(1); }
  ' PORT="$PORT" 2>/dev/null; then
    break
  fi
  PORT="$PORT" i=$((i + 1))
  sleep 1
done

echo "==> running integration test"
rm -rf "$ROOT_DIR/.verify/itest"
LRMS_DB_HOST=127.0.0.1 \
LRMS_DB_PORT="$PORT" \
LRMS_DB_NAME=lrms \
LRMS_DB_USER=root \
LRMS_DB_PASS=root \
php "$ROOT_DIR/tools/integration-test.php"
