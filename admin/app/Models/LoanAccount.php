<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Paginator;

/**
 * A loan account IS the lead in this system. Assignment, status and visit
 * counters all live here; borrower identity lives on `customers`.
 */
final class LoanAccount
{
    public const SORTABLE = [
        'loan_account_number', 'customer_name', 'village', 'outstanding_amount',
        'overdue_amount', 'npa_date', 'current_status', 'last_visit_at', 'created_at',
    ];

    public const STATUSES = ['pending', 'visited', 'promise', 'followup', 'legal', 'closed'];

    /**
     * Base SELECT joining the borrower, branch and agent.
     * Masked PII only - nothing here needs the decryption key.
     */
    /**
     * Every loan_accounts column any screen reads, in one place.
     *
     * This used to be spelled out twice - once here and once in findWithPii() - and a
     * column added to only one of them was written correctly and invisible everywhere
     * else. That is bug 42 in the README, and it happened AGAIN with closure_amount and
     * manual_overrides, which is what finally earned this constant. Two hand-maintained
     * lists of the same table will keep diverging; one will not.
     */
    private const LOAN_COLUMNS = "la.id, la.loan_account_number, la.bc_code, la.loan_type,
                   la.outstanding_amount, la.overdue_amount, la.npa_date, la.is_npa,
                   la.current_status, la.assigned_agent_id, la.assigned_at, la.last_visit_at,
                   la.visit_count, la.next_followup_date, la.remarks, la.created_at, la.updated_at,
                   la.customer_id, la.branch_id, la.closed_at, la.last_promise_id,
                   la.cif_number, la.sanction_date, la.sanction_limit, la.drawing_power,
                   la.interest_overdue, la.ckcc_renewal_due_date,
                   la.ots_eligible, la.krm_eligible, la.ots_amount, la.deposit_amount,
                   la.closure_amount, la.manual_overrides,
                   la.asset_classification, la.interest_rate, la.installment_amount,
                   la.last_payment_date, la.last_payment_amount, la.days_past_due,
                   la.security_value, la.guarantor_name, la.maturity_date, la.purpose,
                   la.facility_type";

    /** The joined borrower / branch / agent columns every screen also reads. */
    private const JOINED_COLUMNS = "c.name AS customer_name, c.father_husband_name, c.village, c.address,
                   c.mobile_masked, c.aadhaar_masked,
                   c.alt_mobile_masked, c.alt_mobile_label,
                   b.name AS branch_name, b.branch_code,
                   ag.name AS agent_name, ag.employee_code AS agent_code";

    private const FROM = "FROM loan_accounts la
              JOIN customers c ON c.id = la.customer_id
              JOIN branches  b ON b.id = la.branch_id
              LEFT JOIN users ag ON ag.id = la.assigned_agent_id";

    private const SELECT = "SELECT " . self::LOAN_COLUMNS . ", " . self::JOINED_COLUMNS . " " . self::FROM;

    public static function find(int $id): ?array
    {
        return Database::instance()->first(self::SELECT . ' WHERE la.id = ? LIMIT 1', [$id]);
    }

    public static function findByNumber(string $loanAccountNumber): ?array
    {
        return Database::instance()->first(
            self::SELECT . ' WHERE la.loan_account_number = ? LIMIT 1',
            [$loanAccountNumber]
        );
    }

    /**
     * Full profile including decrypted PII. Only call this from a context that
     * has already passed a customers.view_pii permission check.
     *
     * @return array<string,mixed>|null
     */
    public static function findWithPii(int $id): ?array
    {
        // The encrypted columns have to sit inside the projection rather than be
        // appended after the JOINs, so this builds its own SELECT - but from the SAME
        // column constants, so it can no longer fall behind.
        $row = Database::instance()->first(
            'SELECT ' . self::LOAN_COLUMNS . ', ' . self::JOINED_COLUMNS
                . ', c.mobile_enc, c.alt_mobile_enc, c.aadhaar_enc ' . self::FROM . ' WHERE la.id = ? LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return null;
        }

        $row['mobile'] = Crypto::decrypt($row['mobile_enc'] ?? null);
        $row['alt_mobile'] = Crypto::decrypt($row['alt_mobile_enc'] ?? null);
        $row['aadhaar'] = Crypto::decrypt($row['aadhaar_enc'] ?? null);
        unset($row['mobile_enc'], $row['alt_mobile_enc'], $row['aadhaar_enc']);

        return $row;
    }

