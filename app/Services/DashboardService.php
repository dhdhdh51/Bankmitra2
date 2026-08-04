<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Dashboard aggregates. A super admin sees every branch; a branch manager is
 * scoped to their own branch by the $branchId argument.
 */
final class DashboardService
{
    /**
     * @return array{
     *   cards:array<string,int|float>,
     *   status_breakdown:list<array{status:string,label:string,total:int}>,
     *   top_agents:list<array<string,mixed>>,
     *   branch_rows:list<array<string,mixed>>,
     *   visit_trend:list<array{label:string,date:string,total:int}>,
     *   promise_counts:array<string,int>,
     *   overdue_promises:list<array<string,mixed>>,
     *   recent_visits:list<array<string,mixed>>,
     *   loan_type_split:list<array{label:string,total:int,outstanding:float}>
     * }
     */
    public static function build(?int $branchId): array
    {
        return [
            'cards'            => self::cards($branchId),
            'status_breakdown' => self::statusBreakdown($branchId),
            'top_agents'       => self::topAgents($branchId),
            'branch_rows'      => $branchId === null ? self::branchRows() : [],
            'visit_trend'      => self::visitTrend($branchId),
            'promise_counts'   => self::promiseCounts($branchId),
            'overdue_promises' => self::overduePromises($branchId),
            'recent_visits'    => self::recentVisits($branchId),
            'loan_type_split'  => self::loanTypeSplit($branchId),
        ];
    }

