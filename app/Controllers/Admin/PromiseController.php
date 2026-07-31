<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Models\Branch;
use App\Models\Promise;
use App\Models\User;

final class PromiseController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'promises.view');

        $scoped = Auth::scopedBranchId();

        $filters = [
            'branch_id' => $this->branchFilter($request),
            'agent_id'  => $this->agentFilter($request),
            'status'    => $request->str('status'),
            'date_from' => $request->str('date_from'),
            'date_to'   => $request->str('date_to'),
            'search'    => $request->str('search'),
        ];

        $promises = Promise::paginate($filters, $request->page(), $this->perPage($request));

        $this->view($request, 'promises/index', [
            'title'    => 'Promises',
            'promises' => $promises,
            'filters'  => $filters,
            'counts'   => Promise::statusCounts($filters['branch_id']),
            'branches' => Branch::options($scoped),
            'agents'   => User::agents($scoped ?? ($filters['branch_id'] ?? null)),
        ]);
    }

    /**
     * Marks a promise kept / broken / cancelled.
     * A broken promise pushes the lead back into follow-up (see Promise::settle).
     */
    public function settle(Request $request): void
    {
        $this->guard($request, 'promises.update');

        $id = $request->paramInt('id');
        $status = $request->str('status');
        $notes = $request->nullableStr('notes');

        $returnTo = $request->str('return_to');
        $redirect = str_starts_with($returnTo, '/') ? $returnTo : '/promises';

        $promise = Promise::find($id);
        if ($promise === null) {
            $this->back($redirect, 'danger', 'That promise could not be found.');
        }

        Auth::assertBranchAccess((int) $promise['branch_id']);

        if (!in_array($status, ['kept', 'broken', 'cancelled'], true)) {
            $this->back($redirect, 'danger', 'Choose a valid outcome for the promise.');
        }

        if ((string) $promise['status'] !== 'pending') {
            $this->back($redirect, 'warning', 'That promise has already been settled.');
        }

        $user = Auth::user();
        $settled = Promise::settle($id, $status, Auth::id(), (string) ($user['name'] ?? 'System'), $notes);

        if (!$settled) {
            $this->back($redirect, 'danger', 'The promise could not be updated.');
        }

        $this->back($redirect, 'success', sprintf(
            'Promise of %s marked as %s.',
            money($promise['promise_amount']),
            $status
        ));
    }
}
