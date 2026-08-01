<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Validator;
use App\Models\Branch;
use App\Models\SssEnrollment;
use App\Models\User;

/**
 * Social Security Scheme enrolment entry.
 *
 * One row per agent per day, corrected in place rather than appended to. The
 * scorecard sums these, so a duplicated day would inflate a score that agents are
 * ranked on - which is why a second entry for the same day sends the user to the
 * existing row instead of inserting.
 *
 * The branch is taken from the agent, never from the form. It exists on the row
 * because `cron/sss-reminder.php` needs it to decide which supervisor to copy, and
 * an agent who moves branches should not retrospectively move their past entries.
 */
final class SssController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'sss.view');

        [$sortBy, $sortDir] = $request->sort(SssEnrollment::SORTABLE, 'enrollment_date', 'DESC');

        $branchId = $this->branchFilter($request);
        $agentId = $this->agentFilter($request);

        // Defaults to the current month rather than everything: the useful question
        // is almost always "how are we doing this month", and an unbounded list on a
        // year of data is slow for no reason.
        $from = $request->str('from') !== '' ? $request->str('from') : date('Y-m-01');
        $to = $request->str('to') !== '' ? $request->str('to') : date('Y-m-d');

        $entries = SssEnrollment::paginate(
            $request->str('search'),
            $from,
            $to,
            $branchId,
            $agentId,
            $sortBy,
            $sortDir,
            $request->page(),
            $this->perPage($request),
        );

        $this->view($request, 'bc/sss/index', [
            'title' => 'SSS enrolment',
            'entries' => $entries,
            'schemeFields' => SssEnrollment::schemeFields(),
            'summary' => SssEnrollment::summary($from, $to, $branchId, $agentId),
            'search' => $request->str('search'),
            'from' => $from,
            'to' => $to,
            'branchId' => $branchId,
            'agentId' => $agentId,
            'branches' => Branch::options(Auth::scopedBranchId()),
            'agents' => User::agents($branchId ?? Auth::scopedBranchId()),
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    public function create(Request $request): void
    {
        $this->guard($request, 'sss.manage');

        if (!$request->isPost()) {
            $this->view($request, 'bc/sss/form', [
                'title' => 'Record SSS enrolment',
                'entry' => null,
                'schemeFields' => SssEnrollment::schemeFields(),
                'agents' => User::agents(Auth::scopedBranchId()),
            ]);
        }

        $validator = $this->validate($request);
        if ($validator->fails()) {
            $this->backWithErrors('/bc/sss/create', $validator->errors(), $request->all());
        }

        $agentId = $request->int('agent_id');
        $agent = $this->agentInScope($agentId);
        $date = $request->str('enrollment_date');

        $existing = SssEnrollment::findForDate($agentId, $date);
        if ($existing !== null) {
            $this->back(
                '/bc/sss/' . (int) $existing['id'] . '/edit',
                'warning',
                'An entry for that agent and date already exists - correct it here rather than adding a second one.',
            );
        }

        $data = $this->payload($request) + [
            'agent_id' => $agentId,
            'enrollment_date' => $date,
            // From the agent record, not the form. sss_enrollment.branch_id is NOT
            // NULL and the reminder cron routes escalation by it.
            'branch_id' => (int) $agent['branch_id'],
        ];

        $id = SssEnrollment::create($data);

        Logger::audit(
            'create',
            'sss_enrollment',
            $id,
            null,
            $data,
            sprintf('Recorded SSS enrolment for %s on %s', (string) $agent['name'], $date),
        );

        $this->back('/bc/sss', 'success', 'Enrolment recorded.');
    }

    public function edit(Request $request): void
    {
        $this->guard($request, 'sss.manage');

        $id = $request->paramInt('id');
        $entry = SssEnrollment::find($id);

        if ($entry === null) {
            $this->back('/bc/sss', 'danger', 'That entry could not be found.');
        }

        $this->agentInScope((int) $entry['agent_id']);

        if (!$request->isPost()) {
            $this->view($request, 'bc/sss/form', [
                'title' => 'Edit SSS enrolment',
                'entry' => $entry,
                'schemeFields' => SssEnrollment::schemeFields(),
                'agents' => User::agents(Auth::scopedBranchId()),
            ]);
        }

        $validator = $this->validate($request, true);
        if ($validator->fails()) {
            $this->backWithErrors('/bc/sss/' . $id . '/edit', $validator->errors(), $request->all());
        }

        // Agent and date stay fixed: changing either would move a day's figures onto
        // a different day or a different person, and the unique key exists to stop
        // exactly that happening by accident.
        $data = $this->payload($request);

        SssEnrollment::update($id, $data);

        Logger::auditDiff(
            'sss_enrollment',
            $id,
            $entry,
            $data,
            sprintf(
                'Updated SSS enrolment for %s on %s',
                (string) $entry['agent_name'],
                (string) $entry['enrollment_date'],
            ),
        );

        $this->back('/bc/sss', 'success', 'Enrolment updated.');
    }

    public function delete(Request $request): void
    {
        $this->guard($request, 'sss.manage');

        $id = $request->paramInt('id');
        $entry = SssEnrollment::find($id);

        if ($entry === null) {
            $this->back('/bc/sss', 'danger', 'That entry could not be found.');
        }

        $this->agentInScope((int) $entry['agent_id']);

        SssEnrollment::delete($id);

        Logger::audit(
            'delete',
            'sss_enrollment',
            $id,
            $entry,
            null,
            sprintf(
                'Deleted SSS enrolment for %s on %s',
                (string) $entry['agent_name'],
                (string) $entry['enrollment_date'],
            ),
        );

        $this->back('/bc/sss', 'success', 'Entry deleted.');
    }

    // -----------------------------------------------------------------------

    private function validate(Request $request, bool $isEdit = false): Validator
    {
        $rules = [];
        $labels = [];

        if (!$isEdit) {
            $rules['agent_id'] = 'required|integer|exists:users,id';
            $rules['enrollment_date'] = 'required|date';
            $labels['agent_id'] = 'Agent';
            $labels['enrollment_date'] = 'Date';
        }

        foreach (SssEnrollment::schemeFields() as $field => $label) {
            $rules[$field] = 'nullable|integer|min_value:0|max_value:999';
            $labels[$field] = $label;
        }

        $rules['remarks'] = 'nullable|max:500';

        return Validator::make($request->all(), $rules, $labels);
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $data = [];

        foreach (array_keys(SssEnrollment::schemeFields()) as $field) {
            $data[$field] = max(0, $request->int($field));
        }

        $data['remarks'] = $request->nullableStr('remarks');

        return $data;
    }

    /**
     * The agent record, having confirmed the current user may touch it.
     *
     * @return array<string,mixed>
     */
    private function agentInScope(int $agentId): array
    {
        $agent = User::find($agentId);

        if ($agent === null || ($agent['branch_id'] ?? null) === null) {
            $this->back('/bc/sss', 'danger', 'That agent has no branch, so enrolment cannot be attributed.');
        }

        $scoped = Auth::scopedBranchId();

        if ($scoped !== null && (int) $agent['branch_id'] !== $scoped) {
            $this->back('/bc/sss', 'danger', 'That agent is not in your branch.');
        }

        return $agent;
    }
}
