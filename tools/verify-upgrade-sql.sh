#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# Proves the upgrade SQL in DEPLOYMENT.md actually produces the shipped schema.
#
#   sh tools/verify-upgrade-sql.sh                 # spins up its own MySQL in docker
#   LRMS_DB_HOST=127.0.0.1 sh tools/verify-upgrade-sql.sh   # uses an existing server
#
# A documented migration nobody runs is worse than no migration: the operator
# discovers it is wrong halfway through, on a populated database, with the panel
# already serving the new code. So this reconstructs the pre-release schema, runs
# the block out of DEPLOYMENT.md verbatim, and compares the result against
# schema.sql column by column, index by index and constraint by constraint.
#
# The SQL is extracted from the document rather than duplicated here. A copy would
# drift, and the copy that matters is the one the operator pastes.
#
# Env (all optional): LRMS_DB_HOST, LRMS_DB_PORT, LRMS_DB_USER, LRMS_DB_PASS.
# Setting LRMS_DB_HOST skips docker entirely, which is how CI reuses its service.
# ---------------------------------------------------------------------------
set -e

CT=lrms-upgrade
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK="$ROOT/.verify/upgrade"

DB_HOST=${LRMS_DB_HOST:-}
DB_PORT=${LRMS_DB_PORT:-13309}
DB_USER=${LRMS_DB_USER:-root}
DB_PASS=${LRMS_DB_PASS:-root}

if [ -n "$DB_HOST" ]; then
    USE_DOCKER=0
else
    USE_DOCKER=1
    DB_HOST=127.0.0.1
fi

cleanup() { [ "$USE_DOCKER" = "1" ] && docker rm -f "$CT" >/dev/null 2>&1 || true; }
trap cleanup EXIT INT TERM
cleanup

rm -rf "$WORK"; mkdir -p "$WORK"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1${2:+ -> $2}"; }
check() { if [ "$2" = "1" ]; then ok "$1"; else bad "$1" "$3"; fi; }

# One place that knows how to reach the server, so the docker and CI paths cannot
# drift apart. Word splitting is intended; none of these values contain spaces.
if [ "$USE_DOCKER" = "1" ]; then
    CLIENT="docker exec -i $CT mysql --protocol=TCP -h 127.0.0.1 -P 3306 -u$DB_USER -p$DB_PASS"
else
    CLIENT="mysql --protocol=TCP -h $DB_HOST -P $DB_PORT -u$DB_USER -p$DB_PASS"
fi
# shellcheck disable=SC2086
db() { $CLIENT "$@" 2>&1 | grep -v 'Using a password' || true; }
dbq() { $CLIENT -N -B "$@" 2>/dev/null | tr -d '\r'; }

echo "==> extracting the migration chain from DEPLOYMENT.md"
python3 - "$ROOT/DEPLOYMENT.md" "$WORK/migration.sql" <<'PY'
import re, sys
doc, out = sys.argv[1], sys.argv[2]
text = open(doc, encoding='utf-8').read()

# The chain this harness covers, in the order an operator runs them. Listed
# explicitly rather than discovered, because the document also carries migrations for
# much older releases whose columns this script's downgrade does not remove - and a
# harness that silently skipped one would be worse than one that named them.
HEADINGS = [
    '### Adding staff photographs, report approval and custom fields to an existing install',
    '### Adding geo-tagged agent photographs and signatures to an existing install',
    '### Adding the full banking columns, panel signatures and agent access to an existing install',
]

chunks = []
for heading in HEADINGS:
    if heading not in text:
        sys.exit(f'!! missing from DEPLOYMENT.md: {heading}')
    section = text.split(heading, 1)[1].split('\n### ', 1)[0]
    blocks = re.findall(r'```sql\n(.*?)```', section, re.S)
    if not blocks:
        sys.exit(f'!! no ```sql block under: {heading}')
    # The first block is the migration; later blocks are confirmation queries.
    chunks.append(blocks[0])
    print(f"    {len(blocks[0].splitlines()):3d} lines - {heading[4:60]}")

