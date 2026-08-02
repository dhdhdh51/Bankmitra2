<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\Promise;
use App\Models\User;
use App\Services\DashboardService;

final class MetaController extends Controller
{
    /**
     * Used when the configured deadline is missing or malformed.
     *
     * Deliberately a real time rather than null: "no deadline configured" would turn
     * a misconfiguration into agents never being reminded, and nobody would notice
     * until a month of missing reports.
     */
    private const DEFAULT_DUE_TIME = '17:00';

    /** Fifteen minutes is firm without being a phone nobody can put down. */
    private const DEFAULT_REPEAT_MINUTES = 15;

    /** Late enough to catch an agent still out, early enough not to wake a household. */
    private const DEFAULT_UNTIL_HOUR = 22;

    /**
     * Unauthenticated health/version probe. The app calls this before the login
     * screen so it can show a "please update" prompt without a valid session.
     */
    public function ping(Request $request): void
    {
        Response::success([
            'status'      => 'ok',
            'app_name'    => Settings::get('app_name', 'D2 Recovery'),
            'bank_name'   => Settings::get('bank_name', ''),
            'app_version' => Settings::get('app_version', '1.0.0'),
            'min_version' => Settings::get('app_min_version', '1.0.0'),
            'api_version' => 'v1',
            'server_time' => date('c'),
        ]);
    }

    /**
     * Dropdown data and configuration the app caches after sign-in.
     */
    public function meta(Request $request): void
    {
        $user = $this->auth($request);
        $scoped = Auth::scopedBranchId();

        // Agents pick villages and loan types from their own branch only.
        $branchId = Auth::isAgent()
            ? ($user['branch_id'] === null ? null : (int) $user['branch_id'])
            : $scoped;

        Response::success([
            'villages'    => LoanAccount::villages($branchId),
            'loan_types'  => LoanAccount::loanTypes($branchId),
            'statuses'    => LoanAccount::STATUSES,
            'branches'    => Auth::isAgent() ? [] : array_map(
                static fn (array $b): array => [
                    'id'   => (int) $b['id'],
                    'code' => (string) $b['branch_code'],
                    'name' => (string) $b['name'],
                ],
                Branch::options($scoped)
            ),
            'agents'      => Auth::isAgent() ? [] : array_map(
                static fn (array $a): array => [
                    'id'            => (int) $a['id'],
                    'name'          => (string) $a['name'],
                    'employee_code' => (string) $a['employee_code'],
                ],
                User::agents($scoped)
            ),
            'promise_statuses' => Promise::STATUSES,
            'app_version'      => Settings::get('app_version', '1.0.0'),
            'min_version'      => Settings::get('app_min_version', '1.0.0'),
            'maps_key'         => Settings::get('google_maps_key'),

            // The daily report deadline. The app schedules a local alarm from this,
            // so the bank sets it in one place instead of it being a time typed into
            // each phone - and changing it here moves every agent's reminder.
            'report_due_time'  => self::reportDueTime(),
            'report_reminder'  => Settings::bool('daily_report_reminder_enabled'),

            // How the alarm behaves once it has fired. Both are the bank's numbers, sent
            // to the phone rather than configured on it, because an agent who can move
            // their own reminder can move it past the deadline they are measured against.
            'report_reminder_repeat_minutes' => self::reminderRepeatMinutes(),
            'report_reminder_until_hour'     => self::reminderUntilHour(),
        ]);
    }

    /**
     * How often the alarm re-fires until the report is in, in minutes.
     *
     * Clamped rather than trusted, like the deadline: the settings screen has no
     * per-type validation, so a browser can post anything. A 0 means "one reminder and
     * no repeating", which is a real choice. Anything below 5 is refused rather than
     * honoured - a phone buzzing every minute is not a firmer reminder, it is an
     * uninstalled app.
     */
    public static function reminderRepeatMinutes(): int
    {
        $raw = trim((string) (Settings::get('daily_report_reminder_repeat_minutes', '') ?? ''));

        if (!preg_match('/^\d{1,4}$/', $raw)) {
            return self::DEFAULT_REPEAT_MINUTES;
        }

        $minutes = (int) $raw;

        if ($minutes === 0) {
            return 0;
        }

        return max(5, min(240, $minutes));
    }

    /**
     * The hour repeats stop at, 0-23.
     *
     * "Until it is submitted" cannot literally mean all night: an alarm at 2 am gets the
     * app's notifications switched off entirely, which loses the reminders that were
     * working. Repeats stop here and resume at the deadline on the next working day.
     */
    public static function reminderUntilHour(): int
    {
        $raw = trim((string) (Settings::get('daily_report_reminder_until_hour', '') ?? ''));

        if (!preg_match('/^\d{1,2}$/', $raw)) {
            return self::DEFAULT_UNTIL_HOUR;
        }

        return min(23, max(0, (int) $raw));
    }

