#!/bin/sh
# ---------------------------------------------------------------------------
# Runs the two cron scripts for real against a live MySQL.
#
# Until this existed, admin/cron/backup.php and admin/cron/reminders.php had
# never been executed by any test - they are the only entry points that are
# neither a web route nor covered by the integration suite, so a fatal error in
# either would have surfaced as a silent nightly failure in production.
#
#   sh tools/verify-cron.sh
# ---------------------------------------------------------------------------
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
APP=$ROOT/admin
WORK=$ROOT/.verify/cron
CT=lrms-cron
DB_PORT=13309

PASSED=0
FAILED=0
pass() { PASSED=$((PASSED + 1)); printf '  PASS  %s\n' "$1"; }
fail() { FAILED=$((FAILED + 1)); printf '  FAIL  %s  %s\n' "$1" "${2:-}"; }

cleanup() {
    docker rm -f "$CT" > /dev/null 2>&1 || true
    rm -f "$APP/config/config.php"
    rm -rf "$APP/storage/backups"/lrms_backup_*.sql
}
trap cleanup EXIT INT TERM

cleanup
rm -rf "$WORK"; mkdir -p "$WORK"

sql() { docker exec -i "$CT" mysql -uroot -proot -N -B lrms 2>/dev/null; }
sqlv() { printf '%s' "$1" | sql; }

echo "==> MySQL on port $DB_PORT"
docker run -d --name "$CT" -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=lrms \
    -p "$DB_PORT":3306 mysql:8.0 > /dev/null
i=0
while [ "$i" -lt 120 ]; do
    docker exec "$CT" mysql -uroot -proot -e "SELECT 1" > /dev/null 2>&1 && break
    i=$((i + 1)); sleep 2
done
[ "$i" -lt 120 ] || { echo '!! MySQL never became ready'; exit 1; }
docker exec -i "$CT" mysql -uroot -proot lrms < "$ROOT/schema.sql" 2>&1 | grep -v 'Using a password' || true