# Ordered by their position in the document, which is the order they must be applied:
# the second release's ALTERs assume the first release's columns exist.
positions = sorted(range(len(HEADINGS)), key=lambda i: text.index(HEADINGS[i]))
open(out, 'w', encoding='utf-8').write('\n'.join(chunks[i] for i in positions))
print(f"    {sum(len(c.splitlines()) for c in chunks)} lines of SQL in {len(chunks)} migrations")
PY

if [ "$USE_DOCKER" = "1" ]; then
    echo "==> MySQL on port $DB_PORT (docker)"
    docker run -d --name "$CT" -e MYSQL_ROOT_PASSWORD="$DB_PASS" \
        -p "$DB_PORT":3306 mysql:8.0 >/dev/null
    # TCP, not the socket: the entrypoint's temporary init server answers on the
    # socket and is then shut down (see tools/integration-test.sh).
    #
    # Probed with $CLIENT directly, never through db()/dbq(): those pipe into grep and
    # tr, so the exit status belongs to the last command in the pipeline and the wait
    # loop would break on its first attempt no matter what the server was doing.
    i=0
    while [ "$i" -lt 120 ]; do
        # shellcheck disable=SC2086
        if $CLIENT -e "SELECT 1" >/dev/null 2>&1; then break; fi
        i=$((i+1)); sleep 2
    done
    [ "$i" -lt 120 ] || { echo '!! MySQL never became ready'; exit 1; }
else
    echo "==> using MySQL at $DB_HOST:$DB_PORT"
    # shellcheck disable=SC2086
    $CLIENT -e "SELECT 1" >/dev/null 2>&1 \
        || { echo "!! cannot reach $DB_HOST:$DB_PORT"; exit 1; }
fi

echo "==> building the reference database from schema.sql"
db -e "DROP DATABASE IF EXISTS lrms_fresh; CREATE DATABASE lrms_fresh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
db lrms_fresh < "$ROOT/schema.sql"

echo "==> building a pre-release database (same schema, new objects removed)"
db -e "DROP DATABASE IF EXISTS lrms_upg; CREATE DATABASE lrms_upg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
db lrms_upg < "$ROOT/schema.sql"

# The downgrade. Deliberately hand-written: it is the inverse of the documented
# migration, so if the two disagree the comparison below fails and says so.
db lrms_upg <<'SQL'
-- Undo of the newest release, applied first because it is the newest.
DELETE FROM `role_permissions`
 WHERE `role_id` = 3
   AND `permission_id` IN (
     SELECT `id` FROM `permissions` WHERE `code` IN ('customers.update', 'custom_fields.manage')
   );

-- The pre-release users table still carried the staff portrait columns, so they have to
-- come back for the migration's DROP to have something to drop.
ALTER TABLE `users`
  ADD COLUMN `photo_path`     VARCHAR(500) DEFAULT NULL COMMENT 'uploads-relative; printed on visit reports' AFTER `status_changed_at`,
  ADD COLUMN `signature_path` VARCHAR(500) DEFAULT NULL COMMENT 'uploads-relative; printed beside the photo'  AFTER `photo_path`;

ALTER TABLE `loan_accounts`
  DROP KEY `idx_loan_classification`,
  DROP COLUMN `asset_classification`,
  DROP COLUMN `interest_rate`,
  DROP COLUMN `installment_amount`,
  DROP COLUMN `last_payment_date`,
  DROP COLUMN `last_payment_amount`,
  DROP COLUMN `days_past_due`,
  DROP COLUMN `security_value`,
  DROP COLUMN `guarantor_name`,
  DROP COLUMN `maturity_date`,
  DROP COLUMN `purpose`;

ALTER TABLE `signatures`
  DROP COLUMN `capture_method`,
  DROP COLUMN `uploaded_note`;

ALTER TABLE `signatures`
  DROP COLUMN `gps_latitude`,
  DROP COLUMN `gps_longitude`,
  DROP COLUMN `gps_accuracy_m`,
  DROP COLUMN `gps_source`;

ALTER TABLE `photos`
  MODIFY COLUMN `photo_type` ENUM('customer','house','land','aadhaar','passbook',
                                  'renewal_form','other') NOT NULL DEFAULT 'other';

