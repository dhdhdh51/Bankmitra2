<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Targets, daily achievement, escalating warnings and the BC scorecard.
 *
 * Two ideas hold this together.
 *
 * First, achievement is DERIVED, never entered. Every figure is recomputed from
 * visit_reports, promises and sss_enrollment, so bc_daily_achievement is a cache
 * that can be thrown away and rebuilt. A warning is a statement about someone's
 * job, so the number behind it has to be reproducible from the source records
 * rather than from a counter that some earlier code path forgot to increment.
 *
 * Second, a warning is about a PATTERN, not a bad day. An agent whose borrower was
 * at a wedding, or who spent the day in a branch meeting, has missed a target
 * through nobody's fault. So the level comes from the length of the consecutive
 * miss streak - 1 day, 3 days, 7 days - and Sundays are not counted as misses,
 * because nobody is expected to enrol anyone on a Sunday.
 */
final class BcPerformanceService
{
    /** Streak length at which each level is issued. */
    private const LEVEL_THRESHOLDS = ['L3' => 7, 'L2' => 3, 'L1' => 1];

    /** Days at final warning before the admin banner is raised. */
    private const ESCALATION_AFTER_DAYS = 7;

    /**
     * Metrics that carry a target, and where the achievement comes from.
     *
     * daily = the target is per working day; monthly targets are pro-rated across
     * the working days of the month so a mid-month check is judged fairly rather
     * than against a full month's number.
     */
    private const METRICS = [
        'visit'        => ['target' => 'daily_visit_target',  'done' => 'visits_done',       'daily' => true,  'money' => false],
        'apy'          => ['target' => 'apy_target',          'done' => 'apy_done',          'daily' => false, 'money' => false],
        'pmjjby'       => ['target' => 'pmjjby_target',       'done' => 'pmjjby_done',       'daily' => false, 'money' => false],
        'pmsby'        => ['target' => 'pmsby_target',        'done' => 'pmsby_done',        'daily' => false, 'money' => false],
        'pmjdy'        => ['target' => 'pmjdy_target',        'done' => 'pmjdy_done',        'daily' => false, 'money' => false],
        'npa_recovery' => ['target' => 'npa_recovery_target', 'done' => 'npa_recovery_done', 'daily' => false, 'money' => true],
        'od2_renewal'  => ['target' => 'od2_renewal_target',  'done' => 'od2_renewal_done',  'daily' => false, 'money' => false],
    ];

    // =======================================================================
    // Daily rollup
    // =======================================================================