    /**
     * The configured deadline as HH:MM, or 17:00.
     *
     * Validated on the way out rather than trusted. The settings screen has no
     * per-type validation, so this value can be anything a browser posted, and a
     * blank or malformed time reaching the app would either crash the alarm
     * scheduler or silently mean "no deadline" - which is the one interpretation
     * nobody wants for a deadline agents are measured against.
     */
    public static function reportDueTime(): string
    {
        $raw = trim((string) (Settings::get('daily_report_due_time', '') ?? ''));

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $matches) !== 1) {
            return self::DEFAULT_DUE_TIME;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return self::DEFAULT_DUE_TIME;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Home-screen counters. An agent sees their own book; a manager or admin sees
     * the branch dashboard.
     */
    public function dashboard(Request $request): void
    {
        $user = $this->auth($request, 'dashboard.view');

        if (Auth::isAgent()) {
            Response::success(DashboardService::forAgent((int) $user['id']));
        }

        $scoped = Auth::scopedBranchId();
        $branchId = $scoped ?? ($request->nullableInt('branch_id') ?: null);

        $data = DashboardService::build($branchId);

        Response::success([
            'cards'            => $data['cards'],
            'status_breakdown' => $data['status_breakdown'],
            'visit_trend'      => $data['visit_trend'],
            'promise_counts'   => $data['promise_counts'],
            'top_agents'       => array_map(
                static fn (array $a): array => [
                    'id'             => (int) $a['id'],
                    'name'           => (string) $a['name'],
                    'employee_code'  => (string) $a['employee_code'],
                    'visits_month'   => (int) $a['visits_month'],
                    'visits_today'   => (int) $a['visits_today'],
                    'assigned_leads' => (int) $a['assigned_leads'],
                ],
                $data['top_agents']
            ),
        ]);
    }

    /** Promise cases visible to the caller. */
    public function promises(Request $request): void
    {
        $user = $this->auth($request, 'promises.view');

        $filters = [
            'status'    => $request->str('status'),
            'date_from' => $request->str('date_from'),
            'date_to'   => $request->str('date_to'),
            'search'    => $request->str('search'),
        ];

        if (Auth::isAgent()) {
            $filters['agent_id'] = (int) $user['id'];
        } else {
            $scoped = Auth::scopedBranchId();
            $filters['branch_id'] = $scoped ?? ($request->nullableInt('branch_id') ?: null);
            $agentId = $request->nullableInt('agent_id');
            if ($agentId !== null && $agentId > 0) {
                $filters['agent_id'] = $agentId;
            }
        }

        $page = Promise::paginate($filters, $request->page(), $this->perPage($request));

        Response::success(
            array_map(function (array $promise): array {
                $presented = $this->presentPromise($promise);
                $presented['loan_account_number'] = (string) $promise['loan_account_number'];
                $presented['customer_name'] = (string) $promise['customer_name'];
                $presented['village'] = $promise['village'] === null ? null : (string) $promise['village'];
                $presented['days_overdue'] = (int) $promise['days_overdue'];
                return $presented;
            }, $page->items),
            '',
            ['meta' => $page->meta()]
        );
    }

    /** Marks a promise kept / broken / cancelled. */
    public function settlePromise(Request $request): void
    {
        $user = $this->auth($request, 'promises.update');

        $id = $request->paramInt('id');
        $status = $request->str('status');

        $promise = Promise::find($id);
        if ($promise === null) {
            Response::notFound('That promise could not be found.');
        }

        if (!Auth::isAgent()) {
            Auth::assertBranchAccess((int) $promise['branch_id']);
        } elseif ((int) $promise['agent_id'] !== (int) $user['id']) {
            Response::forbidden('That promise belongs to another agent.');
        }

        if ((string) $promise['status'] !== 'pending') {
            Response::error('That promise has already been settled.', 422);
        }

        if (!in_array($status, ['kept', 'broken', 'cancelled'], true)) {
            Response::error('status must be kept, broken or cancelled.', 422);
        }

        $ok = Promise::settle($id, $status, (int) $user['id'], (string) $user['name'], $request->nullableStr('notes'));
        if (!$ok) {
            Response::error('The promise could not be updated.', 422);
        }

        $fresh = Promise::find($id);

        Response::success(
            $fresh === null ? null : $this->presentPromise($fresh),
            sprintf('Promise marked as %s.', $status)
        );
    }
}