DROP TABLE IF EXISTS `custom_field_values`;
DROP TABLE IF EXISTS `custom_field_definitions`;
DROP TABLE IF EXISTS `visit_report_revisions`;

ALTER TABLE `visit_reports`
  DROP FOREIGN KEY `fk_visit_approver`;
ALTER TABLE `visit_reports`
  DROP KEY `idx_visit_approval`,
  DROP COLUMN `approval_status`,
  DROP COLUMN `approved_by`,
  DROP COLUMN `approver_name`,
  DROP COLUMN `approved_at`,
  DROP COLUMN `approval_remarks`,
  DROP COLUMN `approval_photo_path`,
  DROP COLUMN `approval_signature_path`,
  DROP COLUMN `approval_gps_latitude`,
  DROP COLUMN `approval_gps_longitude`,
  DROP COLUMN `approval_gps_accuracy_m`,
  DROP COLUMN `approval_gps_source`,
  DROP COLUMN `revision_count`,
  DROP COLUMN `updated_at`;

ALTER TABLE `users`
  DROP COLUMN `photo_path`,
  DROP COLUMN `signature_path`;

ALTER TABLE `loan_accounts`
  DROP COLUMN `closure_amount`,
  DROP COLUMN `manual_overrides`;

ALTER TABLE `visit_history`
  MODIFY COLUMN `event_type` ENUM('lead_imported','lead_updated','assigned','reassigned','transferred',
                                  'visit','promise_created','promise_kept','promise_broken',
                                  'status_changed','closed','reopened','note') NOT NULL;

DELETE FROM `role_permissions` WHERE `permission_id` IN
  (SELECT `id` FROM `permissions` WHERE `code` IN ('visits.approve','visits.revise','custom_fields.manage'));
DELETE FROM `permissions`
  WHERE `code` IN ('visits.approve','visits.revise','custom_fields.manage');
SQL

# A populated database, because an ALTER that works on empty tables and fails on real
# rows is the only kind that matters here. A NOT NULL column with no default added to
# a table that already has rows is exactly where a migration falls over.
echo "==> seeding rows so the ALTERs run against real data"
db lrms_upg <<'SQL'
INSERT INTO `branches` (`branch_code`, `name`, `status`)
  VALUES ('BR900', 'Upgrade Test Branch', 'active');
SET @br = (SELECT `id` FROM `branches` WHERE `branch_code` = 'BR900');

INSERT INTO `users` (`employee_code`, `name`, `password_hash`, `role_id`, `branch_id`)
  VALUES ('UPG001', 'Upgrade Agent', '$2y$10$abcdefghijklmnopqrstuv', 3, @br);
SET @ag = (SELECT `id` FROM `users` WHERE `employee_code` = 'UPG001');

INSERT INTO `customers` (`branch_id`, `name`, `village`)
  VALUES (@br, 'Upgrade Borrower', 'Nowhere');
SET @cu = (SELECT `id` FROM `customers` WHERE `name` = 'Upgrade Borrower');

INSERT INTO `loan_accounts` (`loan_account_number`, `customer_id`, `branch_id`, `outstanding_amount`, `overdue_amount`)
  VALUES ('UPGLN1', @cu, @br, 1000.00, 100.00);
SET @la = (SELECT `id` FROM `loan_accounts` WHERE `loan_account_number` = 'UPGLN1');

INSERT INTO `visit_reports` (`loan_account_id`, `customer_id`, `agent_id`, `branch_id`,
                             `visit_date`, `visit_time`, `agent_name`, `customer_name`,
                             `loan_account_number`)
  VALUES (@la, @cu, @ag, @br, CURDATE(), '10:30', 'Upgrade Agent', 'Upgrade Borrower', 'UPGLN1');

INSERT INTO `visit_history` (`loan_account_id`, `event_type`, `event_at`, `actor_name`, `title`)
  VALUES (@la, 'visit', NOW(), 'Upgrade Agent', 'Visit filed');
SQL

ROWS=$(dbq lrms_upg -e "SELECT COUNT(*) FROM visit_reports;" | tr -d '[:space:]')
check "the pre-release database has rows in it" \
      "$([ "$ROWS" = "1" ] && echo 1 || echo 0)" "visit_reports=$ROWS"

