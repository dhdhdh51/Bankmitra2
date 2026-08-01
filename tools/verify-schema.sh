#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# Validates schema.sql against a real MySQL 8 server running in Docker.
# Intended for local/CI verification only - not needed on shared hosting.
#
# Usage:  sh tools/verify-schema.sh
# ---------------------------------------------------------------------------
set -eu

CT=lrms-schema-check
ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
WORK=$ROOT_DIR/.verify
mkdir -p "$WORK"

EXPECT_TABLES=35
EXPECT_FKS=57

PASSED=0
FAILED=0
pass() { PASSED=$((PASSED + 1)); printf '  PASS  %s\n' "$1"; }
fail() { FAILED=$((FAILED + 1)); printf '  FAIL  %s  %s\n' "$1" "${2:-}"; }

cleanup() {
    docker rm -f "$CT" > /dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

cleanup
echo '==> starting MySQL 8 container'
docker run -d --name "$CT" \
    -e MYSQL_ROOT_PASSWORD=root \
    -e MYSQL_DATABASE=lrms \
    mysql:8.0 > /dev/null

# `mysqladmin ping` is NOT a usable readiness probe here: it answers successfully
# against the temporary server the entrypoint runs while initialising, before the
# root password is in place. Polling it and then importing produced
# "ERROR 1045 Access denied for user 'root'@'localhost'" - the schema had not
# changed at all. Only a real authenticated query against the target database
# proves the server is up.
echo '==> waiting for authenticated connections'
i=0
while [ "$i" -lt 120 ]; do
    if docker exec "$CT" mysql -uroot -proot -e 'SELECT 1' lrms > /dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
if ! docker exec "$CT" mysql -uroot -proot -e 'SELECT 1' lrms > /dev/null 2>&1; then
    echo '!! MySQL did not become ready in time'
    docker logs --tail 40 "$CT" || true
    exit 1
fi
echo "    ready after ${i} attempt(s)"

# `|| true` matters: without it a bad query makes the assignment fail, and under
# `set -e` the script then dies silently mid-run with stderr swallowed - which is
# exactly how a typo in one of these queries hid the rest of the suite once.
q() {
    docker exec -i "$CT" mysql -uroot -proot -N -B lrms -e "$1" 2> "$WORK/q.err" || {
        printf '  FAIL  query failed: %s\n' "$(grep -v 'Using a password' "$WORK/q.err" | head -1)" >&2
        FAILED=$((FAILED + 1))
        echo ''
    }
}

echo
echo '== import'
if docker exec -i "$CT" mysql -uroot -proot lrms < "$ROOT_DIR/schema.sql" 2> "$ROOT_DIR/.verify/schema-import.err"; then
    pass 'schema.sql imports with no error'
else
    fail 'schema.sql imports' "$(grep -v 'Using a password' "$ROOT_DIR/.verify/schema-import.err" | head -3)"
fi
# Warnings are not fatal but should not be ignored either.
WARN=$(grep -v 'Using a password' "$ROOT_DIR/.verify/schema-import.err" 2>/dev/null | head -3 || true)
[ -z "$WARN" ] && pass 'import produced no warnings' || fail 'import warnings' "$WARN"

# schema.sql opens every table with DROP TABLE IF EXISTS, so it is an INSTALL
# script, not a migration: re-importing it wipes the database. That is a
# legitimate design for a fresh install, but it must be asserted rather than
# assumed, because "re-import the schema to be safe" is a natural thing for an
# operator to try during an upgrade - and it would delete every customer.
docker exec -i "$CT" mysql -uroot -proot lrms \
    -e "INSERT INTO branches (branch_code, name, district, state, pincode, status)
        VALUES ('CANARY', 'Canary Branch', 'D', 'S', '000000', 'active');" 2>/dev/null
BEFORE=$(q "SELECT COUNT(*) FROM branches WHERE branch_code='CANARY';")
if docker exec -i "$CT" mysql -uroot -proot lrms < "$ROOT_DIR/schema.sql" > /dev/null 2>&1; then
    pass 'a second import completes without error'
else
    fail 'a second import completes without error'
fi
AFTER=$(q "SELECT COUNT(*) FROM branches WHERE branch_code='CANARY';")
if [ "${BEFORE:-0}" = '1' ] && [ "${AFTER:-1}" = '0' ]; then
    pass 'confirmed DESTRUCTIVE: re-importing drops existing data (documented as such)'
else
    fail 'the destructive re-import behaviour changed' "canary before=$BEFORE after=$AFTER"
fi

# The file turns foreign key checks off at the top to allow any table order; it
# must turn them back on, or everything else done in that same session - a
# phpMyAdmin tab, an operator's mysql shell - silently runs unchecked.
if grep -qE '^SET FOREIGN_KEY_CHECKS *= *1;' "$ROOT_DIR/schema.sql"; then
    pass 'schema.sql re-enables FOREIGN_KEY_CHECKS at the end'
else
    fail 'schema.sql leaves FOREIGN_KEY_CHECKS off for the rest of the session'
fi
FK_ON=$(q "SELECT @@FOREIGN_KEY_CHECKS;")
[ "${FK_ON:-0}" = '1' ] && pass 'foreign key enforcement is on in a fresh session' \
    || fail 'foreign key enforcement' "got $FK_ON"

echo
echo '== structure'
TABLES=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='lrms';")
[ "${TABLES:-0}" = "$EXPECT_TABLES" ] && pass "$TABLES tables created" \
    || fail 'table count' "got ${TABLES:-0}, expected $EXPECT_TABLES"

FKS=$(q "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE table_schema='lrms' AND constraint_type='FOREIGN KEY';")
[ "${FKS:-0}" = "$EXPECT_FKS" ] && pass "$FKS foreign keys created" \
    || fail 'foreign key count' "got ${FKS:-0}, expected $EXPECT_FKS"

ENGINES=$(q "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema='lrms' AND engine <> 'InnoDB';")
[ "${ENGINES:-0}" = '0' ] && pass 'every table is InnoDB (foreign keys actually enforced)' \
    || fail 'non-InnoDB tables' "count=$ENGINES"

CHARSET=$(q "SELECT COUNT(*) FROM information_schema.tables t
              JOIN information_schema.collations c ON c.collation_name = t.table_collation
             WHERE t.table_schema='lrms' AND c.character_set_name <> 'utf8mb4';")
[ "${CHARSET:-0}" = '0' ] && pass 'every table is utf8mb4 (Hindi names and emoji are safe)' \
    || fail 'non-utf8mb4 tables' "count=$CHARSET"

NOPK=$(q "SELECT COUNT(*) FROM information_schema.tables t
           WHERE t.table_schema='lrms'
             AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints k
                              WHERE k.table_schema=t.table_schema
                                AND k.table_name=t.table_name
                                AND k.constraint_type='PRIMARY KEY');")
[ "${NOPK:-0}" = '0' ] && pass 'every table has a primary key' || fail 'tables without a primary key' "count=$NOPK"

echo
echo '== seeds'
for pair in 'roles:3' 'permissions:1' 'role_permissions:1' 'settings:1' 'users:1' 'branches:1'; do
    t=${pair%%:*}
    min=${pair##*:}
    n=$(q "SELECT COUNT(*) FROM \`$t\`;")
    if [ "${n:-0}" -ge "$min" ]; then
        pass "$t seeded ($n rows)"
    else
        fail "$t seeded" "got ${n:-0}, expected >= $min"
    fi
done

ADMIN=$(q "SELECT employee_code FROM users WHERE id=1;")
[ "$ADMIN" = 'ADMIN001' ] && pass 'bootstrap admin is ADMIN001' || fail 'bootstrap admin' "got '$ADMIN'"

MUST=$(q "SELECT must_change_password FROM users WHERE employee_code='ADMIN001';")
[ "$MUST" = '1' ] && pass 'the seeded admin is forced to change its password' \
    || fail 'must_change_password on the seeded admin' "got '$MUST'"

# The seeded hash must be a real bcrypt hash for the documented password, or the
# very first login fails and nobody can get in.
HASH=$(q "SELECT password_hash FROM users WHERE employee_code='ADMIN001';")
if php -r 'exit(password_verify("Admin@123", $argv[1]) ? 0 : 1);' "$HASH"; then
    pass 'ADMIN001 / Admin@123 verifies against the seeded bcrypt hash'
else
    fail 'the seeded admin password does not match the documented one' "hash=$HASH"
fi

# A super admin with no permissions is a locked-out panel.
SUPER=$(q "SELECT COUNT(*) FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id
           WHERE r.slug='super_admin';")
[ "${SUPER:-0}" -ge 20 ] && pass "super_admin holds $SUPER permissions" \
    || fail 'super_admin permissions' "got ${SUPER:-0}"

# Agents must NOT be able to settle promises - that is a branch decision.
AGENT_PROMISE=$(q "SELECT COUNT(*) FROM role_permissions rp
                    JOIN roles r ON r.id = rp.role_id
                    JOIN permissions p ON p.id = rp.permission_id
                   WHERE r.slug='agent' AND p.code='promises.update';")
[ "${AGENT_PROMISE:-1}" = '0' ] && pass 'agents are not granted promises.update' \
    || fail 'agents can settle promises' "count=$AGENT_PROMISE"

# Every role_permissions row must point at a real permission.
ORPHAN=$(q "SELECT COUNT(*) FROM role_permissions rp
             LEFT JOIN permissions p ON p.id = rp.permission_id
            WHERE p.id IS NULL;")
[ "${ORPHAN:-0}" = '0' ] && pass 'no orphaned role_permissions rows' || fail 'orphaned role_permissions' "count=$ORPHAN"

REQUIRED=$(q "SELECT COUNT(*) FROM settings WHERE is_required=1;")
[ "${REQUIRED:-0}" -ge 1 ] && pass "$REQUIRED settings are marked required" || fail 'required settings' "got $REQUIRED"

echo
echo '============================================================'
printf '  SCHEMA: %s passed, %s failed\n' "$PASSED" "$FAILED"
echo '============================================================'
[ "$FAILED" -eq 0 ] || exit 1
echo
echo 'OK: schema.sql imported cleanly'
