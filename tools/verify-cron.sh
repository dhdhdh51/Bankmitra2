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
echo '== cron/bc-warning-check.php'
# ===========================================================================
# A warning is a statement about somebody's job, so this cron gets exercised
# against real rows rather than trusted. --date lets the whole thing be driven
# deterministically instead of depending on what day the suite happens to run.

sqlv "DELETE FROM bc_warnings;" > /dev/null
sqlv "DELETE FROM bc_daily_achievement;" > /dev/null
sqlv "DELETE FROM bc_targets;" > /dev/null

# A Sunday must be refused outright.
if php "$APP/cron/bc-warning-check.php" --date=2026-08-02 > "$WORK/bcw_sun.log" 2>&1; then
    if grep -qi 'Sunday' "$WORK/bcw_sun.log"; then
        pass 'a Sunday is not assessed at all'
    else
        fail 'Sunday should be skipped' "$(tail -3 "$WORK/bcw_sun.log")"
    fi
else
    fail 'Sunday run exits 0' "$(tail -5 "$WORK/bcw_sun.log")"
fi
SUNW=$(sqlv "SELECT COUNT(*) FROM bc_warnings;")
[ "${SUNW:-1}" = '0' ] && pass 'no warning is issued on a Sunday' \
    || fail 'Sunday issued warnings' "count=$SUNW"

# With no targets set, nobody can be warned for missing one.
if php "$APP/cron/bc-warning-check.php" --date=2026-08-03 > "$WORK/bcw_notgt.log" 2>&1; then
    pass "runs with no targets set  ($(grep -c 'no targets set' "$WORK/bcw_notgt.log" > /dev/null && echo 'reported' || echo 'ok'))"
else
    fail 'no-targets run exits 0' "$(tail -5 "$WORK/bcw_notgt.log")"
fi
NOTGT=$(sqlv "SELECT COUNT(*) FROM bc_warnings;")
[ "${NOTGT:-1}" = '0' ] && pass 'an agent with no target is never warned' \
    || fail 'warned without a target' "count=$NOTGT"

