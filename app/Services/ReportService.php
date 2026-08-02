<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\LoanAccount;
use App\Services\BcPerformanceService;
use App\Core\Settings;

/**
 * The 8 report types.
 *
 * Every report returns the same shape, so one view template, one Excel exporter
 * and one PDF exporter serve all of them:
 *
 *   [
 *     'type' => 'daily',
 *     'title' => '...', 'subtitle' => '...',
 *     'columns' => [ ['key','label','type','align','width'], ... ],
 *     'rows'    => [ ['key' => value, ...], ... ],
 *     'totals'  => ['key' => value, ...] | null,
 *     'summary' => [ ['label' => '...', 'value' => '...'], ... ],
 *     'landscape' => bool,
 *   ]
 *
 * Column `type` drives formatting: text | number | money | date | percent.
 */
final class ReportService
{
    /** @var array<string,array{label:string,description:string}> */
    public const TYPES = [
        'daily' => [
            'label'       => 'Daily Report',
            'description' => 'Visits and outcomes for a selected date, agent-wise',
        ],
        'weekly' => [
            'label'       => 'Weekly Report',
            'description' => 'Visit and promise summary for a selected week',
        ],
        'monthly' => [
            'label'       => 'Monthly Report',
            'description' => 'Full month performance, branch and agent-wise',
        ],
        'branch' => [
            'label'       => 'Branch-wise Report',
            'description' => 'Leads, visits and recovery status grouped by branch',
        ],
        'village' => [
            'label'       => 'Village-wise Report',
            'description' => 'Coverage and outstanding amounts grouped by village',
        ],
        'loan-type' => [
            'label'       => 'Loan Type-wise Report',
            'description' => 'Portfolio split by loan type',
        ],
        'agent' => [
            'label'       => 'Agent-wise Report',
            'description' => 'Individual agent performance and visit counts',
        ],
        'promise' => [
            'label'       => 'Promise-wise Report',
            'description' => 'Pending, kept and broken promise cases',
        ],
        'bc-daily' => [
            'label'       => 'BC Daily Report',
            'description' => 'Per-agent visits, contacts, PTP and SSS enrolments (APY, PMJJBY, PMSBY, PMJDY) against the day\'s target',
        ],
        // Two rows, not one.
        //
        // KCC and OD-2 both renew against ckcc_renewal_due_date and used to be reviewed
        // as a single list. They are not one queue: the volumes differ by an order of
        // magnitude, the paperwork differs, and a branch reviews them separately - so one
        // undifferentiated list meant forty OD-2 renewals buried inside three hundred KCC
        // ones.
        'kcc-renewal' => [
            'label'       => 'KCC Renewal Worklist',
            'description' => 'Kisan Credit Card accounts by renewal due date, soonest first',
        ],
        'od2-renewal' => [
            'label'       => 'OD-2 Renewal Worklist',
            'description' => 'OD-2 accounts by renewal due date, soonest first',
        ],
    ];

    public static function isValidType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * @param array{
     *   date?:string, date_from?:string, date_to?:string, week?:string, month?:string,
     *   branch_id?:int|null, agent_id?:int|null, status?:string, village?:string,
     *   loan_type?:string, promise_status?:string
     * } $filters
     *
     * @return array{
     *   type:string, title:string, subtitle:string,
     *   columns:list<array{key:string,label:string,type:string,align?:string,width?:float}>,
     *   rows:list<array<string,mixed>>, totals:array<string,mixed>|null,
     *   summary:list<array{label:string,value:string}>, landscape:bool
     * }
     */
    public static function build(string $type, array $filters): array
    {
        return match ($type) {
            'daily'     => self::daily($filters),
            'weekly'    => self::weekly($filters),
            'monthly'   => self::monthly($filters),
            'branch'    => self::branchWise($filters),
            'village'   => self::villageWise($filters),
            'loan-type' => self::loanTypeWise($filters),
            'agent'     => self::agentWise($filters),
            'promise'   => self::promiseWise($filters),
            'bc-daily'  => self::bcDaily($filters),
            // One method, two facilities: the two worklists differ only in which accounts
            // they contain, and duplicating the query would let them drift apart.
            'kcc-renewal' => self::renewalWorklist($filters, 'kcc'),
            'od2-renewal' => self::renewalWorklist($filters, 'od2'),
            default     => throw new \InvalidArgumentException('Unknown report type: ' . $type),
        };
    }

