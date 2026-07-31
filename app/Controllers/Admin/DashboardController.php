<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Settings;
use App\Models\Branch;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'dashboard.view');

        $scoped = Auth::scopedBranchId();

        // A super admin may focus the dashboard on a single branch.
        $branchId = $scoped ?? ($request->nullableInt('branch_id') ?: null);

        $data = DashboardService::build($branchId);

        $this->view($request, 'dashboard/index', [
            'title'            => 'Dashboard',
            'data'             => $data,
            'branchId'         => $branchId,
            'branches'         => $scoped === null ? Branch::allActive() : [],
            'missingSettings'  => Auth::can('settings.view') ? Settings::missingRequired() : [],
        ]);
    }
}