    /**
     * The one list query behind the admin leads grid, the API lead list and the
     * agent's assigned-leads screen.
     *
     * @param array{
     *   search?:string, branch_id?:int|null, agent_id?:int|null, status?:string,
     *   village?:string, loan_type?:string, npa_only?:bool, unassigned?:bool,
     *   date_from?:string, date_to?:string
     * } $filters
     */
    /**
     * Fills in the decrypted `mobile` for a page of rows.
     *
     * The list projection deliberately carries only `mobile_masked`, so a grid
     * never decrypts anything. But the agent app needs a dialable number in the
     * list itself: its Call button is enabled from this field, and without it the
     * button never appears and an agent has to open every lead one by one just to
     * phone the borrower.
     *
     * Done as ONE extra query for the whole page rather than a lookup per row, and it
     * resolves the two phone numbers only - Aadhaar stays out of list responses, since
     * nothing in a list needs it and bulk-shipping it would widen the blast radius of any
     * single leaked response for no benefit.
     *
     * The alternate number is here for the same reason the first one is: it is the number
     * that answers, and an agent who has to open the borrower to find it will phone the
     * dead number instead.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function attachMobiles(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $customerIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['customer_id'] ?? 0);
            if ($id > 0) {
                $customerIds[$id] = true;
            }
        }
        if ($customerIds === []) {
            return $rows;
        }

        $ids = array_keys($customerIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $encrypted = Database::instance()->all(
            'SELECT id, mobile_enc, alt_mobile_enc FROM customers WHERE id IN (' . $placeholders . ')',
            $ids
        );

        $byId = [];
        foreach ($encrypted as $row) {
            $byId[(int) $row['id']] = [
                Crypto::decrypt($row['mobile_enc'] ?? null),
                Crypto::decrypt($row['alt_mobile_enc'] ?? null),
            ];
        }

        foreach ($rows as $index => $row) {
            [$mobile, $altMobile] = $byId[(int) ($row['customer_id'] ?? 0)] ?? [null, null];
            $rows[$index]['mobile'] = $mobile;
            $rows[$index]['alt_mobile'] = $altMobile;
        }

        return $rows;
    }

    public static function paginate(array $filters, string $sortBy, string $sortDir, int $page, int $perPage): Paginator
    {
        [$clause, $params] = self::buildWhere($filters);

        $orderColumn = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'created_at';
        $direction = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        // customer_name and village live on the joined table.
        $orderExpression = match ($orderColumn) {
            'customer_name' => 'c.name',
            'village'       => 'c.village',
            default         => 'la.`' . $orderColumn . '`',
        };

        return Paginator::fromQuery(
            "SELECT COUNT(*)
               FROM loan_accounts la
               JOIN customers c ON c.id = la.customer_id
               JOIN branches  b ON b.id = la.branch_id
               LEFT JOIN users ag ON ag.id = la.assigned_agent_id
              WHERE {$clause}",
            self::SELECT . " WHERE {$clause} ORDER BY {$orderExpression} {$direction}, la.id DESC",
            $params,
            $page,
            $perPage
        );
    }

    /**
     * Search across loan account number, name, village (LIKE) plus mobile and
     * Aadhaar (exact, via HMAC because those columns are encrypted).
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $mobileHash = Crypto::searchHash($search);
            $aadhaarHash = Crypto::searchHash($search);

            $conditions = [
                'la.loan_account_number LIKE ?',
                'c.name LIKE ?',
                'c.village LIKE ?',
                'c.father_husband_name LIKE ?',
                'la.bc_code LIKE ?',
            ];
            array_push($params, $like, $like, $like, $like, $like);

            // Only add hash predicates when the term could be a number, so a
            // name search does not pay for two extra index lookups.
            if ($mobileHash !== null) {
                $conditions[] = 'c.mobile_hash = ?';
                // The alternate number too. Somebody searching a phone number is usually
                // searching the number that just called them, and the whole reason a second
                // number exists on the record is that the first one does not answer.
                $conditions[] = 'c.alt_mobile_hash = ?';
                $conditions[] = 'c.aadhaar_hash = ?';
                $params[] = $mobileHash;
                $params[] = $mobileHash;
                $params[] = $aadhaarHash;
            }

            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        if (!empty($filters['branch_id'])) {
            $where[] = 'la.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        if (!empty($filters['agent_id'])) {
            $where[] = 'la.assigned_agent_id = ?';
            $params[] = (int) $filters['agent_id'];
        }

        if (!empty($filters['unassigned'])) {
            $where[] = 'la.assigned_agent_id IS NULL';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 'la.current_status = ?';
            $params[] = $status;
        }

        $village = trim((string) ($filters['village'] ?? ''));
        if ($village !== '') {
            $where[] = 'c.village = ?';
            $params[] = $village;
        }

        $loanType = trim((string) ($filters['loan_type'] ?? ''));
        if ($loanType !== '') {
            $where[] = 'la.loan_type = ?';
            $params[] = $loanType;
        }

        if (!empty($filters['npa_only'])) {
            $where[] = 'la.is_npa = 1';
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'la.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'la.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Status counts for the current filter set, used by the filter chips.
     *
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    public static function statusCounts(array $filters): array
    {
        // Status itself must not constrain the breakdown.
        unset($filters['status']);
        [$clause, $params] = self::buildWhere($filters);

        $rows = Database::instance()->all(
            "SELECT la.current_status AS status, COUNT(*) AS total
               FROM loan_accounts la
               JOIN customers c ON c.id = la.customer_id
               JOIN branches  b ON b.id = la.branch_id
               LEFT JOIN users ag ON ag.id = la.assigned_agent_id
              WHERE {$clause}
              GROUP BY la.current_status",
            $params
        );

        $counts = array_fill_keys(self::STATUSES, 0);
        $counts['all'] = 0;

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
            $counts['all'] += (int) $row['total'];
        }

        return $counts;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::instance()->insert('loan_accounts', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::instance()->update('loan_accounts', $data, ['id' => $id]);
    }

    /**
     * Rows needed to validate a bulk action: id, branch and current agent for
     * each selected lead, so the caller can enforce branch scoping.
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public static function findManyForAction(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return Database::instance()->all(
            "SELECT la.id, la.loan_account_number, la.branch_id, la.customer_id,
                    la.assigned_agent_id, la.current_status, c.name AS customer_name
               FROM loan_accounts la
               JOIN customers c ON c.id = la.customer_id
              WHERE la.id IN ({$placeholders})",
            array_map('intval', $ids)
        );
    }

    /** Distinct villages for filter dropdowns. @return list<string> */
    public static function villages(?int $branchId = null): array
    {
        $sql = "SELECT DISTINCT c.village
                  FROM customers c
                 WHERE c.village IS NOT NULL AND c.village <> ''";
        $params = [];

        if ($branchId !== null) {
            $sql .= ' AND c.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY c.village ASC LIMIT 1000';

        return array_map(
            static fn (array $r): string => (string) $r['village'],
            Database::instance()->all($sql, $params)
        );
    }

    /** Distinct loan types for filter dropdowns. @return list<string> */
    public static function loanTypes(?int $branchId = null): array
    {
        $sql = "SELECT DISTINCT loan_type FROM loan_accounts WHERE loan_type IS NOT NULL AND loan_type <> ''";
        $params = [];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY loan_type ASC LIMIT 200';

        return array_map(
            static fn (array $r): string => (string) $r['loan_type'],
            Database::instance()->all($sql, $params)
        );
    }

    /**
     * Applies hand edits to loan figures and remembers which columns were touched.
     *
     * The columns here are fed by the core banking Excel export. Left alone, the next
     * import overwrites whatever somebody corrected in the panel - silently, which is
     * why they used to be read-only. Recording the override lets the importer skip
     * them and say so, which is the only way "editable" and "the import is the source
     * of truth" can both be true.
     *
     * Returns the columns that actually moved, so the audit summary names them.
     *
     * @param  array<string,mixed>  $edits
     * @return array<string,array{from:mixed,to:mixed}>
     */
    public static function applyManualEdit(int $id, array $edits, ?int $userId): array
    {
        if ($edits === []) {
            return [];
        }

        $db = Database::instance();

        $current = $db->first(
            'SELECT * FROM loan_accounts WHERE id = ? LIMIT 1',
            [$id]
        );

        if ($current === null) {
            return [];
        }

        $overrides = [];
        if (is_string($current['manual_overrides'] ?? null) && $current['manual_overrides'] !== '') {
            $decoded = json_decode((string) $current['manual_overrides'], true);
            $overrides = is_array($decoded) ? $decoded : [];
        }

        $changed = [];
        $update = [];
        $now = date('Y-m-d H:i:s');

        foreach ($edits as $column => $value) {
            if (!array_key_exists($column, self::MANUALLY_EDITABLE)) {
                continue;
            }

            $before = $current[$column] ?? null;

            // Compared as strings so 1000 and "1000.00" do not register as an edit -
            // otherwise every save would mark every figure as hand-edited and the
            // importer would stop updating anything at all.
            if (self::sameValue($before, $value)) {
                continue;
            }

            $changed[$column] = ['from' => $before, 'to' => $value];
            $update[$column] = $value;
            $overrides[$column] = ['by' => $userId, 'at' => $now];
        }

        if ($changed === []) {
            return [];
        }

        $update['manual_overrides'] = json_encode($overrides, JSON_UNESCAPED_UNICODE);

        // Derived flag has to follow the date it is derived from, or a cleared NPA date
        // leaves the row still flagged as NPA.
        if (array_key_exists('npa_date', $update)) {
            $update['is_npa'] = ($update['npa_date'] ?? null) === null ? 0 : 1;
        }

        $db->update('loan_accounts', $update, ['id' => $id]);

        return $changed;
    }

    /**
     * Columns a human may correct by hand, and their labels.
     *
     * @return array<string,string>
     */
    public const MANUALLY_EDITABLE = [
        'loan_type'             => 'Loan type',
        'cif_number'            => 'CIF number',
        'outstanding_amount'    => 'Outstanding amount',
        'overdue_amount'        => 'Overdue amount',
        'closure_amount'        => 'Closure amount',
        'ots_amount'            => 'OTS amount',
        'deposit_amount'        => 'Deposit amount',
        'npa_date'              => 'NPA date',
        'ckcc_renewal_due_date' => 'CKCC renewal due date',
        // The rest of the statement. Editable for the same reason the figures above
        // are: the person standing at the door is the one who finds out the guarantor
        // died or the security was sold, and the alternative to letting them record it
        // is a note in a notebook nobody else can read.
        'asset_classification'  => 'Asset classification',
        'interest_rate'         => 'Interest rate',
        'installment_amount'    => 'Instalment amount',
        'last_payment_date'     => 'Last payment date',
        'last_payment_amount'   => 'Last payment amount',
        'days_past_due'         => 'Days past due',
        'security_value'        => 'Security value',
        'guarantor_name'        => 'Guarantor name',
        'maturity_date'         => 'Maturity date',
        'purpose'               => 'Purpose',
        // A product name is not always a facility name, so the derived value has to be
        // correctable - and the correction has to survive the next import like any other.
        'facility_type'         => 'Facility (KCC / OD-2)',
        // The sanction side of the passbook, and a free-text note.
        //
        // These were import-owned and unreachable, which made a passbook the borrower was
        // holding out at the door unusable: the agent could read the sanction limit off it
        // and had nowhere to put it. `remarks` is the one that gets used most - what an
        // agent learns at a doorstep is rarely a number ("shifted to Delhi, brother works
        // the land") and a field for it is the difference between that reaching the branch
        // and staying in somebody's notebook.
        'sanction_date'         => 'Sanction date',
        'sanction_limit'        => 'Sanction limit',
        'drawing_power'         => 'Drawing power',
        'interest_overdue'      => 'Interest overdue',
        'remarks'               => 'Notes on this account',
        // The last of the real data. Everything an import can write, a person can now
        // correct; what is left out below is derived or plumbing, nothing else.
        'bc_code'               => 'BC / DC code on the account',
        'ots_eligible'          => 'Eligible for OTS',
        'krm_eligible'          => 'Eligible for KRM',
        'next_followup_date'    => 'Next follow-up date',
    ];

    /*
     * What is deliberately NOT in the list above, and why. "The rest is editable" is a
     * promise, so the exceptions have to be defensible one at a time.
     *
     *   id, customer_id, import_id, manual_overrides, created_at, updated_at
     *       Plumbing. Nothing on a screen should be able to re-point a row at a different
     *       borrower or a different import batch.
     *
     *   is_npa
     *       Derived from npa_date, set in applyManualEdit() when that date moves. Two
     *       fields that can disagree about whether an account is NPA is worse than one.
     *
     *   visit_count, last_visit_at, last_promise_id
     *       Counted from visit_reports and promises. A hand-set value is a lie the next
     *       counter repair silently corrects, so the edit would appear to work and then
     *       undo itself - the worst kind of editable field.
     *
     *   assigned_at, assigned_by, closed_at
     *       Stamps written by the action that caused them. A stamp without the action is a
     *       record of something that did not happen.
     *
     *   branch_id, assigned_agent_id
     *       A branch move is a transfer and handing a lead over is an assignment. Both
     *       have their own permission, their own audit event, their own timeline entry, and
     *       assignment notifies the agent who receives it. Editing the column silently
     *       would skip all of that.
     *
     * loan_account_number and current_status ARE editable, but not through this list -
     * see CustomerController::edit(), which renames with a uniqueness check and moves the
     * status through AssignmentService so the timeline still says what happened.
     */

    /** The facilities that have their own renewal queue. */
    public const FACILITIES = [
        'kcc'   => 'KCC',
        'od2'   => 'OD-2',
        'other' => 'Other',
    ];

    /** Which columns on this row a human has overridden. @return list<string> */
    public static function overriddenColumns(?string $manualOverrides): array
    {
        if (!is_string($manualOverrides) || $manualOverrides === '') {
            return [];
        }

        $decoded = json_decode($manualOverrides, true);

        return is_array($decoded) ? array_keys($decoded) : [];
    }

    /** Loose equality that treats 1000 and "1000.00" as the same figure. */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return ($a === null) === ($b === null);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.005;
        }