echo "==> running the migration from DEPLOYMENT.md"
# shellcheck disable=SC2086
if $CLIENT lrms_upg < "$WORK/migration.sql" 2>"$WORK/migrate.err"; then
    ok "the documented SQL runs without error on a populated database"
else
    bad "the documented SQL runs without error on a populated database" \
        "$(grep -v 'Using a password' "$WORK/migrate.err" | tr '\n' ' ' | head -c 300)"
    echo; echo "---- migration stderr ----"
    grep -v 'Using a password' "$WORK/migrate.err" || true
    exit 1
fi

check "the existing visit report survived the migration" \
  "$([ "$(dbq lrms_upg -e 'SELECT COUNT(*) FROM visit_reports;' | tr -d '[:space:]')" = "1" ] && echo 1 || echo 0)"

echo "==> comparing the upgraded schema against schema.sql"
UPGRADE_CLIENT="$CLIENT" python3 - <<'PY' > "$WORK/compare.out" 2>&1 || true
import subprocess, os, sys, json

CLIENT = os.environ['UPGRADE_CLIENT'].split()

def q(db, sql):
    out = subprocess.run(CLIENT + ['-N', '-B', db, '-e', sql],
                         capture_output=True, text=True)
    return [line.split('\t') for line in out.stdout.strip().splitlines() if line.strip()]

TABLES = ['users','loan_accounts','visit_reports','visit_history',
          'visit_report_revisions','custom_field_definitions','custom_field_values',
          'photos','signatures']
tlist = "','".join(TABLES)
results = []

def as_map(rows, keyparts):
    return {tuple(r[:keyparts]): r[keyparts:] for r in rows}

# Columns compared by name, not ordinal: ADD COLUMN without AFTER lands a column at the
# end, which is cosmetic. Type, nullability, default and comment are not cosmetic.
COLS = ("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, "
        "IFNULL(COLUMN_DEFAULT,'~'), IFNULL(EXTRA,''), IFNULL(COLUMN_COMMENT,'') "
        "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
        f"AND TABLE_NAME IN ('{tlist}') ORDER BY TABLE_NAME, COLUMN_NAME")

fresh = as_map(q('lrms_fresh', COLS), 2)
upg   = as_map(q('lrms_upg',   COLS), 2)
if not fresh:
    print('!! the reference database returned no columns'); sys.exit(1)

missing = sorted(set(fresh) - set(upg))
extra   = sorted(set(upg) - set(fresh))
differ  = sorted(k for k in set(fresh) & set(upg) if fresh[k] != upg[k])
results.append(('every column exists after the upgrade', not missing,
                'missing: ' + ', '.join('.'.join(m) for m in missing[:6])))
results.append(('no column the shipped schema does not have', not extra,
                'extra: ' + ', '.join('.'.join(x) for x in extra[:6])))
results.append(('every column definition matches exactly', not differ,
                '; '.join(f"{'.'.join(k)}: {fresh[k]} != {upg[k]}" for k in differ[:4])))

IDX = ("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) "
       "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
       f"AND TABLE_NAME IN ('{tlist}') GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE "
       "ORDER BY TABLE_NAME, INDEX_NAME")
fi, ui = as_map(q('lrms_fresh', IDX), 2), as_map(q('lrms_upg', IDX), 2)
imissing = sorted(set(fi) - set(ui))
idiffer  = sorted(k for k in set(fi) & set(ui) if fi[k] != ui[k])
results.append(('every index exists after the upgrade', not imissing,
                'missing: ' + ', '.join('.'.join(m) for m in imissing[:6])))
results.append(('every index covers the same columns', not idiffer,
                '; '.join(f"{'.'.join(k)}: {fi[k]} != {ui[k]}" for k in idiffer[:4])))

