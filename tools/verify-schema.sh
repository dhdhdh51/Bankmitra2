#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# Validates schema.sql against a real MySQL 8 server running in Docker.
# Intended for local/CI verification only - not needed on shared hosting.
#
# Usage:  sh tools/verify-schema.sh
# ---------------------------------------------------------------------------
set -e

CT=lrms-schema-check
ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)

cleanup() {
  docker rm -f "$CT" >/dev/null 2>&1 || true
}

docker rm -f "$CT" >/dev/null 2>&1 || true
echo "==> starting MySQL 8 container"
docker run -d --name "$CT" \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=lrms \
  mysql:8.0 >/dev/null

echo "==> waiting for server"
i=0
while [ "$i" -lt 90 ]; do
  if docker exec "$CT" mysqladmin ping -uroot -proot --silent >/dev/null 2>&1; then
    break
  fi
  i=$((i + 1))
  sleep 2
done

if ! docker exec "$CT" mysqladmin ping -uroot -proot --silent >/dev/null 2>&1; then
  echo "!! MySQL did not become ready in time"
  docker logs --tail 40 "$CT" || true
  cleanup
  exit 1
fi

echo "==> importing schema.sql"
docker exec -i "$CT" mysql -uroot -proot lrms < "$ROOT_DIR/schema.sql"

echo "==> tables created"
docker exec -i "$CT" mysql -uroot -proot -N -B lrms \
  -e "SELECT table_name FROM information_schema.tables WHERE table_schema='lrms' ORDER BY table_name;"

echo "==> seed sanity check"
docker exec -i "$CT" mysql -uroot -proot -t lrms -e "
  SELECT (SELECT COUNT(*) FROM roles)            AS roles,
         (SELECT COUNT(*) FROM permissions)      AS perms,
         (SELECT COUNT(*) FROM role_permissions) AS role_perms,
         (SELECT COUNT(*) FROM settings)         AS settings,
         (SELECT COUNT(*) FROM users)            AS users,
         (SELECT COUNT(*) FROM branches)         AS branches;"

echo "==> foreign keys"
docker exec -i "$CT" mysql -uroot -proot -N -B lrms -e "
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema='lrms' AND constraint_type='FOREIGN KEY';"

cleanup
echo "OK: schema.sql imported cleanly"