        return (string) $a === (string) $b;
    }

    /**
     * Recomputes the denormalised visit counters after a new visit is filed.
     * Derived from visit_reports so it is always reconcilable.
     */
    public static function refreshVisitCounters(int $loanAccountId): void
    {
        Database::instance()->query(
            'UPDATE loan_accounts la
                SET la.visit_count = (SELECT COUNT(*) FROM visit_reports vr WHERE vr.loan_account_id = la.id),
                    la.last_visit_at = (SELECT MAX(vr.created_at) FROM visit_reports vr WHERE vr.loan_account_id = la.id)
              WHERE la.id = ?',
            [$loanAccountId]
        );
    }

    /**
     * Repairs every account whose visit counters disagree with `visit_reports`.
     *
     * `refreshVisitCounters()` only ever runs for the account a visit was just filed
     * against, so a row that drifts stays wrong until somebody happens to visit that
     * borrower again - which for a closed or reassigned account may be never. The
     * drift matters because `last_visit_at` drives the "no visit for N days" nudge in
     * cron/reminders.php: a count that is too high silently suppresses the reminder
     * for a borrower nobody has been to see.
     *
     * Only mismatched rows are touched, so this is cheap enough to run daily and the
     * returned count is a real signal - anything other than zero means something
     * wrote to visit_reports outside VisitService.
     *
     * @return int rows corrected
     */
    public static function rebuildVisitCounters(): int
    {
        return Database::instance()->query(
            'UPDATE loan_accounts la
               JOIN (
                     SELECT la2.id,
                            (SELECT COUNT(*) FROM visit_reports vr WHERE vr.loan_account_id = la2.id) AS real_count,
                            (SELECT MAX(vr.created_at) FROM visit_reports vr WHERE vr.loan_account_id = la2.id) AS real_last
                       FROM loan_accounts la2
                    ) AS truth ON truth.id = la.id
                SET la.visit_count = truth.real_count,
                    la.last_visit_at = truth.real_last
              WHERE la.visit_count <> truth.real_count
                 OR (la.last_visit_at IS NULL) <> (truth.real_last IS NULL)
                 OR (la.last_visit_at IS NOT NULL AND truth.real_last IS NOT NULL
                     AND la.last_visit_at <> truth.real_last)'
        )->rowCount();
    }
}
