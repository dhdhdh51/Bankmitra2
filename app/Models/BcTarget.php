<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;

/**
 * Monthly targets for a BC/DC agent.
 *
 * One row per agent per month, enforced by a unique key. That matters more than it
 * looks: `BcPerformanceService` measures every warning against the target row for
 * the month, so two rows for the same month would mean two different answers to
 * "did this agent miss" - and a warning is something an agent can be disciplined
 * over. Editing is therefore an update, never an insert of a second row.
 */
final class BcTarget
{
    public const SORTABLE = ['target_month', 'agent_name', 'employee_code', 'branch_name', 'updated_at'];

    /**
     * The count metrics, and the labels used on both the form and the list.
     *
     * Keys are the `bc_targets` columns. Kept here so the form, the validator and
     * the table cannot drift apart - three hand-maintained lists of the same seven
     * fields is how a renamed column becomes a silently ignored input.
     *
     * @return array<string,string>
     */
    public static function countFields(): array
    {
        return [
            'daily_visit_target' => 'Visits per working day',
            'apy_target' => 'APY enrolments',
            'pmjjby_target' => 'PMJJBY enrolments',
            'pmsby_target' => 'PMSBY enrolments',
            'pmjdy_target' => 'PMJDY accounts',
            'od2_renewal_target' => 'OD-2 / CKCC renewals',
        ];
    }

    public static function find(int $id): ?array
    {
        return Database::instance()->first(
            'SELECT t.*, u.name AS agent_name, u.employee_code, u.branch_id, b.name AS branch_name'
            . '  FROM bc_targets t'
            . '  JOIN users u ON u.id = t.agent_id'
            . '  LEFT JOIN branches b ON b.id = u.branch_id'
            . ' WHERE t.id = ? LIMIT 1',
            [$id],
        );
    }

    /** The row for one agent and month, used to spot a duplicate before inserting. */
    public static function findForMonth(int $agentId, string $month): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM bc_targets WHERE agent_id = ? AND target_month = ? LIMIT 1',
            [$agentId, self::normaliseMonth($month)],
        );
    }

    /**
     * Parses a submitted month into the stored form, or null if it is not a month.
     *
     * Accepts both `YYYY-MM` and `YYYY-MM-DD`, because `<input type="month">` sends
     * the former while a browser without month support degrades to a text box where
     * somebody may well type the latter.
     *
     * Returning null rather than defaulting to the current month is the point. An
     * unparseable value silently becoming "this month" would write targets against a
     * period nobody chose, and the warning cron would then measure real agents
     * against them.
     */
    public static function parseMonth(string $month): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', trim($month), $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];
        $monthNumber = (int) $matches[2];

        if ($monthNumber < 1 || $monthNumber > 12 || $year < 2000 || $year > 2100) {
            return null;
        }

        return sprintf('%04d-%02d-01', $year, $monthNumber);
    }

    /**
     * Targets are always stored against the first of the month, because
     * `BcPerformanceService::targetsFor()` looks them up by it. A row stored on the
     * 17th would simply never be found, and the agent would show as unassessed.
     *
     * Only for values already known to be a month - use parseMonth() for input.
     */
    public static function normaliseMonth(string $month): string
    {
        return self::parseMonth($month) ?? date('Y-m-01');
    }

    public static function paginate(
        string $search,
        string $month,
        ?int $branchId,
        ?int $agentId,
        string $sortBy,
        string $sortDir,
        int $page,
        int $perPage,
    ): Paginator {
        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(u.name LIKE ? OR u.employee_code LIKE ? OR u.bc_code LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        if ($month !== '') {
            $where[] = 't.target_month = ?';
            $params[] = self::normaliseMonth($month);
        }

        // Branch scoping is applied here as well as in the controller, so a query
        // string cannot widen a branch manager's view.
        if ($branchId !== null) {
            $where[] = 'u.branch_id = ?';
            $params[] = $branchId;
        }

        if ($agentId !== null) {
            $where[] = 't.agent_id = ?';
            $params[] = $agentId;
        }

        $clause = implode(' AND ', $where);

        $sortable = [
            'target_month' => 't.target_month',
            'agent_name' => 'u.name',
            'employee_code' => 'u.employee_code',
            'branch_name' => 'b.name',
            'updated_at' => 't.updated_at',
        ];

        $orderColumn = $sortable[$sortBy] ?? 't.target_month';
        $direction = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return Paginator::fromQuery(
            'SELECT COUNT(*) FROM bc_targets t JOIN users u ON u.id = t.agent_id'
            . ' LEFT JOIN branches b ON b.id = u.branch_id WHERE ' . $clause,
            'SELECT t.*, u.name AS agent_name, u.employee_code, u.bc_code, b.name AS branch_name,'
            . '       s.name AS set_by_name'
            . '  FROM bc_targets t'
            . '  JOIN users u ON u.id = t.agent_id'
            . '  LEFT JOIN branches b ON b.id = u.branch_id'
            . '  LEFT JOIN users s ON s.id = t.set_by'
            . ' WHERE ' . $clause
            . ' ORDER BY ' . $orderColumn . ' ' . $direction . ', u.name ASC',
            $params,
            $page,
            $perPage,
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::instance()->insert('bc_targets', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::instance()->update('bc_targets', $data, ['id' => $id]);
    }

    /**
     * The months that already have targets, newest first, for the filter dropdown.
     *
     * @return list<string>
     */
    public static function months(?int $branchId = null): array
    {
        $sql = 'SELECT DISTINCT t.target_month FROM bc_targets t JOIN users u ON u.id = t.agent_id';
        $params = [];

        if ($branchId !== null) {
            $sql .= ' WHERE u.branch_id = ?';
            $params[] = $branchId;
        }

        $sql .= ' ORDER BY t.target_month DESC LIMIT 36';

        $months = [];
        foreach (Database::instance()->all($sql, $params) as $row) {
            $months[] = (string) $row['target_month'];
        }

        return $months;
    }

    /**
     * Whether deleting is safe. It is not, once the month has been assessed:
     * `bc_warnings` rows point at a target that existed, and removing it would
     * leave a warning nobody can justify. So targets are corrected, not deleted.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function deletable(int $id): array
    {
        $row = self::find($id);

        if ($row === null) {
            return ['ok' => false, 'reason' => 'That target could not be found.'];
        }

        $warnings = (int) (Database::instance()->scalar(
            'SELECT COUNT(*) FROM bc_warnings'
            . ' WHERE agent_id = ? AND triggered_date >= ? AND triggered_date < DATE_ADD(?, INTERVAL 1 MONTH)',
            [(int) $row['agent_id'], (string) $row['target_month'], (string) $row['target_month']],
        ) ?? 0);

        if ($warnings > 0) {
            return [
                'ok' => false,
                'reason' => $warnings . ' warning(s) were already raised against this month. '
                    . 'Correct the figures instead - deleting the target would leave those warnings unexplainable.',
            ];
        }

        return ['ok' => true, 'reason' => ''];
    }

    public static function delete(int $id): void
    {
        Database::instance()->delete('bc_targets', ['id' => $id]);
    }
}