    /**
     * Live per-agent figures for a date range, derived from source records.
     *
     * This is the single definition of every metric in this module. `rollUpDay()`
     * persists a snapshot of it, `scorecard()` and the daily report read it
     * directly, and nothing computes any of these numbers a second way.
     *
     * READ THIS BEFORE REPLACING IT WITH THE CACHE. `bc_daily_achievement` is only
     * written by the 23:55 cron. Reading the scorecard from that cache meant that
     * for the whole working day every agent showed zero visits, zero contacts and a
     * zero score - the report was blank precisely while it was useful, and it read
     * as "these agents did nothing" rather than "the data is not in yet". The cache
     * is a historical snapshot for warnings, not the answer to what happened today.
     *
     * Counted from source rather than from a stored counter, so there is nothing to
     * drift out of step:
     *   visits       - rows in visit_reports for the agent on the date
     *   contacts     - those where the borrower was actually met
     *   od2_renewal  - those filed as a CKCC / OD-2 renewal
     *   ptp          - promises created that day, whatever they later become
     *   npa_recovery - promises the branch marked kept, plus confirmed OTS deposits
     *   apy..pmjdy   - the SSS enrolment figures recorded for the day
     *
     * @param  string   $from     inclusive, Y-m-d
     * @param  string   $to       inclusive, Y-m-d
     * @param  int|null $branchId restrict to one branch
     * @param  int|null $agentId  restrict to one agent
     * @return list<array<string,mixed>>
     */
    public static function figures(
        string $from,
        string $to,
        ?int $branchId = null,
        ?int $agentId = null
    ): array {
        // Correlated subqueries rather than a pile of LEFT JOINs. Joining
        // visit_reports, promises and sss_enrollment in one statement multiplies the
        // rows together, and every SUM then counts each visit once per promise -
        // which does not error, it just reads as an agent doing suspiciously well.
        $range = [$from, $to];

        $params = [];
        for ($i = 0; $i < 12; $i++) {
            array_push($params, ...$range);
        }

        $where = '';
        if ($branchId !== null) {
            $where .= ' AND u.branch_id = ?';
            $params[] = $branchId;
        }
        if ($agentId !== null) {
            $where .= ' AND u.id = ?';
            $params[] = $agentId;
        }

        $rows = Database::instance()->all(
            "SELECT u.id AS agent_id, u.employee_code, u.name AS agent_name, u.bc_code,
                    u.branch_id, u.dashboard_status, u.escalation_flag,
                    b.name AS branch_name,
                    (SELECT COUNT(*) FROM loan_accounts la
                      WHERE la.assigned_agent_id = u.id) AS allocated,
                    (SELECT COUNT(*) FROM visit_reports vr
                      WHERE vr.agent_id = u.id AND vr.visit_date BETWEEN ? AND ?) AS visits,
                    (SELECT COALESCE(SUM(CASE WHEN vr.customer_met = 1 THEN 1 ELSE 0 END), 0)
                       FROM visit_reports vr
                      WHERE vr.agent_id = u.id AND vr.visit_date BETWEEN ? AND ?) AS contacts,
                    (SELECT COALESCE(SUM(CASE WHEN vr.report_type = 'ckcc_renewal' THEN 1 ELSE 0 END), 0)
                       FROM visit_reports vr
                      WHERE vr.agent_id = u.id AND vr.visit_date BETWEEN ? AND ?) AS od2_renewal,
                    (SELECT COUNT(*) FROM promises p
                      WHERE p.agent_id = u.id AND DATE(p.created_at) BETWEEN ? AND ?) AS ptp,
                    (SELECT COALESCE(SUM(p.promise_amount), 0) FROM promises p
                      WHERE p.agent_id = u.id AND p.status = 'kept'
                        AND DATE(p.settled_at) BETWEEN ? AND ?) AS kept_recovery,
                    (SELECT COALESCE(SUM(o.deposit_amount), 0)
                       FROM visit_ots_details o
                       JOIN visit_reports v ON v.id = o.visit_report_id
                      WHERE v.agent_id = u.id AND o.deposit_received = 1
                        AND o.deposit_date BETWEEN ? AND ?) AS ots_recovery,
                    (SELECT COALESCE(SUM(s.apy_count), 0) FROM sss_enrollment s
                      WHERE s.agent_id = u.id AND s.enrollment_date BETWEEN ? AND ?) AS apy,
                    (SELECT COALESCE(SUM(s.pmjjby_count), 0) FROM sss_enrollment s
                      WHERE s.agent_id = u.id AND s.enrollment_date BETWEEN ? AND ?) AS pmjjby,
                    (SELECT COALESCE(SUM(s.pmsby_count), 0) FROM sss_enrollment s
                      WHERE s.agent_id = u.id AND s.enrollment_date BETWEEN ? AND ?) AS pmsby,
                    (SELECT COALESCE(SUM(s.pmjdy_count), 0) FROM sss_enrollment s
                      WHERE s.agent_id = u.id AND s.enrollment_date BETWEEN ? AND ?) AS pmjdy,
                    (SELECT COUNT(*) FROM sss_enrollment s
                      WHERE s.agent_id = u.id AND s.enrollment_date BETWEEN ? AND ?) AS sss_days,
                    (SELECT COUNT(DISTINCT vr.visit_date) FROM visit_reports vr
                      WHERE vr.agent_id = u.id AND vr.visit_date BETWEEN ? AND ?) AS visit_days
               FROM users u
               JOIN roles r ON r.id = u.role_id
          LEFT JOIN branches b ON b.id = u.branch_id
              WHERE r.slug = 'agent' AND u.status = 'active'" . $where . '
           ORDER BY u.name ASC',
            $params
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['npa_recovery'] = round(
                (float) $row['kept_recovery'] + (float) $row['ots_recovery'],
                2
            );

            // Reported at all: filed a visit, or entered enrolment figures. The one
            // target that is about showing up rather than about numbers.
            $rows[$index]['report_submitted'] =
                ((int) $row['visits'] > 0 || (int) $row['sss_days'] > 0) ? 1 : 0;

            $rows[$index]['sss_total'] = (int) $row['apy'] + (int) $row['pmjjby']
                + (int) $row['pmsby'] + (int) $row['pmjdy'];
        }

        return $rows;
    }

