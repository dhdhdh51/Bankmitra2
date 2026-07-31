<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;

final class ReportController extends Controller
{
    /** The list of available report types. */
    public function index(Request $request): void
    {
        $this->auth($request, 'reports.view');

        $types = [];
        foreach (ReportService::TYPES as $key => $meta) {
            $types[] = [
                'type'        => $key,
                'label'       => $meta['label'],
                'description' => $meta['description'],
            ];
        }

        Response::success($types);
    }

    /** Report data as JSON. */
    public function show(Request $request): void
    {
        $this->auth($request, 'reports.view');

        $type = (string) $request->param('type');
        if (!ReportService::isValidType($type)) {
            Response::notFound('Unknown report type.');
        }

        $report = ReportService::build($type, $this->filters($request));

        Response::success([
            'type'      => $report['type'],
            'title'     => $report['title'],
            'subtitle'  => $report['subtitle'],
            'columns'   => $report['columns'],
            'rows'      => $report['rows'],
            'totals'    => $report['totals'],
            'summary'   => $report['summary'],
            'row_count' => count($report['rows']),
        ]);
    }

    /** Excel or PDF export. */
    public function export(Request $request): void
    {
        $this->auth($request, 'reports.export');

        $type = (string) $request->param('type');
        if (!ReportService::isValidType($type)) {
            Response::notFound('Unknown report type.');
        }

        $format = strtolower($request->str('format', 'excel'));
        $report = ReportService::build($type, $this->filters($request));

        [$content, $filename, $mime] = $format === 'pdf'
            ? ReportService::toPdf($report)
            : ReportService::toExcel($report);

        $this->logActivity('export', 'API', sprintf(
            'Exported %s report as %s (%d row(s))',
            $type,
            $format === 'pdf' ? 'PDF' : 'Excel',
            count($report['rows'])
        ));

        Response::download($content, $filename, $mime);
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(Request $request): array
    {
        $scoped = Auth::scopedBranchId();

        $filters = [
            'date'           => $request->str('date', date('Y-m-d')),
            'date_from'      => $request->str('date_from'),
            'date_to'        => $request->str('date_to'),
            'week'           => $request->str('week'),
            'month'          => $request->str('month', date('Y-m')),
            'branch_id'      => $scoped ?? ($request->nullableInt('branch_id') ?: null),
            'status'         => $request->str('status'),
            'village'        => $request->str('village'),
            'loan_type'      => $request->str('loan_type'),
            'promise_status' => $request->str('promise_status'),
            'npa_only'       => $request->bool('npa_only'),
        ];

        // An agent may only ever report on their own activity.
        if (Auth::isAgent()) {
            $filters['agent_id'] = Auth::id();
            return $filters;
        }

        $agentId = $request->nullableInt('agent_id');
        if ($agentId !== null && $agentId > 0) {
            $filters['agent_id'] = $agentId;
        }

        return $filters;
    }
}
