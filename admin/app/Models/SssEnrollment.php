<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;

/**
 * Social Security Scheme enrolment recorded for an agent on a given day.
 *
 * One row per agent per day, and it is edited rather than appended to. That is the
 * opposite of the rule for visit reports, deliberately: a visit report is a
 * statement about what happened at a doorstep and must never be rewritten, whereas
 * this is a running count for the day that an agent corrects as they go. Appending
 * would mean summing rows to answer "how many today", and a double-entered form
 * would inflate the figure the scorecard is built from.
 */
final class SssEnrollment
{
    public const SORTABLE = ['enrollment_date', 'agent_name', 'employee_code', 'branch_name', 'total'];

    /**
     * The four schemes, keyed by column.
     *
     * @return array<string,string>
     */
    public static function schemeFields(): array
    {
        return [
            'apy_count' => 'APY',
            'pmjjby_count' => 'PMJJBY',
            'pmsby_count' => 'PMSBY',
            'pmjdy_count' => 'PMJDY',
        ];
    }

    public static function find(int $id): ?array
    {
        return Database::instance()->first(
            'SELECT s.*, u.name AS agent_name, u.employee_code, b.name AS branch_name'
            . '  FROM sss_enrollment s'
            . '  JOIN users u ON u.id = s.agent_id'
            . '  LEFT JOIN branches b ON b.id = s.branch_id'
            . ' WHERE s.id = ? LIMIT 1',
            [$id],
        );
    }

    public static function findForDate(int $agentId, string $date): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM sss_enrollment WHERE agent_id = ? AND enrollment_date = ? LIMIT 1',
            [$agentId, $date],
        );
    }

    public static function paginate(
        string $search,
        string $from,
        string $to,
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

        if ($from !== '') {
            $where[] = 's.enrollment_date >= ?';
            $params[] = $from;
        }

        if ($to !== '') {
            $where[] = 's.enrollment_date <= ?';
            $params[] = $to;
        }

        if ($branchId !== null) {
            $where[] = 's.branch_id = ?';
            $params[] = $branchId;
        }

        if ($agentId !== null) {
            $where[] = 's.agent_id = ?';
            $params[] = $agentId;
        }

        $clause = implode(' AND ', $where);

        $sortable = [
            'enrollment_date' => 's.enrollment_date',
            'agent_name' => 'u.name',
            'employee_code' => 'u.employee_code',
            'branch_name' => 'b.name',
            'total' => 'total',
        ];

        $orderColumn = $sortable[$sortBy] ?? 's.enrollment_date';
        $direction = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return Paginator::fromQuery(
            'SELECT COUNT(*) FROM sss_enrollment s JOIN users u ON u.id = s.agent_id'
            . ' LEFT JOIN branches b ON b.id = s.branch_id WHERE ' . $clause,
            'SELECT s.*, u.name AS agent_name, u.employee_code, u.bc_code, b.name AS branch_name,'
            . '       (s.apy_count + s.pmjjby_count + s.pmsby_count + s.pmjdy_count) AS total'
            . '  FROM sss_enrollment s'
            . '  JOIN users u ON u.id = s.agent_id'
            . '  LEFT JOIN branches b ON b.id = s.branch_id'
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
        return Database::instance()->insert('sss_enrollment', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::instance()->update('sss_enrollment', $data, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::instance()->delete('sss_enrollment', ['id' => $id]);
    }

    /**
     * Totals for a date range, for the summary strip above the list.
     *
     * @return array{days:int, agents:int, apy:int, pmjjby:int, pmsby:int, pmjdy:int, total:int}
     */
    public static function summary(string $from, string $to, ?int $branchId, ?int $agentId): array
    {
        $where = ['1 = 1'];
        $params = [];

        if ($from !== '') {
            $where[] = 'enrollment_date >= ?';
            $params[] = $from;
        }

        if ($to !== '') {
            $where[] = 'enrollment_date <= ?';
            $params[] = $to;
        }

        if ($branchId !== null) {
            $where[] = 'branch_id = ?';
            $params[] = $branchId;
        }

        if ($agentId !== null) {
            $where[] = 'agent_id = ?';
            $params[] = $agentId;
        }

        $row = Database::instance()->first(
            'SELECT COUNT(DISTINCT enrollment_date) AS days,'
            . '       COUNT(DISTINCT agent_id) AS agents,'
            . '       COALESCE(SUM(apy_count), 0) AS apy,'
            . '       COALESCE(SUM(pmjjby_count), 0) AS pmjjby,'
            . '       COALESCE(SUM(pmsby_count), 0) AS pmsby,'
            . '       COALESCE(SUM(pmjdy_count), 0) AS pmjdy'
            . '  FROM sss_enrollment WHERE ' . implode(' AND ', $where),
            $params,
        ) ?? [];

        $apy = (int) ($row['apy'] ?? 0);
        $pmjjby = (int) ($row['pmjjby'] ?? 0);
        $pmsby = (int) ($row['pmsby'] ?? 0);
        $pmjdy = (int) ($row['pmjdy'] ?? 0);

        return [
            'days' => (int) ($row['days'] ?? 0),
            'agents' => (int) ($row['agents'] ?? 0),
            'apy' => $apy,
            'pmjjby' => $pmjjby,
            'pmsby' => $pmsby,
            'pmjdy' => $pmjdy,
            'total' => $apy + $pmjjby + $pmsby + $pmjdy,
        ];
    }
}
