<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Models\SssEnrollment;
use App\Services\BcPerformanceService;

/**
 * An agent recording their own SSS enrolments from the field.
 *
 * WHY THIS ENDPOINT HAD TO EXIST. The four scheme figures - APY, PMJJBY, PMSBY,
 * PMJDY - were enterable only in the admin panel, which an agent cannot open. But
 * `cron/bc-warning-check.php` measures those same four metrics against the agent's
 * monthly target every night and escalates a sustained shortfall to the supervisor,
 * then the service provider, then the regional office. So an agent could be
 * disciplined, in writing, for failing to report a number the software gave them no
 * way to report. That is not a missing feature, it is a system producing evidence
 * against people over its own gap.
 *
 * Three rules follow from what this data is used for:
 *
 *   OWN RECORD ONLY. The agent id comes from the token, never from the request.
 *   Nobody gets to file enrolments in somebody else's name when those numbers feed
 *   a ranking and a disciplinary trail.
 *
 *   ONE ROW PER DAY, CORRECTED IN PLACE. The unique key on (agent, date) is the
 *   thing that stops a retried POST on a dropped rural connection from doubling the
 *   day's figures - and these figures are summed into a score agents are ranked on.
 *   So this is an upsert, and a resend of the same numbers is a no-op rather than an
 *   error the app has to explain.
 *
 *   TODAY AND YESTERDAY ONLY. Not an arbitrary date. Backdating a month of
 *   enrolments the evening before an assessment is exactly the pressure a scored
 *   metric creates, and a correction to an older day is a conversation with a
 *   supervisor, who has the panel.
 */
final class SssController extends Controller
{
    /** How far back an agent may file or correct their own figures. */
    private const BACKDATE_DAYS = 1;

    /**
     * This agent's entry for a date, plus the month's running total.
     *
     * Returns zeros rather than 404 for a day with no entry: "nothing recorded yet"
     * is the normal state of every morning, and an app that treats it as an error
     * shows a red screen to somebody who has simply not started.
     */
    public function show(Request $request): void
    {
        $user = $this->auth($request);
        $agentId = (int) $user['id'];

        $date = $this->resolveDate($request, false);
        $entry = SssEnrollment::findForDate($agentId, $date);

        $monthStart = date('Y-m-01', (int) strtotime($date));
        $monthEnd = date('Y-m-t', (int) strtotime($date));

        Response::success([
            'date'      => $date,
            'editable'  => $this->isEditable($date),
            'recorded'  => $entry !== null,
            'apy'       => (int) ($entry['apy_count'] ?? 0),
            'pmjjby'    => (int) ($entry['pmjjby_count'] ?? 0),
            'pmsby'     => (int) ($entry['pmsby_count'] ?? 0),
            'pmjdy'     => (int) ($entry['pmjdy_count'] ?? 0),
            'remarks'   => $entry['remarks'] ?? null,
            'month'     => SssEnrollment::summary($monthStart, $monthEnd, null, $agentId),
            // Visits are never sent by the app - they are counted from the visit
            // reports already filed. Returned here so the agent sees the same number
            // their supervisor's report shows, rather than guessing.
            'today'     => $this->todayFigures($agentId, $date),
        ]);
    }