    // =======================================================================
    // 1. DAILY
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function daily(array $filters): array
    {
        $date = self::dateOr($filters['date'] ?? null, date('Y-m-d'));
        [$scope, $params] = self::scope($filters, 'vr');

        $rows = Database::instance()->all(
            "SELECT vr.agent_name,
                    b.name AS branch_name,
                    COUNT(*) AS visits,
                    SUM(vr.customer_met) AS customer_met,
                    SUM(vr.family_member_met) AS family_met,
                    SUM(vr.house_locked) AS house_locked,
                    SUM(vr.phone_switched_off) AS phone_off,
                    SUM(vr.ready_to_pay) AS ready_to_pay,
                    SUM(vr.not_ready) AS not_ready,
                    SUM(CASE WHEN vr.promise_amount > 0 AND vr.promise_date IS NOT NULL THEN 1 ELSE 0 END) AS promises,
                    COALESCE(SUM(vr.promise_amount), 0) AS promise_value,
                    COALESCE(SUM(vr.overdue_amount), 0) AS overdue_touched
               FROM visit_reports vr
               JOIN branches b ON b.id = vr.branch_id
              WHERE vr.visit_date = ? {$scope}
              GROUP BY vr.agent_id, vr.agent_name, b.name
              ORDER BY visits DESC, vr.agent_name ASC",
            array_merge([$date], $params)
        );

        $columns = [
            ['key' => 'agent_name',      'label' => 'Agent',            'type' => 'text',   'width' => 1.6],
            ['key' => 'branch_name',     'label' => 'Branch',           'type' => 'text',   'width' => 1.4],
            ['key' => 'visits',          'label' => 'Visits',           'type' => 'number', 'width' => 0.7],
            ['key' => 'customer_met',    'label' => 'Cust. Met',        'type' => 'number', 'width' => 0.8],
            ['key' => 'family_met',      'label' => 'Family Met',       'type' => 'number', 'width' => 0.8],
            ['key' => 'house_locked',    'label' => 'Locked',           'type' => 'number', 'width' => 0.7],
            ['key' => 'phone_off',       'label' => 'Phone Off',        'type' => 'number', 'width' => 0.8],
            ['key' => 'ready_to_pay',    'label' => 'Ready',            'type' => 'number', 'width' => 0.7],
            ['key' => 'not_ready',       'label' => 'Not Ready',        'type' => 'number', 'width' => 0.8],
            ['key' => 'promises',        'label' => 'Promises',         'type' => 'number', 'width' => 0.8],
            ['key' => 'promise_value',   'label' => 'Promise Value',    'type' => 'money',  'width' => 1.2],
        ];

        $rows = self::castRows($rows, $columns);
        $totals = self::sumTotals($rows, $columns, 'agent_name', 'TOTAL');

        return [
            'type'     => 'daily',
            'title'    => 'Daily Visit Report',
            'subtitle' => self::subtitle($filters, 'For ' . self::humanDate($date)),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Date',           'value' => self::humanDate($date)],
                ['label' => 'Agents active',  'value' => (string) count($rows)],
                ['label' => 'Total visits',   'value' => (string) ($totals['visits'] ?? 0)],
                ['label' => 'Promises made',  'value' => (string) ($totals['promises'] ?? 0)],
            ],
            'landscape' => true,
        ];
    }


    // =======================================================================
    // 9. BC DAILY  (visits, contacts, PTP and the four SSS schemes)
    // =======================================================================

    /**
     * One row per agent for a single day: what they did, and what they were
     * supposed to do.
     *
     * Built on BcPerformanceService::figures(), which reads source records rather
     * than the nightly `bc_daily_achievement` cache. That is the whole reason this
     * report is usable: the cache is written at 23:55, so anything reading it would
     * show every agent on zero for the entire working day - which reads as "nobody
     * did anything", not as "come back tomorrow".
     *
     * Visits are counted, never entered. There is no field anywhere for an agent to
     * type how many visits they made; the number is `COUNT(visit_reports)` for that
     * agent and date, so it cannot be inflated and cannot fall behind. The four
     * scheme columns are the only figures a human types, and they are entered once
     * per day per agent under a unique key.
     *
     * The target columns come from `bc_targets` for the month, pro-rated the same
     * way the warning cron pro-rates them, so this report and the warning an agent
     * receives can never disagree about whether they fell short.
     *
     * @param array<string,mixed> $filters
     */
    private static function bcDaily(array $filters): array
    {
        $date = self::dateOr($filters['date'] ?? null, date('Y-m-d'));

        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== null
            ? (int) $filters['branch_id']
            : null;
        $agentId = isset($filters['agent_id']) && $filters['agent_id'] !== null
            ? (int) $filters['agent_id']
            : null;

        $figures = BcPerformanceService::figures($date, $date, $branchId, $agentId);
        $isWorkingDay = BcPerformanceService::isWorkingDay($date);

        $rows = [];
        foreach ($figures as $figure) {
            $rowAgentId = (int) $figure['agent_id'];
            $target = BcPerformanceService::targetsFor($rowAgentId, $date);

            // The daily visit target is per working day and is never assessed on a
            // Sunday, so showing one here would invite a supervisor to ask about a
            // shortfall the system itself does not count.
            $visitTarget = $target === null || !$isWorkingDay
                ? 0
                : (int) $target['daily_visit_target'];

            $rows[] = [
                'agent_name'   => (string) $figure['agent_name'],
                'employee_code' => (string) $figure['employee_code'],
                'branch_name'  => (string) ($figure['branch_name'] ?? ''),
                'visits'       => (int) $figure['visits'],
                'visit_target' => $visitTarget,
                'contacts'     => (int) $figure['contacts'],
                'ptp'          => (int) $figure['ptp'],
                'apy'          => (int) $figure['apy'],
                'pmjjby'       => (int) $figure['pmjjby'],
                'pmsby'        => (int) $figure['pmsby'],
                'pmjdy'        => (int) $figure['pmjdy'],
                'sss_total'    => (int) $figure['sss_total'],
                'od2_renewal'  => (int) $figure['od2_renewal'],
                'npa_recovery' => (float) $figure['npa_recovery'],
                'reported'     => (int) $figure['report_submitted'] === 1 ? 'Yes' : 'No',
            ];
        }

        // Busiest first. An empty row is still shown: "which agent filed nothing
        // today" is the single most useful thing on this report, and dropping those
        // rows would hide exactly the people it should surface.
        usort($rows, static function (array $a, array $b): int {
            return [$b['visits'], $b['sss_total']] <=> [$a['visits'], $a['sss_total']];
        });

        $columns = [
            ['key' => 'agent_name',    'label' => 'Agent',        'type' => 'text',   'width' => 1.5],
            ['key' => 'employee_code', 'label' => 'Code',         'type' => 'text',   'width' => 0.9],
            ['key' => 'branch_name',   'label' => 'Branch',       'type' => 'text',   'width' => 1.2],
            ['key' => 'visits',        'label' => 'Visits',       'type' => 'number', 'width' => 0.7],
            ['key' => 'visit_target',  'label' => 'Target',       'type' => 'number', 'width' => 0.7],
            ['key' => 'contacts',      'label' => 'Met',          'type' => 'number', 'width' => 0.6],
            ['key' => 'ptp',           'label' => 'PTP',          'type' => 'number', 'width' => 0.6],
            ['key' => 'apy',           'label' => 'APY',          'type' => 'number', 'width' => 0.6],
            ['key' => 'pmjjby',        'label' => 'PMJJBY',       'type' => 'number', 'width' => 0.8],
            ['key' => 'pmsby',         'label' => 'PMSBY',        'type' => 'number', 'width' => 0.8],
            ['key' => 'pmjdy',         'label' => 'PMJDY',        'type' => 'number', 'width' => 0.8],
            ['key' => 'sss_total',     'label' => 'SSS Total',    'type' => 'number', 'width' => 0.9],
            ['key' => 'od2_renewal',   'label' => 'OD-2',         'type' => 'number', 'width' => 0.6],
            ['key' => 'npa_recovery',  'label' => 'Recovery',     'type' => 'money',  'width' => 1.2],
            ['key' => 'reported',      'label' => 'Reported',     'type' => 'text',   'width' => 0.8],
        ];

        $rows = self::castRows($rows, $columns);
        $totals = self::sumTotals($rows, $columns, 'agent_name', 'TOTAL');

        $silent = 0;
        foreach ($rows as $row) {
            if ((string) $row['reported'] === 'No') {
                $silent++;
            }
        }

        return [
            'type'     => 'bc-daily',
            'title'    => 'BC Daily Report',
            'subtitle' => self::subtitle($filters, 'For ' . self::humanDate($date)),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Date',            'value' => self::humanDate($date)
                    . ($isWorkingDay ? '' : ' (Sunday - not assessed)')],
                ['label' => 'Agents',          'value' => (string) count($rows)],
                ['label' => 'Total visits',    'value' => (string) ($totals['visits'] ?? 0)],
                ['label' => 'SSS enrolments',  'value' => (string) ($totals['sss_total'] ?? 0)],
                ['label' => 'Filed nothing',   'value' => (string) $silent],
            ],
            'landscape' => true,
        ];
    }

    // =======================================================================
    // 2. WEEKLY
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function weekly(array $filters): array
    {
        // Accepts an <input type="week"> value (2026-W31) or an explicit range.
        [$from, $to] = self::weekRange($filters);
        [$scope, $params] = self::scope($filters, 'vr');

        $rows = Database::instance()->all(
            "SELECT vr.visit_date,
                    DAYNAME(vr.visit_date) AS day_name,
                    COUNT(*) AS visits,
                    COUNT(DISTINCT vr.agent_id) AS agents,
                    COUNT(DISTINCT vr.loan_account_id) AS accounts,
                    SUM(vr.customer_met) AS customer_met,
                    SUM(CASE WHEN vr.promise_amount > 0 AND vr.promise_date IS NOT NULL THEN 1 ELSE 0 END) AS promises,
                    COALESCE(SUM(vr.promise_amount), 0) AS promise_value
               FROM visit_reports vr
              WHERE vr.visit_date BETWEEN ? AND ? {$scope}
              GROUP BY vr.visit_date, DAYNAME(vr.visit_date)
              ORDER BY vr.visit_date ASC",
            array_merge([$from, $to], $params)
        );

        $columns = [
            ['key' => 'visit_date',    'label' => 'Date',          'type' => 'date',   'width' => 1.1],
            ['key' => 'day_name',      'label' => 'Day',           'type' => 'text',   'width' => 1.0],
            ['key' => 'visits',        'label' => 'Visits',        'type' => 'number', 'width' => 0.8],
            ['key' => 'agents',        'label' => 'Agents',        'type' => 'number', 'width' => 0.8],
            ['key' => 'accounts',      'label' => 'Accounts',      'type' => 'number', 'width' => 0.9],
            ['key' => 'customer_met',  'label' => 'Customer Met',  'type' => 'number', 'width' => 1.0],
            ['key' => 'promises',      'label' => 'Promises',      'type' => 'number', 'width' => 0.9],
            ['key' => 'promise_value', 'label' => 'Promise Value', 'type' => 'money',  'width' => 1.3],
        ];

        $rows = self::castRows($rows, $columns);
        $totals = self::sumTotals($rows, $columns, 'visit_date', 'TOTAL');
        if ($totals !== null) {
            // Distinct counts cannot be summed across days without double counting.
            $totals['agents'] = '';
            $totals['accounts'] = '';
        }

        $promiseSummary = self::promiseSummaryForRange($from, $to, $filters);

        return [
            'type'     => 'weekly',
            'title'    => 'Weekly Visit & Promise Summary',
            'subtitle' => self::subtitle($filters, self::humanDate($from) . ' to ' . self::humanDate($to)),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Week',            'value' => self::humanDate($from) . ' – ' . self::humanDate($to)],
                ['label' => 'Total visits',    'value' => (string) ($totals['visits'] ?? 0)],
                ['label' => 'Promises created', 'value' => (string) $promiseSummary['created']],
                ['label' => 'Promises kept',   'value' => (string) $promiseSummary['kept']],
                ['label' => 'Promises broken', 'value' => (string) $promiseSummary['broken']],
            ],
            'landscape' => false,
        ];
    }

    // =======================================================================
    // 3. MONTHLY
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function monthly(array $filters): array
    {
        $month = trim((string) ($filters['month'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = date('Y-m');
        }
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));

        [$scope, $params] = self::scope($filters, 'vr');

        $rows = Database::instance()->all(
            "SELECT b.name AS branch_name,
                    vr.agent_name,
                    COUNT(*) AS visits,
                    COUNT(DISTINCT vr.loan_account_id) AS accounts_touched,
                    COUNT(DISTINCT vr.visit_date) AS active_days,
                    SUM(vr.customer_met) AS customer_met,
                    SUM(vr.house_locked) AS house_locked,
                    SUM(CASE WHEN vr.promise_amount > 0 AND vr.promise_date IS NOT NULL THEN 1 ELSE 0 END) AS promises,
                    COALESCE(SUM(vr.promise_amount), 0) AS promise_value,
                    ROUND(COUNT(*) / GREATEST(COUNT(DISTINCT vr.visit_date), 1), 1) AS visits_per_day
               FROM visit_reports vr
               JOIN branches b ON b.id = vr.branch_id
              WHERE vr.visit_date BETWEEN ? AND ? {$scope}
              GROUP BY vr.branch_id, b.name, vr.agent_id, vr.agent_name
              ORDER BY b.name ASC, visits DESC",
            array_merge([$from, $to], $params)
        );

        $columns = [
            ['key' => 'branch_name',      'label' => 'Branch',         'type' => 'text',   'width' => 1.4],
            ['key' => 'agent_name',       'label' => 'Agent',          'type' => 'text',   'width' => 1.5],
            ['key' => 'visits',           'label' => 'Visits',         'type' => 'number', 'width' => 0.8],
            ['key' => 'accounts_touched', 'label' => 'Accounts',       'type' => 'number', 'width' => 0.9],
            ['key' => 'active_days',      'label' => 'Active Days',    'type' => 'number', 'width' => 1.0],
            ['key' => 'visits_per_day',   'label' => 'Visits/Day',     'type' => 'number', 'width' => 1.0],
            ['key' => 'customer_met',     'label' => 'Customer Met',   'type' => 'number', 'width' => 1.0],
            ['key' => 'house_locked',     'label' => 'Locked',         'type' => 'number', 'width' => 0.8],
            ['key' => 'promises',         'label' => 'Promises',       'type' => 'number', 'width' => 0.9],
            ['key' => 'promise_value',    'label' => 'Promise Value',  'type' => 'money',  'width' => 1.3],
        ];

        $rows = self::castRows($rows, $columns);
        $totals = self::sumTotals($rows, $columns, 'branch_name', 'TOTAL');
        if ($totals !== null) {
            $totals['agent_name'] = '';
            $totals['visits_per_day'] = '';
            $totals['active_days'] = '';
        }

        return [
            'type'     => 'monthly',
            'title'    => 'Monthly Performance Report',
            'subtitle' => self::subtitle($filters, date('F Y', strtotime($from))),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Month',        'value' => date('F Y', strtotime($from))],
                ['label' => 'Agents',       'value' => (string) count($rows)],
                ['label' => 'Total visits', 'value' => (string) ($totals['visits'] ?? 0)],
                ['label' => 'Promise value', 'value' => number_format((float) ($totals['promise_value'] ?? 0), 2)],
            ],
            'landscape' => true,
        ];
    }

    // =======================================================================
    // 4. BRANCH-WISE
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function branchWise(array $filters): array
    {
        [$scope, $params] = self::leadScope($filters, 'la');
        [$dateClause, $dateParams] = self::visitDateSubClause($filters);

        $rows = Database::instance()->all(
            "SELECT b.branch_code,
                    b.name AS branch_name,
                    b.district,
                    COUNT(la.id) AS total_leads,
                    SUM(CASE WHEN la.current_status = 'pending'  THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN la.current_status = 'visited'  THEN 1 ELSE 0 END) AS visited,
                    SUM(CASE WHEN la.current_status = 'promise'  THEN 1 ELSE 0 END) AS promise,
                    SUM(CASE WHEN la.current_status = 'followup' THEN 1 ELSE 0 END) AS followup,
                    SUM(CASE WHEN la.current_status = 'legal'    THEN 1 ELSE 0 END) AS legal,
                    SUM(CASE WHEN la.current_status = 'closed'   THEN 1 ELSE 0 END) AS closed,
                    SUM(la.is_npa) AS npa_cases,
                    COALESCE(SUM(la.outstanding_amount), 0) AS outstanding,
                    COALESCE(SUM(la.overdue_amount), 0) AS overdue,
                    (SELECT COUNT(*) FROM visit_reports vr
                      WHERE vr.branch_id = b.id {$dateClause}) AS visits
               FROM branches b
               LEFT JOIN loan_accounts la ON la.branch_id = b.id
              WHERE 1 = 1 {$scope}
              GROUP BY b.id, b.branch_code, b.name, b.district
              HAVING total_leads > 0 OR visits > 0
              ORDER BY outstanding DESC, b.name ASC",
            array_merge($dateParams, $params)
        );

        $columns = [
            ['key' => 'branch_code',  'label' => 'Code',        'type' => 'text',   'width' => 0.8],
            ['key' => 'branch_name',  'label' => 'Branch',      'type' => 'text',   'width' => 1.6],
            ['key' => 'total_leads',  'label' => 'Leads',       'type' => 'number', 'width' => 0.8],
            ['key' => 'pending',      'label' => 'Pending',     'type' => 'number', 'width' => 0.8],
            ['key' => 'visited',      'label' => 'Visited',     'type' => 'number', 'width' => 0.8],
            ['key' => 'promise',      'label' => 'Promise',     'type' => 'number', 'width' => 0.8],
            ['key' => 'followup',     'label' => 'Follow-up',   'type' => 'number', 'width' => 0.9],
            ['key' => 'legal',        'label' => 'Legal',       'type' => 'number', 'width' => 0.7],
            ['key' => 'closed',       'label' => 'Closed',      'type' => 'number', 'width' => 0.8],
            ['key' => 'npa_cases',    'label' => 'NPA',         'type' => 'number', 'width' => 0.7],
            ['key' => 'visits',       'label' => 'Visits',      'type' => 'number', 'width' => 0.8],
            ['key' => 'outstanding',  'label' => 'Outstanding', 'type' => 'money',  'width' => 1.3],
            ['key' => 'overdue',      'label' => 'Overdue',     'type' => 'money',  'width' => 1.2],
        ];

        $rows = self::castRows($rows, $columns);
        $totals = self::sumTotals($rows, $columns, 'branch_code', 'TOTAL');
        if ($totals !== null) {
            $totals['branch_name'] = '';
        }

        return [
            'type'     => 'branch',
            'title'    => 'Branch-wise Recovery Report',
            'subtitle' => self::subtitle($filters, self::rangeLabel($filters)),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Branches',        'value' => (string) count($rows)],
                ['label' => 'Total leads',     'value' => (string) ($totals['total_leads'] ?? 0)],
                ['label' => 'Outstanding',     'value' => number_format((float) ($totals['outstanding'] ?? 0), 2)],
                ['label' => 'Overdue',         'value' => number_format((float) ($totals['overdue'] ?? 0), 2)],
            ],
            'landscape' => true,
        ];
    }

    // =======================================================================
    // 5. VILLAGE-WISE
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function villageWise(array $filters): array
    {
        [$scope, $params] = self::leadScope($filters, 'la');

        $rows = Database::instance()->all(
            "SELECT COALESCE(NULLIF(c.village, ''), 'Not specified') AS village,
                    b.name AS branch_name,
                    COUNT(la.id) AS total_leads,
                    COUNT(DISTINCT c.id) AS borrowers,
                    SUM(CASE WHEN la.current_status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN la.visit_count > 0 THEN 1 ELSE 0 END) AS covered,
                    SUM(CASE WHEN la.visit_count = 0 THEN 1 ELSE 0 END) AS not_covered,
                    SUM(la.is_npa) AS npa_cases,
                    COALESCE(SUM(la.outstanding_amount), 0) AS outstanding,
                    COALESCE(SUM(la.overdue_amount), 0) AS overdue,
                    ROUND(100 * SUM(CASE WHEN la.visit_count > 0 THEN 1 ELSE 0 END) / GREATEST(COUNT(la.id), 1), 1) AS coverage_pct
               FROM loan_accounts la
               JOIN customers c ON c.id = la.customer_id
               JOIN branches b ON b.id = la.branch_id
              WHERE 1 = 1 {$scope}
              GROUP BY COALESCE(NULLIF(c.village, ''), 'Not specified'), la.branch_id, b.name
              ORDER BY outstanding DESC, village ASC",
            $params
        );

        $columns = [
            ['key' => 'village',      'label' => 'Village',      'type' => 'text',    'width' => 1.5],
            ['key' => 'branch_name',  'label' => 'Branch',       'type' => 'text',    'width' => 1.4],
            ['key' => 'borrowers',    'label' => 'Borrowers',    'type' => 'number',  'width' => 0.9],
            ['key' => 'total_leads',  'label' => 'Accounts',     'type' => 'number',  'width' => 0.9],
            ['key' => 'covered',      'label' => 'Visited',      'type' => 'number',  'width' => 0.8],
            ['key' => 'not_covered',  'label' => 'Not Visited',  'type' => 'number',  'width' => 1.0],
            ['key' => 'coverage_pct', 'label' => 'Coverage %',   'type' => 'percent', 'width' => 1.0],
            ['key' => 'npa_cases',    'label' => 'NPA',          'type' => 'number',  'width' => 0.7],
            ['key' => 'outstanding',  'label' => 'Outstanding',  'type' => 'money',   'width' => 1.3],
            ['key' => 'overdue',      'label' => 'Overdue',      'type' => 'money',   'width' => 1.2],
        ];

        $rows = self::castRows($rows, $columns);
        $totals = self::sumTotals($rows, $columns, 'village', 'TOTAL');
        if ($totals !== null) {
            $totals['branch_name'] = '';
            // Recompute the aggregate coverage rather than summing percentages.
            $totalLeads = (float) ($totals['total_leads'] ?? 0);
            $totals['coverage_pct'] = $totalLeads > 0
                ? round(100 * (float) ($totals['covered'] ?? 0) / $totalLeads, 1)
                : 0.0;
        }

        return [
            'type'     => 'village',
            'title'    => 'Village-wise Coverage Report',
            'subtitle' => self::subtitle($filters, self::rangeLabel($filters)),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Villages',    'value' => (string) count($rows)],
                ['label' => 'Accounts',    'value' => (string) ($totals['total_leads'] ?? 0)],
                ['label' => 'Coverage',    'value' => (string) ($totals['coverage_pct'] ?? 0) . '%'],
                ['label' => 'Outstanding', 'value' => number_format((float) ($totals['outstanding'] ?? 0), 2)],
            ],
            'landscape' => true,
        ];
    }

    // =======================================================================
    // 6. LOAN TYPE-WISE
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function loanTypeWise(array $filters): array
    {
        [$scope, $params] = self::leadScope($filters, 'la');

        $rows = Database::instance()->all(
            "SELECT COALESCE(NULLIF(la.loan_type, ''), 'Not specified') AS loan_type,
                    COUNT(la.id) AS total_leads,
                    SUM(la.is_npa) AS npa_cases,
                    SUM(CASE WHEN la.current_status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN la.current_status = 'promise' THEN 1 ELSE 0 END) AS promise,
                    SUM(CASE WHEN la.current_status = 'closed'  THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN la.visit_count > 0 THEN 1 ELSE 0 END) AS visited_accounts,
                    COALESCE(SUM(la.outstanding_amount), 0) AS outstanding,
                    COALESCE(SUM(la.overdue_amount), 0) AS overdue,
                    COALESCE(ROUND(AVG(la.outstanding_amount), 2), 0) AS avg_outstanding
               FROM loan_accounts la
               JOIN customers c ON c.id = la.customer_id
              WHERE 1 = 1 {$scope}
              GROUP BY COALESCE(NULLIF(la.loan_type, ''), 'Not specified')
              ORDER BY outstanding DESC",
            $params
        );

        $grandOutstanding = 0.0;
        foreach ($rows as $row) {
            $grandOutstanding += (float) $row['outstanding'];
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['portfolio_pct'] = $grandOutstanding > 0
                ? round(100 * (float) $row['outstanding'] / $grandOutstanding, 1)
                : 0.0;
        }

        $columns = [
            ['key' => 'loan_type',        'label' => 'Loan Type',       'type' => 'text',    'width' => 1.6],
            ['key' => 'total_leads',      'label' => 'Accounts',        'type' => 'number',  'width' => 0.9],
            ['key' => 'visited_accounts', 'label' => 'Visited',         'type' => 'number',  'width' => 0.9],
            ['key' => 'pending',          'label' => 'Pending',         'type' => 'number',  'width' => 0.9],
            ['key' => 'promise',          'label' => 'Promise',         'type' => 'number',  'width' => 0.9],
            ['key' => 'closed',           'label' => 'Closed',          'type' => 'number',  'width' => 0.8],
            ['key' => 'npa_cases',        'label' => 'NPA',             'type' => 'number',  'width' => 0.7],
            ['key' => 'outstanding',      'label' => 'Outstanding',     'type' => 'money',   'width' => 1.3],
            ['key' => 'overdue',          'label' => 'Overdue',         'type' => 'money',   'width' => 1.2],
            ['key' => 'avg_outstanding',  'label' => 'Avg Outstanding', 'type' => 'money',   'width' => 1.3],
            ['key' => 'portfolio_pct',    'label' => 'Portfolio %',     'type' => 'percent', 'width' => 1.0],
        ];

        $rows = self::castRows($rows, $columns);
        $totals = self::sumTotals($rows, $columns, 'loan_type', 'TOTAL');
        if ($totals !== null) {
            $totals['avg_outstanding'] = (int) ($totals['total_leads'] ?? 0) > 0
                ? round((float) ($totals['outstanding'] ?? 0) / (int) $totals['total_leads'], 2)
                : 0.0;
            $totals['portfolio_pct'] = $grandOutstanding > 0 ? 100.0 : 0.0;
        }

        return [
            'type'     => 'loan-type',
            'title'    => 'Loan Type-wise Portfolio Report',
            'subtitle' => self::subtitle($filters, self::rangeLabel($filters)),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Loan types',  'value' => (string) count($rows)],
                ['label' => 'Accounts',    'value' => (string) ($totals['total_leads'] ?? 0)],
                ['label' => 'Outstanding', 'value' => number_format($grandOutstanding, 2)],
                ['label' => 'NPA cases',   'value' => (string) ($totals['npa_cases'] ?? 0)],
            ],
            'landscape' => true,
        ];
    }

    // =======================================================================
    // 7. AGENT-WISE
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function agentWise(array $filters): array
    {
        [$dateClause, $dateParams] = self::visitDateSubClause($filters);

        $where = ["r.slug = 'agent'"];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = 'u.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['agent_id'])) {
            $where[] = 'u.id = ?';
            $params[] = (int) $filters['agent_id'];
        }
        $clause = implode(' AND ', $where);

        // Correlated subqueries keep each metric independent of the others, which
        // avoids the row multiplication that a multi-join would cause here.
        $rows = Database::instance()->all(
            "SELECT u.employee_code,
                    u.name AS agent_name,
                    u.bc_code,
                    b.name AS branch_name,
                    (SELECT COUNT(*) FROM loan_accounts la WHERE la.assigned_agent_id = u.id) AS assigned_leads,
                    (SELECT COUNT(*) FROM loan_accounts la WHERE la.assigned_agent_id = u.id AND la.current_status = 'pending') AS pending,
                    (SELECT COUNT(*) FROM loan_accounts la WHERE la.assigned_agent_id = u.id AND la.current_status = 'closed') AS closed,
                    (SELECT COUNT(*) FROM visit_reports vr WHERE vr.agent_id = u.id {$dateClause}) AS visits,
                    (SELECT COUNT(DISTINCT vr.loan_account_id) FROM visit_reports vr WHERE vr.agent_id = u.id {$dateClause}) AS accounts_visited,
                    (SELECT COUNT(DISTINCT vr.visit_date) FROM visit_reports vr WHERE vr.agent_id = u.id {$dateClause}) AS active_days,
                    (SELECT COUNT(*) FROM promises p WHERE p.agent_id = u.id) AS promises,
                    (SELECT COUNT(*) FROM promises p WHERE p.agent_id = u.id AND p.status = 'kept') AS promises_kept,
                    (SELECT COUNT(*) FROM promises p WHERE p.agent_id = u.id AND p.status = 'broken') AS promises_broken,
                    (SELECT COALESCE(SUM(la.outstanding_amount), 0) FROM loan_accounts la WHERE la.assigned_agent_id = u.id) AS outstanding
               FROM users u
               JOIN roles r ON r.id = u.role_id
               LEFT JOIN branches b ON b.id = u.branch_id
              WHERE {$clause}
              ORDER BY visits DESC, u.name ASC",
            array_merge($dateParams, $dateParams, $dateParams, $params)
        );

        foreach ($rows as $index => $row) {
            $assigned = (int) $row['assigned_leads'];
            $rows[$index]['coverage_pct'] = $assigned > 0
                ? round(100 * (int) $row['accounts_visited'] / $assigned, 1)
                : 0.0;
            $promises = (int) $row['promises'];
            $rows[$index]['keep_rate_pct'] = $promises > 0
                ? round(100 * (int) $row['promises_kept'] / $promises, 1)
                : 0.0;
        }

        $columns = [
            ['key' => 'employee_code',    'label' => 'Emp Code',     'type' => 'text',    'width' => 1.0],
            ['key' => 'agent_name',       'label' => 'Agent',        'type' => 'text',    'width' => 1.5],
            ['key' => 'branch_name',      'label' => 'Branch',       'type' => 'text',    'width' => 1.3],
            ['key' => 'assigned_leads',   'label' => 'Assigned',     'type' => 'number',  'width' => 0.9],
            ['key' => 'visits',           'label' => 'Visits',       'type' => 'number',  'width' => 0.8],
            ['key' => 'accounts_visited', 'label' => 'Accounts',     'type' => 'number',  'width' => 0.9],
            ['key' => 'active_days',      'label' => 'Days',         'type' => 'number',  'width' => 0.7],
            ['key' => 'coverage_pct',     'label' => 'Coverage %',   'type' => 'percent', 'width' => 1.0],
            ['key' => 'promises',         'label' => 'Promises',     'type' => 'number',  'width' => 0.9],
            ['key' => 'promises_kept',    'label' => 'Kept',         'type' => 'number',  'width' => 0.7],
            ['key' => 'promises_broken',  'label' => 'Broken',       'type' => 'number',  'width' => 0.8],
            ['key' => 'keep_rate_pct',    'label' => 'Keep %',       'type' => 'percent', 'width' => 0.9],
            ['key' => 'pending',          'label' => 'Pending',      'type' => 'number',  'width' => 0.9],
            ['key' => 'outstanding',      'label' => 'Outstanding',  'type' => 'money',   'width' => 1.3],
        ];

        $rows = self::castRows($rows, $columns);
        $totals = self::sumTotals($rows, $columns, 'employee_code', 'TOTAL');
        if ($totals !== null) {
            $totals['agent_name'] = '';
            $totals['branch_name'] = '';
            $totals['active_days'] = '';
            $assigned = (float) ($totals['assigned_leads'] ?? 0);
            $totals['coverage_pct'] = $assigned > 0
                ? round(100 * (float) ($totals['accounts_visited'] ?? 0) / $assigned, 1)
                : 0.0;
            $promises = (float) ($totals['promises'] ?? 0);
            $totals['keep_rate_pct'] = $promises > 0
                ? round(100 * (float) ($totals['promises_kept'] ?? 0) / $promises, 1)
                : 0.0;
        }

        return [
            'type'     => 'agent',
            'title'    => 'Agent-wise Performance Report',
            'subtitle' => self::subtitle($filters, self::rangeLabel($filters)),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Agents',       'value' => (string) count($rows)],
                ['label' => 'Total visits', 'value' => (string) ($totals['visits'] ?? 0)],
                ['label' => 'Assigned leads', 'value' => (string) ($totals['assigned_leads'] ?? 0)],
                ['label' => 'Promise keep rate', 'value' => (string) ($totals['keep_rate_pct'] ?? 0) . '%'],
            ],
            'landscape' => true,
        ];
    }

    // =======================================================================
    // 8. PROMISE-WISE
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function promiseWise(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = 'p.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['agent_id'])) {
            $where[] = 'p.agent_id = ?';
            $params[] = (int) $filters['agent_id'];
        }

        $promiseStatus = trim((string) ($filters['promise_status'] ?? ''));
        if ($promiseStatus !== '' && in_array($promiseStatus, ['pending', 'kept', 'broken', 'cancelled'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $promiseStatus;
        }

        $from = self::dateOrNull($filters['date_from'] ?? null);
        if ($from !== null) {
            $where[] = 'p.promise_date >= ?';
            $params[] = $from;
        }
        $to = self::dateOrNull($filters['date_to'] ?? null);
        if ($to !== null) {
            $where[] = 'p.promise_date <= ?';
            $params[] = $to;
        }

        $clause = implode(' AND ', $where);

        $rows = Database::instance()->all(
            "SELECT la.loan_account_number,
                    c.name AS customer_name,
                    c.village,
                    c.mobile_masked,
                    b.name AS branch_name,
                    ag.name AS agent_name,
                    p.promise_amount,
                    p.promise_date,
                    p.status,
                    la.outstanding_amount,
                    la.overdue_amount,
                    CASE
                      WHEN p.status = 'pending' AND p.promise_date < CURDATE()
                        THEN DATEDIFF(CURDATE(), p.promise_date)
                      ELSE 0
                    END AS days_overdue
               FROM promises p
               JOIN loan_accounts la ON la.id = p.loan_account_id
               JOIN customers c ON c.id = p.customer_id
               JOIN branches b ON b.id = p.branch_id
               JOIN users ag ON ag.id = p.agent_id
              WHERE {$clause}
              ORDER BY FIELD(p.status, 'pending', 'broken', 'kept', 'cancelled'), p.promise_date ASC
              LIMIT 20000",
            $params
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['status_label'] = ucfirst((string) $row['status']);
        }

        $columns = [
            ['key' => 'loan_account_number', 'label' => 'Loan Account',   'type' => 'text',   'width' => 1.4],
            ['key' => 'customer_name',       'label' => 'Customer',       'type' => 'text',   'width' => 1.5],
            ['key' => 'village',             'label' => 'Village',        'type' => 'text',   'width' => 1.1],
            ['key' => 'branch_name',         'label' => 'Branch',         'type' => 'text',   'width' => 1.2],
            ['key' => 'agent_name',          'label' => 'Agent',          'type' => 'text',   'width' => 1.3],
            ['key' => 'promise_amount',      'label' => 'Promise Amt',    'type' => 'money',  'width' => 1.1],
            ['key' => 'promise_date',        'label' => 'Promise Date',   'type' => 'date',   'width' => 1.1],
            ['key' => 'status_label',        'label' => 'Status',         'type' => 'text',   'width' => 0.9],
            ['key' => 'days_overdue',        'label' => 'Days Overdue',   'type' => 'number', 'width' => 1.0],
            ['key' => 'outstanding_amount',  'label' => 'Outstanding',    'type' => 'money',  'width' => 1.2],
            ['key' => 'overdue_amount',      'label' => 'Overdue',        'type' => 'money',  'width' => 1.1],
        ];

        $rows = self::castRows($rows, $columns);

        $totals = self::sumTotals($rows, $columns, 'loan_account_number', 'TOTAL');
        if ($totals !== null) {
            foreach (['customer_name', 'village', 'branch_name', 'agent_name', 'status_label', 'promise_date', 'days_overdue'] as $key) {
                $totals[$key] = '';
            }
        }

        $counts = ['pending' => 0, 'kept' => 0, 'broken' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $key = strtolower((string) $row['status_label']);
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        return [
            'type'     => 'promise',
            'title'    => 'Promise-wise Report',
            'subtitle' => self::subtitle($filters, self::rangeLabel($filters, 'promise date')),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            'summary'  => [
                ['label' => 'Total promises', 'value' => (string) count($rows)],
                ['label' => 'Pending',        'value' => (string) $counts['pending']],
                ['label' => 'Kept',           'value' => (string) $counts['kept']],
                ['label' => 'Broken',         'value' => (string) $counts['broken']],
                ['label' => 'Promised value', 'value' => number_format((float) ($totals['promise_amount'] ?? 0), 2)],
            ],
            'landscape' => true,
        ];
    }

    // =======================================================================
    // Shared filter helpers
    // =======================================================================

    /**
     * Branch/agent scope for visit_reports queries.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private static function scope(array $filters, string $alias): array
    {
        $clause = '';
        $params = [];

        if (!empty($filters['branch_id'])) {
            $clause .= " AND {$alias}.branch_id = ?";
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['agent_id'])) {
            $clause .= " AND {$alias}.agent_id = ?";
            $params[] = (int) $filters['agent_id'];
        }
        if (!empty($filters['village'])) {
            $clause .= " AND {$alias}.village = ?";
            $params[] = (string) $filters['village'];
        }
        if (!empty($filters['loan_type'])) {
            $clause .= " AND {$alias}.loan_type = ?";
            $params[] = (string) $filters['loan_type'];
        }

        return [$clause, $params];
    }

    /**
     * Branch/agent/status scope for loan_accounts queries.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private static function leadScope(array $filters, string $alias): array
    {
        $clause = '';
        $params = [];

        if (!empty($filters['branch_id'])) {
            $clause .= " AND {$alias}.branch_id = ?";
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['agent_id'])) {
            $clause .= " AND {$alias}.assigned_agent_id = ?";
            $params[] = (int) $filters['agent_id'];
        }
        if (!empty($filters['status'])) {
            $clause .= " AND {$alias}.current_status = ?";
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['loan_type'])) {
            $clause .= " AND {$alias}.loan_type = ?";
            $params[] = (string) $filters['loan_type'];
        }
        if (!empty($filters['village'])) {
            $clause .= ' AND c.village = ?';
            $params[] = (string) $filters['village'];
        }
        if (!empty($filters['npa_only'])) {
            $clause .= " AND {$alias}.is_npa = 1";
        }

        return [$clause, $params];
    }

    /**
     * Date filter fragment for correlated visit_reports subqueries.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private static function visitDateSubClause(array $filters): array
    {
        $clause = '';
        $params = [];

        $from = self::dateOrNull($filters['date_from'] ?? null);
        if ($from !== null) {
            $clause .= ' AND vr.visit_date >= ?';
            $params[] = $from;
        }
        $to = self::dateOrNull($filters['date_to'] ?? null);
        if ($to !== null) {
            $clause .= ' AND vr.visit_date <= ?';
            $params[] = $to;
        }

        return [$clause, $params];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:string}
     */
    private static function weekRange(array $filters): array
    {
        $week = trim((string) ($filters['week'] ?? ''));

        if (preg_match('/^(\d{4})-W(\d{1,2})$/', $week, $m) === 1) {
            $date = new \DateTimeImmutable();
            $date = $date->setISODate((int) $m[1], (int) $m[2]);
            return [$date->format('Y-m-d'), $date->modify('+6 days')->format('Y-m-d')];
        }

        $from = self::dateOrNull($filters['date_from'] ?? null);
        $to = self::dateOrNull($filters['date_to'] ?? null);

        if ($from !== null && $to !== null) {
            return [$from, $to];
        }

        // Default: the current ISO week (Monday to Sunday).
        $monday = new \DateTimeImmutable('monday this week');
        return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{created:int,kept:int,broken:int}
     */
    private static function promiseSummaryForRange(string $from, string $to, array $filters): array
    {
        $clause = '';
        $params = [$from, $to];

        if (!empty($filters['branch_id'])) {
            $clause .= ' AND p.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['agent_id'])) {
            $clause .= ' AND p.agent_id = ?';
            $params[] = (int) $filters['agent_id'];
        }

        $row = Database::instance()->first(
            "SELECT COUNT(*) AS created,
                    SUM(CASE WHEN p.status = 'kept'   THEN 1 ELSE 0 END) AS kept,
                    SUM(CASE WHEN p.status = 'broken' THEN 1 ELSE 0 END) AS broken
               FROM promises p
              WHERE DATE(p.created_at) BETWEEN ? AND ? {$clause}",
            $params
        );

        return [
            'created' => (int) ($row['created'] ?? 0),
            'kept'    => (int) ($row['kept'] ?? 0),
            'broken'  => (int) ($row['broken'] ?? 0),
        ];
    }

    // =======================================================================
    // Row shaping
    // =======================================================================

    /**
     * Casts DB strings to real ints/floats so Excel receives numbers (not text)
     * and the PDF right-aligns them.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $columns
     * @return list<array<string,mixed>>
     */
    private static function castRows(array $rows, array $columns): array
    {
        foreach ($rows as $index => $row) {
            foreach ($columns as $column) {
                $key = (string) $column['key'];
                if (!array_key_exists($key, $row)) {
                    continue;
                }
                $value = $row[$key];
                if ($value === null) {
                    $rows[$index][$key] = match ((string) $column['type']) {
                        'number', 'money', 'percent' => 0,
                        default => null,
                    };
                    continue;
                }

                $rows[$index][$key] = match ((string) $column['type']) {
                    'number'  => str_contains((string) $value, '.') ? (float) $value : (int) $value,
                    'money', 'percent' => (float) $value,
                    default   => (string) $value,
                };
            }
        }

        return $rows;
    }

    /**
     * Sums numeric columns into a totals row.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $columns
     * @return array<string,mixed>|null
     */
    private static function sumTotals(array $rows, array $columns, string $labelKey, string $label): ?array
    {
        if ($rows === []) {
            return null;
        }

        $totals = [];
        foreach ($columns as $column) {
            $key = (string) $column['key'];
            $type = (string) $column['type'];

            if ($key === $labelKey) {
                $totals[$key] = $label;
                continue;
            }

            if (in_array($type, ['number', 'money'], true)) {
                $sum = 0.0;
                foreach ($rows as $row) {
                    $sum += (float) ($row[$key] ?? 0);
                }
                // Keep integers as integers so Excel shows "12" not "12.00".
                $totals[$key] = $type === 'number' && $sum === floor($sum) ? (int) $sum : $sum;
                continue;
            }

            $totals[$key] = '';
        }

        return $totals;
    }

    // =======================================================================
    // Labels
    // =======================================================================

    /** @param array<string,mixed> $filters */
    private static function subtitle(array $filters, string $primary): string
    {
        $parts = [$primary];

        if (!empty($filters['branch_id'])) {
            $branch = Database::instance()->first('SELECT name FROM branches WHERE id = ?', [(int) $filters['branch_id']]);
            if ($branch !== null) {
                $parts[] = 'Branch: ' . (string) $branch['name'];
            }
        } else {
            $parts[] = 'All branches';
        }

        if (!empty($filters['agent_id'])) {
            $agent = Database::instance()->first('SELECT name FROM users WHERE id = ?', [(int) $filters['agent_id']]);
            if ($agent !== null) {
                $parts[] = 'Agent: ' . (string) $agent['name'];
            }
        }
        if (!empty($filters['village'])) {
            $parts[] = 'Village: ' . (string) $filters['village'];
        }
        if (!empty($filters['loan_type'])) {
            $parts[] = 'Loan type: ' . (string) $filters['loan_type'];
        }

        return implode(' · ', $parts);
    }

    /** @param array<string,mixed> $filters */
    private static function rangeLabel(array $filters, string $noun = 'visit date'): string
    {
        $from = self::dateOrNull($filters['date_from'] ?? null);
        $to = self::dateOrNull($filters['date_to'] ?? null);

        if ($from !== null && $to !== null) {
            return sprintf('%s %s to %s', ucfirst($noun), self::humanDate($from), self::humanDate($to));
        }
        if ($from !== null) {
            return sprintf('%s from %s', ucfirst($noun), self::humanDate($from));
        }
        if ($to !== null) {
            return sprintf('%s up to %s', ucfirst($noun), self::humanDate($to));
        }
        return 'All dates';
    }

    private static function humanDate(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp === false ? $date : date('d M Y', $timestamp);
    }

    private static function dateOr(mixed $value, string $default): string
    {
        $date = self::dateOrNull($value);
        return $date ?? $default;
    }

    private static function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        return checkdate($m, $d, $y) ? $raw : null;
    }

    // =======================================================================
    // Export
    // =======================================================================

    /**
     * @param array<string,mixed> $report
     * @return array{0:string,1:string,2:string} [binary, filename, mime]
     */
    public static function toExcel(array $report): array
    {
        /** @var list<array<string,mixed>> $columns */
        $columns = $report['columns'];
        $headings = array_map(static fn (array $c): string => (string) $c['label'], $columns);

        $rows = [];
        foreach ($report['rows'] as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = self::exportValue($row[$column['key']] ?? null, (string) $column['type']);
            }
            $rows[] = $line;
        }

        $totals = null;
        if (!empty($report['totals'])) {
            $totals = [];
            foreach ($columns as $column) {
                $totals[] = self::exportValue($report['totals'][$column['key']] ?? null, (string) $column['type']);
            }
        }

        $filename = self::filename((string) $report['type']);
        $sheetName = mb_substr((string) $report['title'], 0, 31);

        if (\App\Core\Xlsx::available()) {
            return [
                \App\Core\Xlsx::build(
                    $sheetName,
                    $headings,
                    $rows,
                    (string) $report['title'],
                    (string) $report['subtitle'],
                    $totals
                ),
                $filename . '.xlsx',
                \App\Core\Xlsx::MIME,
            ];
        }

        // Shared host without ZipArchive: CSV still opens in Excel.
        return [
            \App\Core\Xlsx::csv($headings, $rows, $totals),
            $filename . '.csv',
            'text/csv; charset=utf-8',
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array{0:string,1:string,2:string}
     */
    public static function toPdf(array $report): array
    {
        /** @var list<array<string,mixed>> $columns */
        $columns = $report['columns'];

        $bank = (string) Settings::get('bank_name', '');
        $footer = ($bank !== '' ? $bank . ' · ' : '') . 'D2 Recovery Solutions & Services confidential - for internal recovery use only';

        $pdf = new \App\Core\Pdf(
            (string) $report['title'],
            (string) $report['subtitle'],
            (bool) ($report['landscape'] ?? false),
            $footer
        );

        // Same masthead the field visit report uses, not the generic header band - every
        // report this system exports is recognised by the same head, and the agency's
        // own name belongs across the top of it rather than the bank's.
        $organisation = trim((string) Settings::get('report_org_name', '')) !== ''
            ? (string) Settings::get('report_org_name')
            : 'D2 Recovery Solutions & Services';

        $pdf->useRunningHeader($organisation . '  |  ' . (string) $report['title']);
        $pdf->titleBlock($organisation, (string) $report['title'], array_filter([
            (string) $report['subtitle'],
        ]));

        // Summary strip above the table.
        if (!empty($report['summary'])) {
            $pairs = [];
            foreach ($report['summary'] as $item) {
                $pairs[(string) $item['label']] = (string) $item['value'];
            }
            $pdf->keyValueBlock($pairs, min(4, max(1, count($pairs))));
            $pdf->spacer(6);
        }

        $pdf->setColumns(array_map(
            static fn (array $c): array => [
                'label' => (string) $c['label'],
                'width' => (float) ($c['width'] ?? 1.0),
                'align' => in_array((string) $c['type'], ['number', 'money', 'percent'], true) ? 'right' : 'left',
            ],
            $columns
        ));

        $pdf->tableHeader();

        foreach ($report['rows'] as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = self::pdfValue($row[$column['key']] ?? null, (string) $column['type']);
            }
            $pdf->row($line);
        }

        if (!empty($report['totals'])) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = self::pdfValue($report['totals'][$column['key']] ?? null, (string) $column['type']);
            }
            $pdf->totalRow($line);
        }

        if ($report['rows'] === []) {
            $pdf->spacer(14);
            $pdf->paragraph('No records matched the selected filters.');
        }

        return [$pdf->output(), self::filename((string) $report['type']) . '.pdf', \App\Core\Pdf::MIME];
    }

    /** Raw typed value for Excel (numbers stay numeric). */
    private static function exportValue(mixed $value, string $type): string|int|float|null
    {
        if ($value === null || $value === '') {
            return $value === '' ? '' : null;
        }

        return match ($type) {
            'number'  => is_float($value) ? (float) $value : (int) $value,
            'money', 'percent' => (float) $value,
            'date'    => self::humanDate((string) $value),
            default   => (string) $value,
        };
    }

    /** Pre-formatted string for the PDF (which right-aligns by column). */
    private static function pdfValue(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money'   => number_format((float) $value, 2, '.', ','),
            'percent' => number_format((float) $value, 1) . '%',
            'number'  => is_float($value) && $value !== floor((float) $value)
                ? number_format((float) $value, 1)
                : number_format((float) $value, 0, '.', ','),
            'date'    => self::humanDate((string) $value),
            default   => (string) $value,
        };
    }

    private static function filename(string $type): string
    {
        return 'lrms_' . str_replace('-', '_', $type) . '_report_' . date('Ymd_His');
    }

    // =======================================================================
    // 10 & 11. RENEWAL WORKLISTS  (KCC and OD-2, separately)
    // =======================================================================

    /**
     * Accounts of one facility, ordered by how soon the renewal is due.
     *
     * A worklist rather than a summary: every other report aggregates, but nobody renews
     * an average. This is the list a branch works down, so it is one row per account with
     * the borrower, the village, who is on it and how many days are left.
     *
     * Accounts with no renewal date are included and sorted last. They are the ones most
     * likely to be wrong - a KCC with no due date recorded is a KCC nobody is tracking -
     * and dropping them would make the worklist look complete when it is not.
     *
     * @param  array<string,mixed> $filters
     * @param  'kcc'|'od2'         $facility
     * @return array<string,mixed>
     */
    private static function renewalWorklist(array $filters, string $facility): array
    {
        [$scope, $params] = self::leadScope($filters, 'la');

        $label = LoanAccount::FACILITIES[$facility] ?? strtoupper($facility);

        // Closed accounts are finished work and never need renewing.
        $rows = Database::instance()->all(
            "SELECT la.id,
                    la.loan_account_number,
                    c.name AS customer_name,
                    c.village,
                    la.loan_type,
                    la.sanction_limit,
                    la.outstanding_amount,
                    la.overdue_amount,
                    la.ckcc_renewal_due_date AS renewal_due_date,
                    CASE
                        WHEN la.ckcc_renewal_due_date IS NULL THEN NULL
                        ELSE DATEDIFF(la.ckcc_renewal_due_date, CURDATE())
                    END AS days_to_due,
                    la.asset_classification,
                    la.current_status,
                    COALESCE(ag.name, '') AS agent_name
               FROM loan_accounts la
               JOIN customers c ON c.id = la.customer_id
               LEFT JOIN users ag ON ag.id = la.assigned_agent_id
              WHERE la.facility_type = ?
                AND la.current_status <> 'closed'
                {$scope}
              ORDER BY la.ckcc_renewal_due_date IS NULL,
                       la.ckcc_renewal_due_date ASC,
                       la.overdue_amount DESC",
            array_merge([$facility], $params)
        );

        // Said in words on the report, because "-14" in a days column is read as a typo
        // more often than as "this lapsed a fortnight ago".
        $overdue = 0;
        $dueSoon = 0;
        $noDate = 0;
        foreach ($rows as $index => $row) {
            $days = $row['days_to_due'] === null ? null : (int) $row['days_to_due'];

            if ($days === null) {
                $noDate++;
                $rows[$index]['renewal_state'] = 'No due date recorded';
                continue;
            }
            if ($days < 0) {
                $overdue++;
                $rows[$index]['renewal_state'] = abs($days) . ' day(s) overdue';
                continue;
            }
            if ($days <= 30) {
                $dueSoon++;
            }
            $rows[$index]['renewal_state'] = $days === 0 ? 'Due today' : 'in ' . $days . ' day(s)';
        }

        $columns = [
            ['key' => 'loan_account_number',  'label' => 'Loan Account',   'type' => 'text',  'width' => 1.5],
            ['key' => 'customer_name',        'label' => 'Borrower',       'type' => 'text',  'width' => 1.6],
            ['key' => 'village',              'label' => 'Village',        'type' => 'text',  'width' => 1.1],
            ['key' => 'agent_name',           'label' => 'Agent',          'type' => 'text',  'width' => 1.3],
            ['key' => 'renewal_due_date',     'label' => 'Renewal Due',    'type' => 'date',  'width' => 1.1],
            ['key' => 'renewal_state',        'label' => 'Status',         'type' => 'text',  'width' => 1.3],
            ['key' => 'sanction_limit',       'label' => 'Limit',          'type' => 'money', 'width' => 1.2],
            ['key' => 'outstanding_amount',   'label' => 'Outstanding',    'type' => 'money', 'width' => 1.3],
            ['key' => 'overdue_amount',       'label' => 'Overdue',        'type' => 'money', 'width' => 1.2],
            ['key' => 'asset_classification', 'label' => 'Classification', 'type' => 'text',  'width' => 1.2],
        ];

        $rows = self::castRows($rows, $columns);

        // Money only. Summing a due date or a day count would produce a number that means
        // nothing, and sumTotals() cannot know that on its own.
        $totals = self::sumTotals($rows, array_values(array_filter(
            $columns,
            static fn (array $column): bool => $column['type'] === 'money'
        )), 'loan_account_number', 'TOTAL');

        if ($totals !== null) {
            $totals['customer_name'] = '';
            $totals['village'] = '';
            $totals['agent_name'] = '';
            $totals['renewal_due_date'] = null;
            $totals['renewal_state'] = sprintf('%d account(s)', count($rows));
            $totals['asset_classification'] = '';
        }

        $headline = sprintf(
            '%d overdue, %d due within 30 days, %d with no date recorded',
            $overdue,
            $dueSoon,
            $noDate
        );

        return [
            'type'     => $facility === 'kcc' ? 'kcc-renewal' : 'od2-renewal',
            'title'    => $label . ' Renewal Worklist',
            'subtitle' => self::subtitle($filters, $headline),
            'columns'  => $columns,
            'rows'     => $rows,
            'totals'   => $totals,
            // Every report in this service returns a summary and the API reads it
            // unconditionally, so omitting it is a 500 rather than a missing panel.
            'summary'  => [
                ['label' => 'Facility',            'value' => $label],
                ['label' => 'Accounts',            'value' => (string) count($rows)],
                ['label' => 'Renewal overdue',     'value' => (string) $overdue],
                ['label' => 'Due within 30 days',  'value' => (string) $dueSoon],
                ['label' => 'No due date on file', 'value' => (string) $noDate],
            ],
            'landscape' => true,
            'filename' => self::filename($facility === 'kcc' ? 'kcc-renewal' : 'od2-renewal'),
        ];
    }
}