    /** @return array<string,int|float> */
    private static function cards(?int $branchId): array
    {
        [$clause, $params] = self::leadScope($branchId);

        $lead = Database::instance()->first(
            "SELECT COUNT(*) AS total_leads,
                    SUM(CASE WHEN current_status = 'pending'  THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN current_status = 'visited'  THEN 1 ELSE 0 END) AS visited,
                    SUM(CASE WHEN current_status = 'promise'  THEN 1 ELSE 0 END) AS promise_cases,
                    SUM(CASE WHEN current_status = 'followup' THEN 1 ELSE 0 END) AS followup,
                    SUM(CASE WHEN current_status = 'legal'    THEN 1 ELSE 0 END) AS legal,
                    SUM(CASE WHEN current_status = 'closed'   THEN 1 ELSE 0 END) AS closed,
                    SUM(is_npa) AS npa_cases,
                    SUM(CASE WHEN assigned_agent_id IS NULL THEN 1 ELSE 0 END) AS unassigned,
                    COALESCE(SUM(outstanding_amount), 0) AS outstanding,
                    COALESCE(SUM(overdue_amount), 0) AS overdue
               FROM loan_accounts
              WHERE {$clause}",
            $params
        ) ?? [];

        $visitClause = $branchId === null ? '1 = 1' : 'branch_id = ?';
        $visitParams = $branchId === null ? [] : [$branchId];

        $visits = Database::instance()->first(
            "SELECT COUNT(*) AS total_visits,
                    SUM(CASE WHEN visit_date = CURDATE() THEN 1 ELSE 0 END) AS visits_today,
                    SUM(CASE WHEN visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END) AS visits_week,
                    SUM(CASE WHEN visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS visits_month
               FROM visit_reports
              WHERE {$visitClause}",
            $visitParams
        ) ?? [];

        $agentCount = (int) Database::instance()->scalar(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.slug = 'agent' AND u.status = 'active'" . ($branchId === null ? '' : ' AND u.branch_id = ?'),
            $branchId === null ? [] : [$branchId]
        );

        return [
            'total_leads'   => (int) ($lead['total_leads'] ?? 0),
            'pending'       => (int) ($lead['pending'] ?? 0),
            'visited'       => (int) ($lead['visited'] ?? 0),
            'promise_cases' => (int) ($lead['promise_cases'] ?? 0),
            'followup'      => (int) ($lead['followup'] ?? 0),
            'legal'         => (int) ($lead['legal'] ?? 0),
            'closed'        => (int) ($lead['closed'] ?? 0),
            'npa_cases'     => (int) ($lead['npa_cases'] ?? 0),
            'unassigned'    => (int) ($lead['unassigned'] ?? 0),
            'outstanding'   => (float) ($lead['outstanding'] ?? 0),
            'overdue'       => (float) ($lead['overdue'] ?? 0),
            'total_visits'  => (int) ($visits['total_visits'] ?? 0),
            'visits_today'  => (int) ($visits['visits_today'] ?? 0),
            'visits_week'   => (int) ($visits['visits_week'] ?? 0),
            'visits_month'  => (int) ($visits['visits_month'] ?? 0),
            'active_agents' => $agentCount,
        ];
    }

    /** @return list<array{status:string,label:string,total:int}> */
    private static function statusBreakdown(?int $branchId): array
    {
        [$clause, $params] = self::leadScope($branchId);

        $rows = Database::instance()->all(
            "SELECT current_status AS status, COUNT(*) AS total
               FROM loan_accounts WHERE {$clause}
              GROUP BY current_status",
            $params
        );

        $labels = [
            'pending'  => 'Pending',
            'visited'  => 'Visited',
            'promise'  => 'Promise',
            'followup' => 'Follow-up',
            'legal'    => 'Legal',
            'closed'   => 'Closed',
        ];

        $found = [];
        foreach ($rows as $row) {
            $found[(string) $row['status']] = (int) $row['total'];
        }

        $out = [];
        foreach ($labels as $status => $label) {
            $out[] = ['status' => $status, 'label' => $label, 'total' => $found[$status] ?? 0];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function topAgents(?int $branchId): array
    {
        $clause = $branchId === null ? '' : ' AND u.branch_id = ?';
        $params = $branchId === null ? [] : [$branchId];

        return Database::instance()->all(
            "SELECT u.id, u.name, u.employee_code, b.name AS branch_name,
                    (SELECT COUNT(*) FROM visit_reports vr
                      WHERE vr.agent_id = u.id
                        AND vr.visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS visits_month,
                    (SELECT COUNT(*) FROM visit_reports vr
                      WHERE vr.agent_id = u.id AND vr.visit_date = CURDATE()) AS visits_today,
                    (SELECT COUNT(*) FROM loan_accounts la WHERE la.assigned_agent_id = u.id) AS assigned_leads,
                    (SELECT COUNT(*) FROM promises p
                      WHERE p.agent_id = u.id AND p.status = 'kept') AS promises_kept
               FROM users u
               JOIN roles r ON r.id = u.role_id
               LEFT JOIN branches b ON b.id = u.branch_id
              WHERE r.slug = 'agent' AND u.status = 'active' {$clause}
              ORDER BY visits_month DESC, u.name ASC
              LIMIT 8",
            $params
        );
    }

    /** @return list<array<string,mixed>> */
    private static function branchRows(): array
    {
        return Database::instance()->all(
            "SELECT b.id, b.branch_code, b.name,
                    COUNT(la.id) AS total_leads,
                    SUM(CASE WHEN la.current_status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN la.current_status = 'promise' THEN 1 ELSE 0 END) AS promise_cases,
                    SUM(la.is_npa) AS npa_cases,
                    COALESCE(SUM(la.outstanding_amount), 0) AS outstanding,
                    COALESCE(SUM(la.overdue_amount), 0) AS overdue,
                    (SELECT COUNT(*) FROM visit_reports vr
                      WHERE vr.branch_id = b.id
                        AND vr.visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS visits_month
               FROM branches b
               LEFT JOIN loan_accounts la ON la.branch_id = b.id
              WHERE b.status = 'active'
              GROUP BY b.id, b.branch_code, b.name
              ORDER BY outstanding DESC, b.name ASC
              LIMIT 15"
        );
    }

    /**
     * Visits for the last 14 days, zero-filled so the chart has no gaps.
     *
     * @return list<array{label:string,date:string,total:int}>
     */
    private static function visitTrend(?int $branchId): array
    {
        $clause = $branchId === null ? '' : ' AND branch_id = ?';
        $params = $branchId === null ? [] : [$branchId];

        $rows = Database::instance()->all(
            "SELECT visit_date, COUNT(*) AS total
               FROM visit_reports
              WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) {$clause}
              GROUP BY visit_date",
            $params
        );

        $found = [];
        foreach ($rows as $row) {
            $found[(string) $row['visit_date']] = (int) $row['total'];
        }

        $trend = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = [
                'label' => date('d M', strtotime($date)),
                'date'  => $date,
                'total' => $found[$date] ?? 0,
            ];
        }

        return $trend;
    }

    /** @return array<string,int> */
    private static function promiseCounts(?int $branchId): array
    {
        $clause = $branchId === null ? '1 = 1' : 'branch_id = ?';
        $params = $branchId === null ? [] : [$branchId];

        $row = Database::instance()->first(
            "SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'kept'    THEN 1 ELSE 0 END) AS kept,
                    SUM(CASE WHEN status = 'broken'  THEN 1 ELSE 0 END) AS broken,
                    SUM(CASE WHEN status = 'pending' AND promise_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN promise_amount ELSE 0 END), 0) AS pending_value
               FROM promises WHERE {$clause}",
            $params
        ) ?? [];

        return [
            'pending'       => (int) ($row['pending'] ?? 0),
            'kept'          => (int) ($row['kept'] ?? 0),
            'broken'        => (int) ($row['broken'] ?? 0),
            'overdue'       => (int) ($row['overdue'] ?? 0),
            'pending_value' => (int) round((float) ($row['pending_value'] ?? 0)),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function overduePromises(?int $branchId): array
    {
        return \App\Models\Promise::overdue($branchId, 8);
    }

    /** @return list<array<string,mixed>> */
    private static function recentVisits(?int $branchId): array
    {
        $clause = $branchId === null ? '1 = 1' : 'vr.branch_id = ?';
        $params = $branchId === null ? [] : [$branchId];

        return Database::instance()->all(
            "SELECT vr.id, vr.loan_account_id, vr.loan_account_number, vr.customer_name,
                    vr.village, vr.address, vr.visit_date, vr.visit_time, vr.agent_name,
                    vr.customer_met, vr.house_locked, vr.promise_amount, vr.promise_date,
                    vr.created_at
               FROM visit_reports vr
              WHERE {$clause}
              ORDER BY vr.created_at DESC
              LIMIT 10",
            $params
        );
    }

    /** @return list<array{label:string,total:int,outstanding:float}> */
    private static function loanTypeSplit(?int $branchId): array
    {
        [$clause, $params] = self::leadScope($branchId);

        $rows = Database::instance()->all(
            "SELECT COALESCE(NULLIF(loan_type, ''), 'Not specified') AS label,
                    COUNT(*) AS total,
                    COALESCE(SUM(outstanding_amount), 0) AS outstanding
               FROM loan_accounts
              WHERE {$clause}
              GROUP BY COALESCE(NULLIF(loan_type, ''), 'Not specified')
              ORDER BY outstanding DESC
              LIMIT 8",
            $params
        );

        return array_map(
            static fn (array $r): array => [
                'label'       => (string) $r['label'],
                'total'       => (int) $r['total'],
                'outstanding' => (float) $r['outstanding'],
            ],
            $rows
        );
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private static function leadScope(?int $branchId): array
    {
        if ($branchId === null) {
            return ['1 = 1', []];
        }
        return ['branch_id = ?', [$branchId]];
    }

    /**
     * The agent's own dashboard, served to the Android app.
     *
     * @return array<string,mixed>
     */
    public static function forAgent(int $agentId): array
    {
        $db = Database::instance();

        $leads = $db->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN current_status = 'pending'  THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN current_status = 'visited'  THEN 1 ELSE 0 END) AS visited,
                    SUM(CASE WHEN current_status = 'promise'  THEN 1 ELSE 0 END) AS promise_cases,
                    SUM(CASE WHEN current_status = 'followup' THEN 1 ELSE 0 END) AS followup,
                    SUM(CASE WHEN current_status = 'closed'   THEN 1 ELSE 0 END) AS closed,
                    SUM(is_npa) AS npa_cases,
                    COALESCE(SUM(outstanding_amount), 0) AS outstanding,
                    COALESCE(SUM(overdue_amount), 0) AS overdue
               FROM loan_accounts WHERE assigned_agent_id = ?",
            [$agentId]
        ) ?? [];

        $visits = $db->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN visit_date = CURDATE() THEN 1 ELSE 0 END) AS today,
                    SUM(CASE WHEN visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END) AS week,
                    SUM(CASE WHEN visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS month
               FROM visit_reports WHERE agent_id = ?",
            [$agentId]
        ) ?? [];

        $promises = $db->first(
            "SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'kept'    THEN 1 ELSE 0 END) AS kept,
                    SUM(CASE WHEN status = 'broken'  THEN 1 ELSE 0 END) AS broken,
                    SUM(CASE WHEN status = 'pending' AND promise_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
                    SUM(CASE WHEN status = 'pending' AND promise_date = CURDATE() THEN 1 ELSE 0 END) AS due_today
               FROM promises WHERE agent_id = ?",
            [$agentId]
        ) ?? [];

        return [
            'leads' => [
                'total'         => (int) ($leads['total'] ?? 0),
                'pending'       => (int) ($leads['pending'] ?? 0),
                'visited'       => (int) ($leads['visited'] ?? 0),
                'promise_cases' => (int) ($leads['promise_cases'] ?? 0),
                'followup'      => (int) ($leads['followup'] ?? 0),
                'closed'        => (int) ($leads['closed'] ?? 0),
                'npa_cases'     => (int) ($leads['npa_cases'] ?? 0),
                'outstanding'   => round((float) ($leads['outstanding'] ?? 0), 2),
                'overdue'       => round((float) ($leads['overdue'] ?? 0), 2),
            ],
            'visits' => [
                'total' => (int) ($visits['total'] ?? 0),
                'today' => (int) ($visits['today'] ?? 0),
                'week'  => (int) ($visits['week'] ?? 0),
                'month' => (int) ($visits['month'] ?? 0),
            ],
            'promises' => [
                'pending'   => (int) ($promises['pending'] ?? 0),
                'kept'      => (int) ($promises['kept'] ?? 0),
                'broken'    => (int) ($promises['broken'] ?? 0),
                'overdue'   => (int) ($promises['overdue'] ?? 0),
                'due_today' => (int) ($promises['due_today'] ?? 0),
            ],
        ];
    }
}
