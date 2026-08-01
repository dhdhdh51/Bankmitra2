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
    private const SELECT = "SELECT la.id, la.loan_account_number, la.bc_code, la.loan_type,
                   la.outstanding_amount, la.overdue_amount, la.npa_date, la.is_npa,
                   la.current_status, la.assigned_agent_id, la.assigned_at, la.last_visit_at,
                   la.visit_count, la.next_followup_date, la.remarks, la.created_at, la.updated_at,
                   la.customer_id, la.branch_id, la.closed_at, la.last_promise_id,
                   la.cif_number, la.sanction_date, la.sanction_limit, la.drawing_power,
                   la.interest_overdue, la.ckcc_renewal_due_date,
                    la.ots_eligible, la.krm_eligible, la.ots_amount, la.deposit_amount,
                   c.name AS customer_name, c.father_husband_name, c.village, c.address,
                   c.mobile_masked, c.aadhaar_masked,
                   b.name AS branch_name, b.branch_code,
                   ag.name AS agent_name, ag.employee_code AS agent_code
              FROM loan_accounts la
              JOIN customers c ON c.id = la.customer_id
              JOIN branches  b ON b.id = la.branch_id
              LEFT JOIN users ag ON ag.id = la.assigned_agent_id";

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
        // The encrypted columns must be added to the projection, not appended
        // after the JOIN clauses of self::SELECT, so this query is spelled out.
        $row = Database::instance()->first(
            'SELECT la.id, la.loan_account_number, la.bc_code, la.loan_type,
                    la.outstanding_amount, la.overdue_amount, la.npa_date, la.is_npa,
                    la.current_status, la.assigned_agent_id, la.assigned_at, la.last_visit_at,
                    la.visit_count, la.next_followup_date, la.remarks, la.created_at, la.updated_at,
                    la.customer_id, la.branch_id, la.closed_at,
                    la.cif_number, la.sanction_date, la.sanction_limit, la.drawing_power,
                    la.interest_overdue, la.ckcc_renewal_due_date,
                    la.ots_eligible, la.krm_eligible, la.ots_amount, la.deposit_amount,
                    c.name AS customer_name, c.father_husband_name, c.village, c.address,
                    c.mobile_masked, c.aadhaar_masked, c.mobile_enc, c.aadhaar_enc,
                    b.name AS branch_name, b.branch_code,
                    ag.name AS agent_name, ag.employee_code AS agent_code
               FROM loan_accounts la
               JOIN customers c ON c.id = la.customer_id
               JOIN branches  b ON b.id = la.branch_id
               LEFT JOIN users ag ON ag.id = la.assigned_agent_id
              WHERE la.id = ? LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return null;
        }

        $row['mobile'] = Crypto::decrypt($row['mobile_enc'] ?? null);
        $row['aadhaar'] = Crypto::decrypt($row['aadhaar_enc'] ?? null);
        unset($row['mobile_enc'], $row['aadhaar_enc']);

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
     * Done as ONE extra query for the whole page rather than a lookup per row,
     * and it resolves `mobile` only - Aadhaar stays out of list responses, since
     * nothing in a list needs it and bulk-shipping it would widen the blast
     * radius of any single leaked response for no benefit.
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
            'SELECT id, mobile_enc FROM customers WHERE id IN (' . $placeholders . ')',
            $ids
        );

        $byId = [];
        foreach ($encrypted as $row) {
            $byId[(int) $row['id']] = Crypto::decrypt($row['mobile_enc'] ?? null);
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['mobile'] = $byId[(int) ($row['customer_id'] ?? 0)] ?? null;
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
                $conditions[] = 'c.aadhaar_hash = ?';
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
}
