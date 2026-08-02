<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Validator;
use App\Models\Branch;

final class BranchController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'branches.view');

        [$sortBy, $sortDir] = $request->sort(Branch::SORTABLE, 'name', 'ASC');

        $branches = Branch::paginate(
            $request->str('search'),
            $request->str('status'),
            $sortBy,
            $sortDir,
            $request->page(),
            $this->perPage($request)
        );

        $this->view($request, 'branches/index', [
            'title'    => 'Branches',
            'branches' => $branches,
            'search'   => $request->str('search'),
            'status'   => $request->str('status'),
            'sortBy'   => $sortBy,
            'sortDir'  => $sortDir,
        ]);
    }

    public function create(Request $request): void
    {
        $this->guard($request, 'branches.create');

        if (!$request->isPost()) {
            $this->view($request, 'branches/form', [
                'title'  => 'Add branch',
                'branch' => null,
            ]);
        }

        $validator = $this->validate($request, null);
        if ($validator->fails()) {
            $this->backWithErrors('/branches/create', $validator->errors(), $request->all());
        }

        $data = $this->payload($request);
        $id = Branch::create($data);

        Logger::audit('create', 'branch', $id, null, $data, sprintf('Created branch %s', $data['name']));

        $this->back('/branches', 'success', sprintf('Branch "%s" created.', e($data['name'])));
    }

    public function edit(Request $request): void
    {
        $this->guard($request, 'branches.update');

        $id = $request->paramInt('id');
        $branch = Branch::find($id);

        if ($branch === null) {
            $this->back('/branches', 'danger', 'That branch could not be found.');
        }

        if (!$request->isPost()) {
            $this->view($request, 'branches/form', [
                'title'  => 'Edit branch',
                'branch' => $branch,
            ]);
        }

        $validator = $this->validate($request, $id);
        if ($validator->fails()) {
            $this->backWithErrors('/branches/' . $id . '/edit', $validator->errors(), $request->all());
        }

        $data = $this->payload($request);
        Branch::update($id, $data);

        Logger::auditDiff('branch', $id, $branch, $data, sprintf('Updated branch %s', $data['name']));

        $this->back('/branches', 'success', sprintf('Branch "%s" updated.', e($data['name'])));
    }

    public function delete(Request $request): void
    {
        $this->guard($request, 'branches.delete');

        $id = $request->paramInt('id');
        $branch = Branch::find($id);

        if ($branch === null) {
            $this->back('/branches', 'danger', 'That branch could not be found.');
        }

        // Blocked while users, leads or customers still reference the branch, so
        // the operator gets a clear reason instead of a foreign-key error.
        $check = Branch::deletable($id);
        if (!$check['ok']) {
            $this->back('/branches', 'danger', 'Cannot delete this branch: ' . e($check['reason']));
        }

        Branch::delete($id);
        Logger::audit('delete', 'branch', $id, $branch, null, sprintf('Deleted branch %s', (string) $branch['name']));

        $this->back('/branches', 'success', sprintf('Branch "%s" deleted.', e((string) $branch['name'])));
    }

    // -----------------------------------------------------------------------

    private function validate(Request $request, ?int $ignoreId): Validator
    {
        $uniqueRule = 'required|max:30|unique:branches,branch_code' . ($ignoreId === null ? '' : ',' . $ignoreId);

        return Validator::make($request->all(), [
            'branch_code' => $uniqueRule,
            'name'        => 'required|max:150',
            'district'    => 'nullable|max:100',
            'state'       => 'nullable|max:100',
            'pincode'     => 'nullable|regex:/^\d{6}$/',
            'regional_office' => 'nullable|max:150',
            'zone'            => 'nullable|max:150',
            'status'      => 'required|in:active,inactive',
        ], [
            'branch_code'     => 'Branch code',
            'pincode'         => 'PIN code',
            'regional_office' => 'Regional office',
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        return [
            'branch_code' => strtoupper($request->str('branch_code')),
            'name'        => $request->str('name'),
            'district'    => $request->nullableStr('district'),
            'state'       => $request->nullableStr('state'),
            'pincode'     => $request->nullableStr('pincode'),
            // Printed at the top of every field visit report. Held once here so an
            // agent does not spell the same regional office four different ways.
            'regional_office' => $request->nullableStr('regional_office'),
            'zone'            => $request->nullableStr('zone'),
            'status'      => $request->str('status') === 'inactive' ? 'inactive' : 'active',
        ];
    }
}