    /**
     * Records or corrects this agent's figures for a day.
     *
     * An upsert, not a create. See the class note: a retry on a flaky connection
     * must not double a figure that feeds a ranking.
     */
    public function store(Request $request): void
    {
        $user = $this->auth($request);
        $agentId = (int) $user['id'];

        if (($user['branch_id'] ?? null) === null) {
            // sss_enrollment.branch_id is NOT NULL and the reminder cron routes
            // escalation by it, so an agent with no branch cannot be attributed.
            Response::error('Your account has no branch, so enrolments cannot be recorded. Please contact your supervisor.', 409);
        }

        $this->validate($request, [
            'apy_count'    => 'nullable|integer|min_value:0|max_value:999',
            'pmjjby_count' => 'nullable|integer|min_value:0|max_value:999',
            'pmsby_count'  => 'nullable|integer|min_value:0|max_value:999',
            'pmjdy_count'  => 'nullable|integer|min_value:0|max_value:999',
            'remarks'      => 'nullable|max:500',
        ], [
            'apy_count'    => 'APY',
            'pmjjby_count' => 'PMJJBY',
            'pmsby_count'  => 'PMSBY',
            'pmjdy_count'  => 'PMJDY',
        ]);

        $date = $this->resolveDate($request, true);

        $data = [
            'apy_count'    => max(0, $request->int('apy_count')),
            'pmjjby_count' => max(0, $request->int('pmjjby_count')),
            'pmsby_count'  => max(0, $request->int('pmsby_count')),
            'pmjdy_count'  => max(0, $request->int('pmjdy_count')),
            'remarks'      => $request->nullableStr('remarks'),
        ];

        $existing = SssEnrollment::findForDate($agentId, $date);

        if ($existing === null) {
            $id = SssEnrollment::create($data + [
                'agent_id'        => $agentId,
                'branch_id'       => (int) $user['branch_id'],
                'enrollment_date' => $date,
            ]);

            Logger::audit('create', 'sss_enrollment', $id, null, $data, sprintf(
                'Agent recorded SSS enrolment for %s from the app',
                $date
            ));
        } else {
            $id = (int) $existing['id'];
            SssEnrollment::update($id, $data);

            Logger::auditDiff('sss_enrollment', $id, $existing, $data, sprintf(
                'Agent corrected SSS enrolment for %s from the app',
                $date
            ));
        }

        Response::success([
            'id'       => $id,
            'date'     => $date,
            'recorded' => true,
            'total'    => array_sum([
                $data['apy_count'], $data['pmjjby_count'],
                $data['pmsby_count'], $data['pmjdy_count'],
            ]),
            'today'    => $this->todayFigures($agentId, $date),
        ], $existing === null ? 'Enrolment recorded.' : 'Enrolment updated.');
    }

    // -----------------------------------------------------------------------

    /**
     * The requested date, defaulting to today, refused if out of range on write.
     *
     * On a read an out-of-range date is clamped rather than rejected, because
     * showing an old day read-only is harmless and useful.
     */
    private function resolveDate(Request $request, bool $forWrite): string
    {
        $raw = trim((string) $request->input('date', ''));

        if ($raw === '') {
            return date('Y-m-d');
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            Response::error('That date could not be read. Use YYYY-MM-DD.', 422);
        }

        $date = date('Y-m-d', (int) $timestamp);

        if ($forWrite && !$this->isEditable($date)) {
            Response::error(sprintf(
                'You can only record today or the previous %d day(s). Ask your supervisor to correct an older entry.',
                self::BACKDATE_DAYS
            ), 422);
        }

        return $date;
    }

    private function isEditable(string $date): bool
    {
        $today = date('Y-m-d');

        if ($date > $today) {
            return false;
        }

        return $date >= date('Y-m-d', (int) strtotime('-' . self::BACKDATE_DAYS . ' days'));
    }

    /**
     * The agent's own counted figures for the day.
     *
     * @return array<string,mixed>
     */
    private function todayFigures(int $agentId, string $date): array
    {
        $figures = BcPerformanceService::figures($date, $date, null, $agentId);
        $row = $figures[0] ?? [];

        return [
            'visits'       => (int) ($row['visits'] ?? 0),
            'contacts'     => (int) ($row['contacts'] ?? 0),
            'ptp'          => (int) ($row['ptp'] ?? 0),
            'od2_renewal'  => (int) ($row['od2_renewal'] ?? 0),
            'npa_recovery' => (float) ($row['npa_recovery'] ?? 0),
            'sss_total'    => (int) ($row['sss_total'] ?? 0),
        ];
    }
}
