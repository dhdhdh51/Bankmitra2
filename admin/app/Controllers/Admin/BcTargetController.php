<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Validator;
use App\Models\BcTarget;
use App\Models\Branch;
use App\Models\User;

/**
 * Monthly targets for BC/DC agents.
 *
 * These numbers are not decoration: `cron/bc-warning-check.php` measures each
 * agent's day against them and raises L1/L2/L3 warnings that escalate to a
 * supervisor, then a service provider, then the regional office. So a typo here
 * becomes a warning letter about a target nobody set. Two consequences shape this
 * controller:
 *
 *   A target month is unique per agent. Trying to add a second one redirects to
 *   editing the existing row instead of failing on a database constraint, because
 *   "you already set this, here it is" is the actual answer to what the user was
 *   trying to do.
 *
 *   Deleting is refused once the month has been assessed. Warnings reference the
 *   target they were measured against; removing it leaves an agent carrying a
 *   warning that can no longer be justified or disputed.
 */
final class BcTargetController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'bc_targets.view');

        [$sortBy, $sortDir] = $request->sort(BcTarget::SORTABLE, 'target_month', 'DESC');

        $branchId = $this->branchFilter($request);
        $agentId = $this->agentFilter($request);
        $month = $request->str('month');

        $targets = BcTarget::paginate(
            $request->str('search'),
            $month,
            $branchId,
            $agentId,
            $sortBy,
            $sortDir,
            $request->page(),
            $this->perPage($request),
        );

        $this->view($request, 'bc/targets/index', [
            'title' => 'BC targets',
            'targets' => $targets,
            'countFields' => BcTarget::countFields(),
            'search' => $request->str('search'),
            'month' => $month,
            'months' => BcTarget::months(Auth::scopedBranchId()),
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
        $this->guard($request, 'bc_targets.manage');

        if (!$request->isPost()) {
            $this->view($request, 'bc/targets/form', [
                'title' => 'Set BC targets',
                'target' => null,
                'countFields' => BcTarget::countFields(),
                'agents' => User::agents(Auth::scopedBranchId()),
            ]);
        }

        $validator = $this->validate($request);
        if ($validator->fails()) {
            $this->backWithErrors('/bc/targets/create', $validator->errors(), $request->all());
        }

        $agentId = $request->int('agent_id');
        $this->assertAgentInScope($agentId);

        $month = BcTarget::parseMonth($request->str('target_month'));

        if ($month === null) {
            $this->backWithErrors(
                '/bc/targets/create',
                ['target_month' => ['Target month must be a real month, for example 2026-08.']],
                $request->all(),
            );
        }

        // The unique key would reject this anyway, but with an opaque error. Sending
        // the user to the row they already created is what they actually wanted.
        $existing = BcTarget::findForMonth($agentId, $month);
        if ($existing !== null) {
            $this->back(
                '/bc/targets/' . (int) $existing['id'] . '/edit',
                'warning',
                'Targets for that agent and month already exist - edit them here.',
            );
        }

        $data = $this->payload($request) + [
            'agent_id' => $agentId,
            'target_month' => $month,
            'set_by' => Auth::id(),
        ];

        $id = BcTarget::create($data);

        Logger::audit(
            'create',
            'bc_target',
            $id,
            null,
            $data,
            sprintf('Set BC targets for agent #%d, %s', $agentId, date('F Y', (int) strtotime($month))),
        );

        $this->back('/bc/targets', 'success', 'Targets saved.');
    }

    public function edit(Request $request): void
    {
        $this->guard($request, 'bc_targets.manage');

        $id = $request->paramInt('id');
        $target = BcTarget::find($id);

        if ($target === null) {
            $this->back('/bc/targets', 'danger', 'That target could not be found.');
        }

        $this->assertAgentInScope((int) $target['agent_id']);

        if (!$request->isPost()) {
            $this->view($request, 'bc/targets/form', [
                'title' => 'Edit BC targets',
                'target' => $target,
                'countFields' => BcTarget::countFields(),
                'agents' => User::agents(Auth::scopedBranchId()),
            ]);
        }

        $validator = $this->validate($request, true);
        if ($validator->fails()) {
            $this->backWithErrors('/bc/targets/' . $id . '/edit', $validator->errors(), $request->all());
        }

        // The agent and the month are not editable. Moving a target row to a
        // different agent would silently re-attribute every warning already raised
        // against it; setting a different agent's targets is a new row.
        $data = $this->payload($request) + ['set_by' => Auth::id()];

        BcTarget::update($id, $data);

        Logger::auditDiff(
            'bc_target',
            $id,
            $target,
            $data,
            sprintf(
                'Updated BC targets for %s, %s',
                (string) $target['agent_name'],
                date('F Y', (int) strtotime((string) $target['target_month'])),
            ),
        );

        $this->back('/bc/targets', 'success', 'Targets updated.');
    }

    public function delete(Request $request): void
    {
        $this->guard($request, 'bc_targets.manage');

        $id = $request->paramInt('id');
        $target = BcTarget::find($id);

        if ($target === null) {
            $this->back('/bc/targets', 'danger', 'That target could not be found.');
        }

        $this->assertAgentInScope((int) $target['agent_id']);

        $check = BcTarget::deletable($id);
        if (!$check['ok']) {
            $this->back('/bc/targets', 'danger', 'Cannot delete: ' . e($check['reason']));
        }

        BcTarget::delete($id);

        Logger::audit(
            'delete',
            'bc_target',
            $id,
            $target,
            null,
            sprintf('Deleted BC targets for %s', (string) $target['agent_name']),
        );

        $this->back('/bc/targets', 'success', 'Target removed.');
    }

    // -----------------------------------------------------------------------

    private function validate(Request $request, bool $isEdit = false): Validator
    {
        $rules = [];
        $labels = [];

        if (!$isEdit) {
            $rules['agent_id'] = 'required|integer|exists:users,id';
            // Not `date`: that rule insists on YYYY-MM-DD, while the month picker
            // sends YYYY-MM. The shape is checked here and the value is parsed by
            // BcTarget::parseMonth(), which is the only thing that knows both forms.
            // The pattern deliberately contains no alternation, because rules are
            // split on `|`.
            $rules['target_month'] = 'required|regex:/^\d{4}-\d{2}(-\d{2})?$/';
            $labels['agent_id'] = 'Agent';
            $labels['target_month'] = 'Target month';
        }

        foreach (array_keys(BcTarget::countFields()) as $field) {
            // max_value is not arbitrary tidiness: a mistyped 30 as 3000 produces a
            // target no human can hit, and the warning cron would then escalate that
            // agent to the regional office every single day.
            $rules[$field] = 'nullable|integer|min_value:0|max_value:9999';
        }

        $rules['npa_recovery_target'] = 'nullable|numeric|min_value:0';
        $labels['npa_recovery_target'] = 'NPA recovery target';

        foreach (BcTarget::countFields() as $field => $label) {
            $labels[$field] = $label;
        }

        return Validator::make($request->all(), $rules, $labels);
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $data = [];

        foreach (array_keys(BcTarget::countFields()) as $field) {
            $data[$field] = max(0, $request->int($field));
        }

        $data['npa_recovery_target'] = round(max(0.0, $request->float('npa_recovery_target')), 2);

        return $data;
    }

    /**
     * A branch manager may only set targets for their own agents.
     *
     * Checked against the agent's branch rather than the submitted branch_id,
     * because the submitted value is the thing under the user's control.
     */
    private function assertAgentInScope(int $agentId): void
    {
        $scoped = Auth::scopedBranchId();

        if ($scoped === null) {
            return;
        }

        $agent = User::find($agentId);

        if ($agent === null || (int) ($agent['branch_id'] ?? 0) !== $scoped) {
            $this->back('/bc/targets', 'danger', 'That agent is not in your branch.');
        }
    }
}
