<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\User;
use App\Services\ReportService;

final class ReportController extends Controller
{
    /** The report picker grid. */
    public function index(Request $request): void
    {
        $this->guard($request, 'reports.view');

        $this->view($request, 'reports/index', [
            'title' => 'Reports',
            'types' => ReportService::TYPES,
        ]);
    }

    public function show(Request $request): void
    {
        $this->guard($request, 'reports.view');

        $type = (string) $request->param('type');
        if (!ReportService::isValidType($type)) {
            $this->back('/reports', 'danger', 'Unknown report type.');
        }

        $filters = $this->filters($request);
        $report = ReportService::build($type, $filters);

        $this->logView('Reports', sprintf('Viewed %s report', $type));

        $scoped = Auth::scopedBranchId();

        $this->view($request, 'reports/show', [
            'title'     => (string) ReportService::TYPES[$type]['label'],
            'type'      => $type,
            'report'    => $report,
            'filters'   => $filters,
            'branches'  => Branch::options($scoped),
            'agents'    => User::agents($scoped ?? ($filters['branch_id'] ?? null)),
            'villages'  => LoanAccount::villages($scoped),
            'loanTypes' => LoanAccount::loanTypes($scoped),
            'types'     => ReportService::TYPES,
        ]);
    }

    /** Excel / PDF export of the currently filtered report. */
    public function export(Request $request): void
    {
        $this->guard($request, 'reports.export');

        $type = (string) $request->param('type');
        if (!ReportService::isValidType($type)) {
            $this->back('/reports', 'danger', 'Unknown report type.');
        }

        $format = strtolower($request->str('format', 'excel'));
        $report = ReportService::build($type, $this->filters($request));

        [$content, $filename, $mime] = $format === 'pdf'
            ? ReportService::toPdf($report)
            : ReportService::toExcel($report);

        $this->logExport('Reports', sprintf(
            'Exported %s report as %s (%d row(s))',
            $type,
            $format === 'pdf' ? 'PDF' : 'Excel',
            count($report['rows'])
        ));

        Response::download($content, $filename, $mime);
    }

    // -----------------------------------------------------------------------

    /**
     * Report filters. branch_id/agent_id go through the base-class helpers so a
     * branch manager cannot widen their scope with a query parameter.
     *
     * @return array<string,mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'date'           => $request->str('date', date('Y-m-d')),
            'date_from'      => $request->str('date_from'),
            'date_to'        => $request->str('date_to'),
            'week'           => $request->str('week'),
            'month'          => $request->str('month', date('Y-m')),
            'branch_id'      => $this->branchFilter($request),
            'agent_id'       => $this->agentFilter($request),
            'status'         => $request->str('status'),
            'village'        => $request->str('village'),
            'loan_type'      => $request->str('loan_type'),
            'promise_status' => $request->str('promise_status'),
            'npa_only'       => $request->bool('npa_only'),
        ];
    }
}