    /**
     * Recomputes and stores one agent's figures for one day.
     *
     * Idempotent: running it again for the same day overwrites rather than adds,
     * so the cron can be re-run after a failure and a backfill cannot double count.
     *
     * @return array<string,mixed> the stored row
     */
    public static function rollUpDay(int $agentId, string $date): array
    {
        $db = Database::instance();

        // Derived by figures(), not by a second copy of the same SQL living here.
        // Two implementations of "how many visits did this agent make" is two
        // answers, and the one an agent is warned on would not be the one their
        // supervisor sees on screen.
        $live = self::figures($date, $date, null, $agentId);
        $figures = $live[0] ?? [];

        $row = [
            'agent_id'          => $agentId,
            'achievement_date'  => $date,
            'apy_done'          => (int) ($figures['apy'] ?? 0),
            'pmjjby_done'       => (int) ($figures['pmjjby'] ?? 0),
            'pmsby_done'        => (int) ($figures['pmsby'] ?? 0),
            'pmjdy_done'        => (int) ($figures['pmjdy'] ?? 0),
            'npa_recovery_done' => round((float) ($figures['npa_recovery'] ?? 0), 2),
            'od2_renewal_done'  => (int) ($figures['od2_renewal'] ?? 0),
            'visits_done'       => (int) ($figures['visits'] ?? 0),
            'contacts_done'     => (int) ($figures['contacts'] ?? 0),
            'ptp_done'          => (int) ($figures['ptp'] ?? 0),
            // "Did this agent report at all today?" - the one target that is about
            // showing up rather than about numbers.
            'report_submitted'  => (int) ($figures['report_submitted'] ?? 0),
            'computed_at'       => date('Y-m-d H:i:s'),
        ];

        $db->query(
            'INSERT INTO bc_daily_achievement
                (agent_id, achievement_date, apy_done, pmjjby_done, pmsby_done, pmjdy_done,
                 npa_recovery_done, od2_renewal_done, visits_done, contacts_done, ptp_done,
                 report_submitted, computed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                apy_done = VALUES(apy_done), pmjjby_done = VALUES(pmjjby_done),
                pmsby_done = VALUES(pmsby_done), pmjdy_done = VALUES(pmjdy_done),
                npa_recovery_done = VALUES(npa_recovery_done),
                od2_renewal_done = VALUES(od2_renewal_done),
                visits_done = VALUES(visits_done), contacts_done = VALUES(contacts_done),
                ptp_done = VALUES(ptp_done), report_submitted = VALUES(report_submitted),
                computed_at = VALUES(computed_at)',
            array_values($row)
        );

        return $row;
    }

    // =======================================================================
    // Targets
    // =======================================================================

    /** @return array<string,mixed>|null */
    public static function targetsFor(int $agentId, string $date): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM bc_targets WHERE agent_id = ? AND target_month = ? LIMIT 1',
            [$agentId, date('Y-m-01', strtotime($date))]
        );
    }

    /**
     * Working days in a month up to and including a date, Sundays excluded.
     *
     * Used to pro-rate a monthly target. Judging an agent on the 3rd against the
     * whole month's enrolment target would put everyone on final warning by the
     * first week.
     */
    public static function workingDaysElapsed(string $date): int
    {
        $end = strtotime($date);
        $cursor = strtotime(date('Y-m-01', $end));
        $days = 0;

        while ($cursor <= $end) {
            if ((int) date('N', $cursor) !== 7) {
                $days++;
            }
            $cursor = strtotime('+1 day', $cursor);
        }

        return max(1, $days);
    }

    public static function workingDaysInMonth(string $date): int
    {
        $days = 0;
        $last = (int) date('t', strtotime($date));
        for ($day = 1; $day <= $last; $day++) {
            $stamp = strtotime(date('Y-m-', strtotime($date)) . str_pad((string) $day, 2, '0', STR_PAD_LEFT));
            if ((int) date('N', $stamp) !== 7) {
                $days++;
            }
        }
        return max(1, $days);
    }

    /** Sunday is not a miss. */
    public static function isWorkingDay(string $date): bool
    {
        return (int) date('N', strtotime($date)) !== 7;
    }

    // =======================================================================
    // Gaps and warning levels
    // =======================================================================

    /**
     * Which targets this agent missed on this date, and by how much.
     *
     * A metric with a target of zero is not assessed at all - an agent who was
     * never given an APY target has not failed to meet one.
     *
     * @return array<string,array{target:float,achieved:float,gap:float,money:bool}>
     */
    public static function gapsFor(int $agentId, string $date): array
    {
        $targets = self::targetsFor($agentId, $date);
        if ($targets === null) {
            return [];
        }

        $achievement = Database::instance()->first(
            'SELECT * FROM bc_daily_achievement WHERE agent_id = ? AND achievement_date = ? LIMIT 1',
            [$agentId, $date]
        ) ?? [];

        $monthShare = self::workingDaysElapsed($date) / self::workingDaysInMonth($date);
        $gaps = [];

        foreach (self::METRICS as $metric => $spec) {
            $target = (float) ($targets[$spec['target']] ?? 0);
            if ($target <= 0) {
                continue;
            }

            if ($spec['daily']) {
                $expected = $target;
                $achieved = (float) ($achievement[$spec['done']] ?? 0);
            } elseif (!$spec['money']) {
                // Pro-rated count targets are floored. 27 enrolments across 26
                // working days is 2.08 by the second day, and warning somebody for
                // being 0.08 of a person short is the kind of thing that makes a
                // whole system get switched off.
                $expected = floor($target * $monthShare);
                $achieved = (float) (Database::instance()->scalar(
                    sprintf(
                        'SELECT COALESCE(SUM(%s), 0) FROM bc_daily_achievement
                          WHERE agent_id = ? AND achievement_date BETWEEN ? AND ?',
                        $spec['done']
                    ),
                    [$agentId, date('Y-m-01', strtotime($date)), $date]
                ) ?? 0);
            } else {
                // Monthly target, pro-rated, against the month's running total.
                $expected = $target * $monthShare;
                $achieved = (float) (Database::instance()->scalar(
                    sprintf(
                        'SELECT COALESCE(SUM(%s), 0) FROM bc_daily_achievement
                          WHERE agent_id = ? AND achievement_date BETWEEN ? AND ?',
                        $spec['done']
                    ),
                    [$agentId, date('Y-m-01', strtotime($date)), $date]
                ) ?? 0);
            }

            if ($achieved + 0.001 < $expected) {
                $gaps[$metric] = [
                    'target'   => round($expected, 2),
                    'achieved' => round($achieved, 2),
                    'gap'      => round($expected - $achieved, 2),
                    'money'    => $spec['money'],
                ];
            }
        }

        // Not reporting at all is its own miss, independent of any numeric target.
        if ((int) ($achievement['report_submitted'] ?? 0) === 0) {
            $gaps['report'] = ['target' => 1.0, 'achieved' => 0.0, 'gap' => 1.0, 'money' => false];
        }

        return $gaps;
    }

    /**
     * How many consecutive working days this metric has been missed, ending today.
     *
     * Counts backwards through bc_warnings, which is what makes the streak survive
     * a day when the cron did not run: a gap in the warning log ends the streak,
     * which errs towards the agent rather than against them.
     */
    public static function missStreak(int $agentId, string $metric, string $date): int
    {
        $db = Database::instance();
        $streak = 1;
        $cursor = strtotime($date);

        for ($step = 0; $step < 60; $step++) {
            $cursor = strtotime('-1 day', $cursor);
            $day = date('Y-m-d', $cursor);

            if (!self::isWorkingDay($day)) {
                continue;   // Sundays neither break nor extend a streak
            }

            $missed = $db->scalar(
                'SELECT 1 FROM bc_warnings
                  WHERE agent_id = ? AND target_type = ? AND triggered_date = ? LIMIT 1',
                [$agentId, $metric, $day]
            );

            if ($missed === null) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /** Warning level for a streak length. */
    public static function levelForStreak(int $streak): string
    {
        foreach (self::LEVEL_THRESHOLDS as $level => $threshold) {
            if ($streak >= $threshold) {
                return $level;
            }
        }
        return 'L1';
    }

    /** Dashboard badge for a level. */
    public static function statusForLevel(string $level): string
    {
        return match ($level) {
            'L3' => 'final_warning',
            'L2' => 'warning_2',
            'L1' => 'warning_1',
            default => 'normal',
        };
    }

    /**
     * Records a warning, or returns null when one already exists for the day.
     *
     * @param array{target:float,achieved:float,gap:float,money:bool} $gap
     */
    public static function recordWarning(int $agentId, string $metric, array $gap, string $date): ?array
    {
        $streak = self::missStreak($agentId, $metric, $date);
        $level = self::levelForStreak($streak);

        $format = static fn (float $value): string => $gap['money']
            ? number_format($value, 2, '.', '')
            : (string) (int) round($value);

        try {
            $id = Database::instance()->insert('bc_warnings', [
                'agent_id'       => $agentId,
                'warning_level'  => $level,
                'target_type'    => $metric,
                'target_value'   => $format($gap['target']),
                'achieved_value' => $format($gap['achieved']),
                'gap_value'      => $format($gap['gap']),
                'miss_streak'    => $streak,
                'triggered_date' => $date,
                'status'         => 'open',
            ]);
        } catch (\Throwable) {
            // The unique key did its job: the cron already ran for this day.
            return null;
        }

        return [
            'id'      => $id,
            'level'   => $level,
            'streak'  => $streak,
            'metric'  => $metric,
            'gap'     => $gap,
            'date'    => $date,
        ];
    }

    /**
     * Sets the agent's badge to the worst level currently open, and raises the
     * escalation flag when a final warning has gone unimproved for its window.
     */
    public static function refreshStanding(int $agentId, string $date): array
    {
        $db = Database::instance();

        $worst = $db->first(
            "SELECT warning_level, MIN(triggered_date) AS since
               FROM bc_warnings
              WHERE agent_id = ? AND status = 'open' AND triggered_date >= ?
           GROUP BY warning_level
           ORDER BY FIELD(warning_level, 'L3', 'L2', 'L1')
              LIMIT 1",
            [$agentId, date('Y-m-d', strtotime($date . ' -30 days'))]
        );

        $level = $worst === null ? 'none' : (string) $worst['warning_level'];
        $status = self::statusForLevel($level);

        $escalate = 0;
        if ($level === 'L3' && $worst !== null) {
            $daysAtFinal = (int) round(
                (strtotime($date) - strtotime((string) $worst['since'])) / 86400
            );
            $escalate = $daysAtFinal >= self::ESCALATION_AFTER_DAYS ? 1 : 0;
        }

        $db->update('users', [
            'dashboard_status'  => $status,
            'escalation_flag'   => $escalate,
            'status_changed_at' => date('Y-m-d H:i:s'),
        ], ['id' => $agentId]);

        return ['status' => $status, 'escalation_flag' => $escalate];
    }

    // =======================================================================
    // Scorecard
    // =======================================================================

    /** @return array<string,array{weight:float,divisor:float,label:string}> */
    public static function weights(): array
    {
        $weights = [];
        foreach (Database::instance()->all('SELECT * FROM score_weights ORDER BY sort_order') as $row) {
            $weights[(string) $row['metric']] = [
                'weight'  => (float) $row['weight'],
                'divisor' => max(0.01, (float) $row['divisor']),
                'label'   => (string) $row['label'],
            ];
        }
        return $weights;
    }

    /**
     * The BC scorecard for a date range, ranked.
     *
     * Ranking is dense: two agents on the same score share a rank rather than one
     * being arbitrarily placed above the other, because this table is read as a
     * league and an arbitrary tie-break is an argument waiting to happen.
     *
     * @return list<array<string,mixed>>
     */
    public static function scorecard(string $from, string $to, ?int $branchId = null): array
    {
        // Live, via figures(). This used to read bc_daily_achievement, which the
        // 23:55 cron fills - so any range including today showed every agent on
        // zero, all day, on the one screen a supervisor opens to see how the day is
        // going.
        $rows = self::figures($from, $to, $branchId);

        $weights = self::weights();

        foreach ($rows as $index => $row) {
            $score = 0.0;
            foreach ($weights as $metric => $weight) {
                $value = (float) ($row[$metric] ?? 0);
                $score += ($value / $weight['divisor']) * $weight['weight'];
            }
            $rows[$index]['total_score'] = round($score, 2);
        }

        usort($rows, static fn (array $a, array $b): int => $b['total_score'] <=> $a['total_score']);

        $rank = 0;
        $previous = null;
        foreach ($rows as $index => $row) {
            if ($previous === null || $row['total_score'] < $previous) {
                $rank++;
                $previous = $row['total_score'];
            }
            $rows[$index]['rank'] = $rank;
        }

        return $rows;
    }
}