# Foreign keys with their delete rules - ON DELETE SET NULL vs CASCADE is the
# difference between keeping a revision log and shredding it with a user account.
FK = ("SELECT rc.TABLE_NAME, rc.CONSTRAINT_NAME, rc.DELETE_RULE, "
      "  GROUP_CONCAT(CONCAT(k.COLUMN_NAME,'>',k.REFERENCED_TABLE_NAME,'.',k.REFERENCED_COLUMN_NAME) "
      "               ORDER BY k.ORDINAL_POSITION) "
      "FROM information_schema.REFERENTIAL_CONSTRAINTS rc "
      "JOIN information_schema.KEY_COLUMN_USAGE k "
      "  ON k.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME=rc.CONSTRAINT_NAME "
      "WHERE rc.CONSTRAINT_SCHEMA=DATABASE() "
      f"AND rc.TABLE_NAME IN ('{tlist}') GROUP BY 1,2,3 ORDER BY 1,2")
ff, uf = as_map(q('lrms_fresh', FK), 2), as_map(q('lrms_upg', FK), 2)
fmissing = sorted(set(ff) - set(uf))
fdiffer  = sorted(k for k in set(ff) & set(uf) if ff[k] != uf[k])
results.append(('every foreign key exists after the upgrade', not fmissing,
                'missing: ' + ', '.join('.'.join(m) for m in fmissing[:6])))
results.append(('every foreign key has the same delete rule', not fdiffer,
                '; '.join(f"{'.'.join(k)}: {ff[k]} != {uf[k]}" for k in fdiffer[:4])))

# Whole-database counts, so a table the migration forgot entirely is caught.
for label, sql in [
    ('the upgraded database has the same table count',
     "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()"),
    ('the upgraded database has the same foreign key count',
     "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()"),
    ('the same permissions exist', "SELECT COUNT(*) FROM permissions"),
]:
    a, b = q('lrms_fresh', sql)[0][0], q('lrms_upg', sql)[0][0]
    results.append((label, a == b, f'schema.sql={a} upgraded={b}'))

# Grants per role: a permission row nobody holds is a screen nobody can reach.
GRANTS = ("SELECT r.role_name, GROUP_CONCAT(p.code ORDER BY p.code) FROM role_permissions rp "
          "JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id "
          "GROUP BY r.role_name ORDER BY r.role_name")
fg, ug = as_map(q('lrms_fresh', GRANTS), 1), as_map(q('lrms_upg', GRANTS), 1)
gdiffer = sorted(k for k in set(fg) & set(ug) if fg[k] != ug[k])
def note(k):
    a, b = set(fg[k][0].split(',')), set(ug[k][0].split(','))
    return f"{k[0]}: missing {sorted(a-b)} extra {sorted(b-a)}"
results.append(('every role holds exactly the permissions it should',
                not gdiffer and set(fg) == set(ug),
                '; '.join(note(k) for k in gdiffer[:3])))

print(json.dumps(results))
PY

RESULTS=$(tail -1 "$WORK/compare.out")
case "$RESULTS" in
  \[*) ;;
  *) echo "!! comparison failed to run:"; cat "$WORK/compare.out"; exit 1 ;;
esac

EXPECTED_COMPARISONS=11
BEFORE=$((PASS + FAIL))

eval "$(printf '%s' "$RESULTS" | python3 -c '
import json, sys, shlex
for label, ok, detail in json.load(sys.stdin):
    parts = ["check", shlex.quote(label), "1" if ok else "0", shlex.quote(detail or "")]
    print(" ".join(parts))
')"

# Without this the script happily prints "OK" having run three checks and skipped
# every schema comparison - a harness that passes while doing nothing is worse than
# one that fails, because it is believed.
RAN=$(( PASS + FAIL - BEFORE ))
if [ "$RAN" -ne "$EXPECTED_COMPARISONS" ]; then
  echo
  echo "!! expected $EXPECTED_COMPARISONS schema comparisons, got $RAN - the comparison did not run"
  cat "$WORK/compare.out"
  exit 1
fi

db -e "DROP DATABASE IF EXISTS lrms_fresh; DROP DATABASE IF EXISTS lrms_upg;" >/dev/null 2>&1 || true

echo
echo "============================================================"
printf "  UPGRADE SQL: %d passed, %d failed\n" "$PASS" "$FAIL"
echo "============================================================"
[ "$FAIL" -eq 0 ] || exit 1
echo
echo "UPGRADE SQL OK"
