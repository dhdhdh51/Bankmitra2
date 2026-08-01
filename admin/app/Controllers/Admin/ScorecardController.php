<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Branch;
use App\Services\BcPerformanceService;
use App\Services\ReportService;

/**
 * The BC summary scorecard: every agent's activity for a period, scored and ranked.
 *
 * Two things about this screen are deliberate and worth not undoing.
 *
 * RANKING IS DENSE. Equal scores share a rank, and the next distinct score takes
 * the following number. Competition ranking (1, 2, 2, 4) would tell two agents on
 * identical figures that one of them is behind the other, which is both false and
 * the kind of thing that ends up in a disciplinary conversation.
 *
 * THE WEIGHTS ARE VISIBLE. `score_weights` is what turns nine raw counts into one
 * number, and the number is used to rank people. A ranking whose arithmetic is
 * hidden is a ranking nobody can dispute, so the weights are printed alongside the
 * table and carried into the exports.
 */
final class ScorecardController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'scorecard.view');

        [$from, $to] = $this->period($request);
        $branchId = $this->branchFilter($request);

        $rows = BcPerformanceService::scorecard($from, $to, $branchId ?? Auth::scopedBranchId());

        $this->logView(
            'Scorecard',
            sprintf('Viewed BC scorecard %s to %s (%d agent(s))', $from, $to, count($rows)),
        );

        $this->view($request, 'bc/scorecard', [
            'title' => 'BC summary report',
            'rows' => $rows,
            'weights' => BcPerformanceService::weights(),
            'from' => $from,
            'to' => $to,
            'branchId' => $branchId,
            'branches' => Branch::options(Auth::scopedBranchId()),
            'totals' => $this->totals($rows),
        ]);
    }

    /**
     * PDF or Excel, built through the same report contract the other reports use so
     * the exporters do not need to know this screen exists.
     */
    public function export(Request $request): void
    {
        $this->guard($request, 'scorecard.view');

        // A separate permission would be tidier, but exporting a table somebody can
        // already read on screen is not a different capability - and reports.export
        // is about the reports module, not this one.
        $format = strtolower($request->str('format', 'excel')) === 'pdf' ? 'pdf' : 'excel';

        [$from, $to] = $this->period($request);
        $branchId = $this->branchFilter($request);

        $rows = BcPerformanceService::scorecard($from, $to, $branchId ?? Auth::scopedBranchId());
        $report = $this->report($rows, $from, $to);

        [$content, $filename, $mime] = $format === 'pdf'
            ? ReportService::toPdf($report)
            : ReportService::toExcel($report);

        $this->logExport(
            'Scorecard',
            sprintf('Exported BC scorecard %s to %s as %s (%d row(s))', $from, $to, $format, count($rows)),
        );

        Response::download($content, $filename, $mime);
    }

    // -----------------------------------------------------------------------

    /**
     * The reporting period, defaulting to the current month.
     *
     * A reversed range is swapped rather than rejected: somebody who typed the dates
     * the wrong way round wants the period between them, and an empty table with no
     * explanation looks like "no agents did anything".
     *
     * @return array{0:string,1:string}
     */
    private function period(Request $request): array
    {
        $from = $request->str('from') !== '' ? $request->str('from') : date('Y-m-01');
        $to = $request->str('to') !== '' ? $request->str('to') : date('Y-m-d');

        if (strtotime($from) === false) {
            $from = date('Y-m-01');
        }

        if (strtotime($to) === false) {
            $to = date('Y-m-d');
        }

        $from = date('Y-m-d', (int) strtotime($from));
        $to = date('Y-m-d', (int) strtotime($to));

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /**
     * Column totals for the footer row.
     *
     * total_score is summed, not averaged, and it is labelled as a sum in the view.
     * An average of a weighted score across a variable number of agents is a number
     * that reads like it means something and does not.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,float|int>
     */
    private function totals(array $rows): array
    {
        $columns = [
            'allocated', 'visits', 'contacts', 'ptp', 'npa_recovery',
            'od2_renewal', 'apy', 'pmjjby', 'pmsby', 'pmjdy', 'total_score',
        ];

        $totals = array_fill_keys($columns, 0);

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $totals[$column] += (float) ($row[$column] ?? 0);
            }
        }

        foreach ($totals as $column => $value) {
            $totals[$column] = $column === 'npa_recovery' || $column === 'total_score'
                ? round((float) $value, 2)
                : (int) $value;
        }

        return $totals;
    }

    /**
     * Shapes the scorecard into the report contract used by ReportService.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function report(array $rows, string $from, string $to): array
    {
        $columns = [
            ['key' => 'rank', 'label' => 'Rank', 'type' => 'number'],
            ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
            ['key' => 'agent_name', 'label' => 'Agent', 'type' => 'text'],
            ['key' => 'branch_name', 'label' => 'Branch', 'type' => 'text'],
            ['key' => 'allocated', 'label' => 'Allocated', 'type' => 'number'],
            ['key' => 'visits', 'label' => 'Visits', 'type' => 'number'],
            ['key' => 'contacts', 'label' => 'Contacts', 'type' => 'number'],
            ['key' => 'ptp', 'label' => 'PTP', 'type' => 'number'],
            ['key' => 'npa_recovery', 'label' => 'NPA recovery', 'type' => 'money'],
            ['key' => 'od2_renewal', 'label' => 'OD-2 renewals', 'type' => 'number'],
            ['key' => 'apy', 'label' => 'APY', 'type' => 'number'],
            ['key' => 'pmjjby', 'label' => 'PMJJBY', 'type' => 'number'],
            ['key' => 'pmsby', 'label' => 'PMSBY', 'type' => 'number'],
            ['key' => 'pmjdy', 'label' => 'PMJDY', 'type' => 'number'],
            ['key' => 'total_score', 'label' => 'Score', 'type' => 'number'],
            ['key' => 'dashboard_status', 'label' => 'Standing', 'type' => 'text'],
        ];

        $totals = $this->totals($rows);

        $summary = [
            ['label' => 'Agents', 'value' => (string) count($rows)],
            ['label' => 'Period', 'value' => date('d-m-Y', (int) strtotime($from)) . ' to ' . date('d-m-Y', (int) strtotime($to))],
            ['label' => 'Total visits', 'value' => (string) $totals['visits']],
            ['label' => 'Total PTP', 'value' => (string) $totals['ptp']],
        ];

        // The scoring rule travels with the export. A printed ranking handed to
        // somebody without it invites "why am I fourth" with no way to answer.
        foreach (BcPerformanceService::weights() as $metric => $weight) {
            $summary[] = [
                'label' => 'Weight: ' . $weight['label'],
                'value' => (float) $weight['divisor'] > 1.0
                    ? sprintf('%.2f per %s', (float) $weight['weight'], rupees((float) $weight['divisor']))
                    : sprintf('%.2f each', (float) $weight['weight']),
            ];
        }

        return [
            'type' => 'bc_scorecard',
            'title' => 'BC Summary Report',
            'subtitle' => sprintf(
                '%s to %s',
                date('d-m-Y', (int) strtotime($from)),
                date('d-m-Y', (int) strtotime($to)),
            ),
            'columns' => $columns,
            'rows' => $rows,
            'totals' => ['agent_name' => 'Total'] + $totals,
            'summary' => $summary,
            'landscape' => true,
        ];
    }
}