# Give one agent an impossible daily visit target and assess a working day.
BCAG=$(sqlv "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
              WHERE r.slug='agent' AND u.status='active' ORDER BY u.id LIMIT 1;")
if [ -z "${BCAG:-}" ]; then
    fail 'fixture: need an active agent'
else
    sqlv "INSERT INTO bc_targets (agent_id, target_month, daily_visit_target)
          VALUES ($BCAG, '2026-08-01', 99);" > /dev/null
    pass "fixture: agent $BCAG given a daily visit target of 99"

    php "$APP/cron/bc-warning-check.php" --date=2026-08-03 > "$WORK/bcw1.log" 2>&1 \
        || fail 'targeted run exits 0' "$(tail -5 "$WORK/bcw1.log")"

    L1=$(sqlv "SELECT warning_level FROM bc_warnings
                WHERE agent_id=$BCAG AND target_type='visit' AND triggered_date='2026-08-03';")
    [ "${L1:-}" = 'L1' ] && pass 'a first miss issues Level 1' \
        || fail 'first miss level' "got '${L1:-none}'"

    GAP=$(sqlv "SELECT gap_value FROM bc_warnings
                 WHERE agent_id=$BCAG AND target_type='visit' AND triggered_date='2026-08-03';")
    [ "${GAP:-0}" = '99' ] && pass 'the warning records the gap (99 visits short)' \
        || fail 'gap value' "got '${GAP:-}'"

    ROLL=$(sqlv "SELECT COUNT(*) FROM bc_daily_achievement
                  WHERE agent_id=$BCAG AND achievement_date='2026-08-03';")
    [ "${ROLL:-0}" = '1' ] && pass 'the cron rolled the day up' \
        || fail 'rollup missing' "count=$ROLL"

    # Idempotency: re-running the same day must not warn or mail twice.
    W1=$(sqlv "SELECT COUNT(*) FROM bc_warnings;")
    php "$APP/cron/bc-warning-check.php" --date=2026-08-03 > "$WORK/bcw2.log" 2>&1 \
        || fail 'second run exits 0'
    W2=$(sqlv "SELECT COUNT(*) FROM bc_warnings;")
    [ "$W1" = "$W2" ] && pass "re-running the same day warns nobody twice (still $W2)" \
        || fail 'cron is not idempotent' "$W1 -> $W2"

    # Escalation across consecutive working days: 4 Aug is day 2, 5 Aug day 3 = L2.
    php "$APP/cron/bc-warning-check.php" --date=2026-08-04 > "$WORK/bcw3.log" 2>&1 || true
    php "$APP/cron/bc-warning-check.php" --date=2026-08-05 > "$WORK/bcw4.log" 2>&1 || true
    L2=$(sqlv "SELECT warning_level FROM bc_warnings
                WHERE agent_id=$BCAG AND target_type='visit' AND triggered_date='2026-08-05';")
    [ "${L2:-}" = 'L2' ] && pass 'a third consecutive miss escalates to Level 2' \
        || fail 'third miss level' "got '${L2:-none}'"

    BADGE=$(sqlv "SELECT dashboard_status FROM users WHERE id=$BCAG;")
    [ "${BADGE:-}" = 'warning_2' ] && pass 'the dashboard badge follows the level' \
        || fail 'dashboard badge' "got '${BADGE:-}'"

    # The agent must be told in the app, not only by email.
    APPN=$(sqlv "SELECT COUNT(*) FROM notifications
                  WHERE user_id=$BCAG AND type='target_warning';")
    [ "${APPN:-0}" -ge 1 ] && pass "the agent is notified in the app ($APPN)" \
        || fail 'no in-app notification' "count=$APPN"

    # A dry run must change nothing.
    W3=$(sqlv "SELECT COUNT(*) FROM bc_warnings;")
    php "$APP/cron/bc-warning-check.php" --date=2026-08-06 --dry-run > "$WORK/bcw5.log" 2>&1 \
        || fail 'dry run exits 0'
    W4=$(sqlv "SELECT COUNT(*) FROM bc_warnings;")
    [ "$W3" = "$W4" ] && pass 'a dry run writes nothing' \
        || fail 'dry run wrote rows' "$W3 -> $W4"

    # A bad --date must be refused rather than silently assessed as today.
    if php "$APP/cron/bc-warning-check.php" --date=notadate > "$WORK/bcw6.log" 2>&1; then
        fail 'a malformed --date should exit non-zero'
    else
        pass 'a malformed --date is refused'
    fi
fi

# ===========================================================================
echo
echo '== cron/purge-location-logs.php'
# ===========================================================================
# The location notice every agent acknowledges promises their position is deleted
# after the retention window. If this cron does not work, that promise is false,
# so it gets tested rather than trusted.

BCAG2=$(sqlv "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
               WHERE r.slug='agent' AND u.status='active' ORDER BY u.id LIMIT 1;")
sqlv "DELETE FROM bc_location_logs;" > /dev/null
sqlv "INSERT INTO bc_location_logs (agent_id, latitude, longitude, logged_at) VALUES
        ($BCAG2, 26.9124, 75.7873, DATE_SUB(NOW(), INTERVAL 200 DAY)),
        ($BCAG2, 26.9124, 75.7873, DATE_SUB(NOW(), INTERVAL 120 DAY)),
        ($BCAG2, 26.9124, 75.7873, DATE_SUB(NOW(), INTERVAL 10 DAY)),
        ($BCAG2, 26.9124, 75.7873, NOW());" > /dev/null
pass 'fixture: 4 location points at -200d / -120d / -10d / now'

# A dry run must delete nothing.
php "$APP/cron/purge-location-logs.php" --days=90 --dry-run > "$WORK/purge1.log" 2>&1 \
    || fail 'purge dry run exits 0' "$(tail -5 "$WORK/purge1.log")"
DRY=$(sqlv "SELECT COUNT(*) FROM bc_location_logs;")
[ "${DRY:-0}" = '4' ] && pass 'a dry run deletes nothing' || fail 'dry run deleted rows' "count=$DRY"
grep -q 'past retention   : 2' "$WORK/purge1.log" \
    && pass 'the dry run reports what would go' \
    || fail 'dry run count' "$(grep 'past retention' "$WORK/purge1.log")"

php "$APP/cron/purge-location-logs.php" --days=90 > "$WORK/purge2.log" 2>&1 \
    || fail 'purge exits 0' "$(tail -5 "$WORK/purge2.log")"
LEFT=$(sqlv "SELECT COUNT(*) FROM bc_location_logs;")
[ "${LEFT:-0}" = '2' ] && pass 'points past the window are deleted' || fail 'purge count' "left=$LEFT"

PAUD=$(sqlv "SELECT COUNT(*) FROM audit_logs WHERE action='purge' AND entity_type='bc_location_logs';")
[ "${PAUD:-0}" -ge 1 ] && pass 'the purge is audited' || fail 'purge not audited' "count=$PAUD"

# A retention of zero must be refused, not treated as "keep forever".
if php "$APP/cron/purge-location-logs.php" --days=0 > "$WORK/purge3.log" 2>&1; then
    fail 'a retention of 0 should exit non-zero'
else
    pass 'a retention of zero is refused'
fi

# Re-running when there is nothing stale must be a no-op, not an error.
php "$APP/cron/purge-location-logs.php" --days=90 > "$WORK/purge4.log" 2>&1 \
    || fail 'second purge exits 0'
grep -q 'Nothing to purge' "$WORK/purge4.log" \
    && pass 'a second run is a clean no-op' \
    || fail 'second purge should find nothing' "$(tail -3 "$WORK/purge4.log")"

# ===========================================================================
echo
echo '== cron/sss-reminder.php'
# ===========================================================================
# No SMS gateway in this deployment, so the reminder goes by email and in-app.
sqlv "DELETE FROM notifications;" > /dev/null
sqlv "DELETE FROM sss_enrollment;" > /dev/null
sqlv "DELETE FROM bc_targets;" > /dev/null

# Sunday must be refused.
php "$APP/cron/sss-reminder.php" --date=2026-08-02 > "$WORK/sss0.log" 2>&1 \
    || fail 'sss Sunday run exits 0'
grep -qi 'Sunday' "$WORK/sss0.log" && pass 'no SSS reminder on a Sunday' \
    || fail 'Sunday should be skipped' "$(tail -3 "$WORK/sss0.log")"

# An agent with no SSS target is not reminded about one.
php "$APP/cron/sss-reminder.php" --date=2026-08-03 > "$WORK/sss1.log" 2>&1 || fail 'sss run exits 0'
N0=$(sqlv "SELECT COUNT(*) FROM notifications WHERE type='sss_pending';")
[ "${N0:-0}" = '0' ] && pass 'an agent without an SSS target is not reminded' \
    || fail 'reminded without a target' "count=$N0"

sqlv "INSERT INTO bc_targets (agent_id, target_month, apy_target, pmjjby_target, pmsby_target)
      VALUES ($BCAG2, '2026-08-01', 10, 10, 10);" > /dev/null

php "$APP/cron/sss-reminder.php" --date=2026-08-03 > "$WORK/sss2.log" 2>&1 || fail 'targeted sss run exits 0'
N1=$(sqlv "SELECT COUNT(*) FROM notifications WHERE user_id=$BCAG2 AND type='sss_pending';")
[ "${N1:-0}" -ge 1 ] && pass "an agent with a target and no entry is reminded ($N1)" \
    || fail 'no SSS reminder raised' "count=$N1"

# Same slot twice must not double up.
php "$APP/cron/sss-reminder.php" --date=2026-08-03 > "$WORK/sss3.log" 2>&1 || fail 'repeat sss run exits 0'
N2=$(sqlv "SELECT COUNT(*) FROM notifications WHERE user_id=$BCAG2 AND type='sss_pending';")
[ "$N1" = "$N2" ] && pass "the same slot does not remind twice (still $N2)" \
    || fail 'sss reminder is not idempotent per slot' "$N1 -> $N2"

# The final slot is a separate reminder, not a duplicate.
php "$APP/cron/sss-reminder.php" --date=2026-08-03 --final > "$WORK/sss4.log" 2>&1 || fail 'final slot exits 0'
N3=$(sqlv "SELECT COUNT(*) FROM notifications WHERE user_id=$BCAG2 AND type='sss_pending';")
[ "${N3:-0}" -gt "${N2:-0}" ] && pass 'the final slot sends its own reminder' \
    || fail 'final slot sent nothing' "$N2 -> $N3"

# Once the agent records an entry, the reminders stop.
sqlv "INSERT INTO sss_enrollment (agent_id, branch_id, enrollment_date, apy_count)
      SELECT $BCAG2, branch_id, '2026-08-03', 3 FROM users WHERE id=$BCAG2;" > /dev/null
php "$APP/cron/sss-reminder.php" --date=2026-08-03 --final > "$WORK/sss5.log" 2>&1 || fail 'post-entry run exits 0'
grep -q '0 agent(s) with no SSS entry' "$WORK/sss5.log" \
    && pass 'an agent who has recorded an entry is not reminded' \
    || fail 'still reminded after recording' "$(grep 'agent(s) with no' "$WORK/sss5.log")"

# ===========================================================================
echo
echo '== every cron script refuses to run over HTTP'
# ===========================================================================
# Enumerated from the directory, not from a list kept here. A hardcoded list means
# a cron added later is simply never checked - and the whole point of this check is
# that a cron script reachable over HTTP hands an unauthenticated visitor a job that
# emails agents, purges data or dumps the database.
for path in "$APP"/cron/*.php; do
    f=$(basename "$path" .php)
    if grep -q "PHP_SAPI !== 'cli'" "$path"; then
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