echo '==> config + demo data'
APP_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
DATA_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
PEPPER=$(php -r 'echo bin2hex(random_bytes(32));')
cat > "$APP/config/config.php" <<PHPEOF
<?php
return [
    'db' => ['host' => '127.0.0.1', 'port' => $DB_PORT, 'name' => 'lrms',
             'user' => 'root', 'pass' => 'root', 'charset' => 'utf8mb4'],
    'app_key' => '$APP_KEY', 'data_key' => '$DATA_KEY', 'hash_pepper' => '$PEPPER',
    'app' => ['url' => 'http://127.0.0.1', 'base_path' => '', 'env' => 'local',
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

# ===========================================================================
echo
echo '== cron/backup.php'
# ===========================================================================
rm -f "$APP/storage/backups"/*.sql
if php "$APP/cron/backup.php" > "$WORK/backup1.log" 2>&1; then
    pass "exits 0  ($(tr -d '\n' < "$WORK/backup1.log" | cut -c1-72))"
else
    fail 'exits 0' "$(tail -3 "$WORK/backup1.log")"
fi
grep -q 'backup ok' "$WORK/backup1.log" && pass 'prints a success line' || fail 'prints a success line'

COUNT=$(find "$APP/storage/backups" -name '*.sql' | wc -l | tr -d ' ')
[ "$COUNT" = '1' ] && pass 'one .sql written' || fail 'one .sql written' "found $COUNT"

FILE=$(find "$APP/storage/backups" -name '*.sql' | head -1)
if [ -n "$FILE" ]; then
    grep -q 'CREATE TABLE' "$FILE" && pass 'backup contains CREATE TABLE' || fail 'backup contains CREATE TABLE'
    grep -qi 'FOREIGN_KEY_CHECKS' "$FILE" && pass 'backup disables FK checks' || fail 'backup disables FK checks'
    MODE=$(stat -c '%a' "$FILE")
    [ "$MODE" = '600' ] && pass "backup file is chmod $MODE (not world-readable)" \
        || fail 'backup file permissions' "got $MODE, expected 600"
    # The dump must actually restore, or it is not a backup.
    docker exec -i "$CT" mysql -uroot -proot -e 'CREATE DATABASE restored' 2>/dev/null
    if docker exec -i "$CT" mysql -uroot -proot restored < "$FILE" 2>"$WORK/restore.err"; then
        T=$(printf 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="restored";' \
            | docker exec -i "$CT" mysql -uroot -proot -N -B 2>/dev/null)
        [ "$T" -ge 20 ] && pass "the dump restores into a fresh database ($T tables)" \
            || fail 'the dump restores' "only $T tables"
        L=$(printf 'SELECT COUNT(*) FROM restored.loan_accounts;' \
            | docker exec -i "$CT" mysql -uroot -proot -N -B 2>/dev/null)
        [ "$L" -ge 50 ] && pass "restored data is intact ($L loan accounts)" \
            || fail 'restored row counts' "loan_accounts=$L"
    else
        fail 'the dump restores into a fresh database' "$(head -3 "$WORK/restore.err")"
    fi
fi

# Two runs inside the same second must not overwrite each other.
php "$APP/cron/backup.php" > "$WORK/backup2.log" 2>&1 || true
php "$APP/cron/backup.php" > "$WORK/backup3.log" 2>&1 || true
COUNT=$(find "$APP/storage/backups" -name '*.sql' | wc -l | tr -d ' ')
[ "$COUNT" = '3' ] && pass 'three runs produce three files (no same-second overwrite)' \
    || fail 'same-second runs' "expected 3 files, found $COUNT"

# Retention pruning.
sqlv "UPDATE settings SET setting_value='1' WHERE setting_key='backup_retention_days';" > /dev/null
touch -d '10 days ago' "$APP/storage/backups"/lrms_backup_old_manual.sql 2>/dev/null \
    || touch -t 202001010101 "$APP/storage/backups"/lrms_backup_old_manual.sql
php "$APP/cron/backup.php" > "$WORK/backup4.log" 2>&1 || true
if [ -f "$APP/storage/backups/lrms_backup_old_manual.sql" ]; then
    fail 'retention prunes files older than the window'
else
    pass 'retention prunes files older than the window'
fi
sqlv "UPDATE settings SET setting_value='14' WHERE setting_key='backup_retention_days';" > /dev/null

# ===========================================================================
echo
echo '== cron/reminders.php'
# ===========================================================================
# Deterministic fixture: four pending promises on four different loan accounts
# and agents, dated so that exactly one is due today, one is 1 day overdue, one
# is 7 days overdue, and one is 3 days overdue (which must be skipped, because
# the script nudges on day 1 then weekly rather than every morning).
sqlv "DELETE FROM notifications;" > /dev/null
sqlv "UPDATE promises SET status='kept' WHERE status='pending';" > /dev/null

IDS=$(sqlv "SELECT id FROM promises ORDER BY id LIMIT 4;" | tr '\n' ' ')
set -- $IDS
if [ "$#" -lt 4 ]; then
    fail 'fixture: need at least 4 promises' "found $#"
else
    n=0
    for offset in 0 1 7 3; do
        n=$((n + 1))
        eval "P=\$$n"
        sqlv "UPDATE promises SET status='pending',
                 promise_date = DATE_SUB(CURDATE(), INTERVAL $offset DAY)
               WHERE id = $P;" > /dev/null
    done
    pass 'fixture: 4 pending promises dated today / -1d / -7d / -3d'
fi

if php "$APP/cron/reminders.php" > "$WORK/rem1.log" 2>&1; then
    pass "exits 0  ($(tr -d '\n' < "$WORK/rem1.log" | cut -c1-72))"
else
    fail 'exits 0' "$(tail -5 "$WORK/rem1.log")"
fi

DUE=$(sqlv "SELECT COUNT(*) FROM notifications WHERE type='promise_reminder' AND title LIKE 'Promise due%';")
[ "${DUE:-0}" -ge 1 ] && pass "a due-today promise raised a reminder ($DUE)" \
    || fail 'due-today reminder' "count=$DUE"

OD=$(sqlv "SELECT COUNT(*) FROM notifications WHERE type='promise_reminder' AND title LIKE 'Promise overdue%';")
[ "${OD:-0}" -ge 2 ] && pass "overdue promises at 1 and 7 days raised reminders ($OD)" \
    || fail 'overdue reminders' "count=$OD (expected >= 2)"

# The 3-day-overdue promise must NOT have produced a notification.
OD3=$(sqlv "SELECT COUNT(*) FROM notifications n
             JOIN promises p ON p.loan_account_id = n.loan_account_id
            WHERE n.type='promise_reminder'
              AND p.status='pending'
              AND DATEDIFF(CURDATE(), p.promise_date) = 3;")
[ "${OD3:-0}" = '0' ] && pass 'a 3-day-overdue promise is skipped (day 1, then weekly)' \
    || fail '3-day-overdue should be skipped' "count=$OD3"

TOTAL1=$(sqlv "SELECT COUNT(*) FROM notifications;")

# Idempotency: a duplicated cron entry or a manual re-run must not spam agents.
php "$APP/cron/reminders.php" > "$WORK/rem2.log" 2>&1 || fail 'second run exits 0'
TOTAL2=$(sqlv "SELECT COUNT(*) FROM notifications;")
[ "$TOTAL1" = "$TOTAL2" ] && pass "re-running sends nothing new (still $TOTAL2 notifications)" \
    || fail 'idempotency' "$TOTAL1 -> $TOTAL2"

# Notifications must be addressed to the promise's own agent.
BAD=$(sqlv "SELECT COUNT(*) FROM notifications n
             JOIN loan_accounts la ON la.id = n.loan_account_id
            WHERE n.type='promise_reminder'
              AND n.user_id <> la.assigned_agent_id;")
[ "${BAD:-0}" = '0' ] && pass 'each reminder went to the assigned agent' \
    || fail 'reminder addressed to the wrong user' "count=$BAD"

# An inactive agent must not be notified.
sqlv "UPDATE notifications SET is_read=1;" > /dev/null
AG=$(sqlv "SELECT agent_id FROM promises WHERE status='pending' LIMIT 1;")
if [ -n "${AG:-}" ]; then
    sqlv "DELETE FROM notifications;" > /dev/null
    sqlv "UPDATE users SET status='inactive' WHERE id=$AG;" > /dev/null
    php "$APP/cron/reminders.php" > "$WORK/rem3.log" 2>&1 || true
    N=$(sqlv "SELECT COUNT(*) FROM notifications WHERE user_id=$AG;")
    [ "${N:-0}" = '0' ] && pass 'an inactive agent receives no reminders' \
        || fail 'inactive agent was notified' "count=$N"
    sqlv "UPDATE users SET status='active' WHERE id=$AG;" > /dev/null
fi

# ===========================================================================
echo
echo '== both scripts refuse to run over HTTP'
# ===========================================================================
for f in backup reminders; do
    if grep -q "PHP_SAPI !== 'cli'" "$APP/cron/$f.php"; then
        pass "cron/$f.php has a CLI-only guard"
    else
        fail "cron/$f.php has no CLI-only guard"
    fi
done

echo
echo '============================================================'
printf '  CRON: %s passed, %s failed\n' "$PASSED" "$FAILED"
echo '============================================================'
[ "$FAILED" -eq 0 ] || exit 1
echo
echo 'CRON OK'
