<?php
/**
 * Single report view. Renders any of the 8 report types from the uniform shape
 * returned by ReportService, so one template serves them all.
 *
 * @var string                    $type
 * @var array<string,mixed>       $report
 * @var array<string,mixed>       $filters
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $agents
 * @var list<string>              $villages
 * @var list<string>              $loanTypes
 * @var array<string,array{label:string,description:string}> $types
 */

use App\Core\Url;

$columns = $report['columns'];
$queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');

/** Which date controls this report actually uses. */
$dateMode = match ($type) {
    'daily'   => 'single',
    'weekly'  => 'week',
    'monthly' => 'month',
    default   => 'range',
};

$formatCell = static function (mixed $value, string $columnType): string {
    if ($value === null || $value === '') {
        return $columnType === 'text' ? '<span class="text-muted">—</span>' : '';
    }
    return match ($columnType) {
        'money'   => e(money($value)),
        'percent' => e(number_format((float) $value, 1)) . '%',
        'number'  => e(number_format((float) $value, (is_float($value) && $value !== floor((float) $value)) ? 1 : 0)),
        'date'    => e(fmt_date((string) $value)),
        default   => e($value),
    };
};
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/reports')) ?>" class="text-muted">Reports</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted"><?= e($types[$type]['label']) ?></span>
        </nav>
        <h1><?= e($report['title']) ?></h1>
        <p><?= e($report['subtitle']) ?></p>
    </div>

    <div class="d-flex gap-2 flex-wrap no-print">
        <?php if (can('reports.export')): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= e(url('/reports/' . $type . '/export') . '?' . $queryString . '&format=excel') ?>">
                <?= icon('excel') ?> Excel
            </a>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= e(url('/reports/' . $type . '/export') . '?' . $queryString . '&format=pdf') ?>">
                <?= icon('pdf') ?> PDF
            </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <?= icon('print') ?> Print
        </button>
    </div>
</div>

<!-- Report switcher -->
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
    <?php foreach ($types as $key => $meta): ?>
        <a class="lrms-chip<?= $key === $type ? ' active' : '' ?>" href="<?= e(url('/reports/' . $key)) ?>">
            <?= e($meta['label']) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ==================== Filters ==================== -->
<div class="lrms-card mb-3 no-print">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/reports/' . $type)) ?>">
            <div class="lrms-filters">
                <?php if ($dateMode === 'single'): ?>
                    <div>
                        <label class="form-label" for="r-date">Date</label>
                        <input type="date" class="form-control" id="r-date" name="date"
                               value="<?= e($filters['date']) ?>">
                    </div>
                <?php elseif ($dateMode === 'week'): ?>
                    <div>
                        <label class="form-label" for="r-week">Week</label>
                        <input type="week" class="form-control" id="r-week" name="week"
                               value="<?= e($filters['week']) ?>">
                        <div class="form-text">Monday to Sunday</div>
                    </div>
                <?php elseif ($dateMode === 'month'): ?>
                    <div>
                        <label class="form-label" for="r-month">Month</label>
                        <input type="month" class="form-control" id="r-month" name="month"
                               value="<?= e($filters['month']) ?>">
                    </div>
                <?php else: ?>
                    <div>
                        <label class="form-label" for="r-from">From</label>
                        <input type="date" class="form-control" id="r-from" name="date_from"
                               value="<?= e($filters['date_from']) ?>">
                    </div>
                    <div>
                        <label class="form-label" for="r-to">To</label>
                        <input type="date" class="form-control" id="r-to" name="date_to"
                               value="<?= e($filters['date_to']) ?>">
                    </div>
                <?php endif; ?>

                <?php if (count($branches) > 1): ?>
                    <div>
                        <label class="form-label" for="r-branch">Branch</label>
                        <select class="form-select" id="r-branch" name="branch_id">
                            <option value="">All branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= e((string) $branch['id']) ?>"
                                    <?= ($filters['branch_id'] ?? null) === (int) $branch['id'] ? 'selected' : '' ?>>
                                    <?= e($branch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array($type, ['daily', 'weekly', 'monthly', 'agent', 'promise', 'branch'], true)): ?>
                    <div>
                        <label class="form-label" for="r-agent">Agent</label>
                        <select class="form-select" id="r-agent" name="agent_id">
                            <option value="">All agents</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?= e((string) $agent['id']) ?>"
                                    <?= ($filters['agent_id'] ?? null) === (int) $agent['id'] ? 'selected' : '' ?>>
                                    <?= e($agent['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array($type, ['village', 'branch', 'loan-type'], true)): ?>
                    <div>
                        <label class="form-label" for="r-village">Village</label>
                        <select class="form-select" id="r-village" name="village">
                            <option value="">All villages</option>
                            <?php foreach ($villages as $village): ?>
                                <option value="<?= e($village) ?>"
                                    <?= $filters['village'] === $village ? 'selected' : '' ?>><?= e($village) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array($type, ['loan-type', 'branch', 'village'], true)): ?>
                    <div>
                        <label class="form-label" for="r-loan-type">Loan type</label>
                        <select class="form-select" id="r-loan-type" name="loan_type">
                            <option value="">All types</option>
                            <?php foreach ($loanTypes as $loanType): ?>
                                <option value="<?= e($loanType) ?>"
                                    <?= $filters['loan_type'] === $loanType ? 'selected' : '' ?>><?= e($loanType) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($type === 'promise'): ?>
                    <div>
                        <label class="form-label" for="r-promise-status">Promise status</label>
                        <select class="form-select" id="r-promise-status" name="promise_status">
                            <option value="">All</option>
                            <?php foreach (['pending' => 'Pending', 'kept' => 'Kept', 'broken' => 'Broken', 'cancelled' => 'Cancelled'] as $key => $label): ?>
                                <option value="<?= e($key) ?>"
                                    <?= $filters['promise_status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Apply</button>
                    <a href="<?= e(url('/reports/' . $type)) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ==================== Summary ==================== -->
<?php if (!empty($report['summary'])): ?>
    <div class="lrms-stats">
        <?php foreach ($report['summary'] as $item): ?>
            <div class="lrms-stat lrms-stat-accent">
                <div class="lrms-stat-label"><?= e($item['label']) ?></div>
                <div class="lrms-stat-value sm"><?= e($item['value']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Print-only header, since the sidebar and page head are hidden -->
<div class="print-only mb-3">
    <h2 style="margin:0;font-size:15pt"><?= e($report['title']) ?></h2>
    <p style="margin:2px 0 0;font-size:9pt;color:#4b5563"><?= e($report['subtitle']) ?></p>
</div>

<!-- ==================== Table ==================== -->
<div class="lrms-card">
    <?php if ($report['rows'] === []): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'  => 'No data for these filters',
            'message'  => 'Nothing matched the selected period and filters. Widen the date range or clear the filters.',
            'iconName' => 'reports',
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th class="<?= in_array((string) $column['type'], ['number', 'money', 'percent'], true) ? 'text-end' : '' ?>">
                                <?= e($column['label']) ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <?php $isNumeric = in_array((string) $column['type'], ['number', 'money', 'percent'], true); ?>
                                <td class="<?= $isNumeric ? 'num' : '' ?>">
                                    <?= $formatCell($row[$column['key']] ?? null, (string) $column['type']) ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php if (!empty($report['totals'])): ?>
                    <tfoot>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <?php $isNumeric = in_array((string) $column['type'], ['number', 'money', 'percent'], true); ?>
                                <td class="<?= $isNumeric ? 'num' : '' ?>">
                                    <?= $formatCell($report['totals'][$column['key']] ?? null, (string) $column['type']) ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <div class="lrms-card-foot">
            <div class="lrms-pager">
                <div class="info">
                    <?= e(number_format(count($report['rows']))) ?> row(s)
                </div>
                <?php if (can('reports.export')): ?>
                    <div class="d-flex gap-2 no-print">
                        <a class="btn btn-outline-secondary btn-sm"
                           href="<?= e(url('/reports/' . $type . '/export') . '?' . $queryString . '&format=excel') ?>">
                            <?= icon('excel') ?> Export Excel
                        </a>
                        <a class="btn btn-outline-secondary btn-sm"
                           href="<?= e(url('/reports/' . $type . '/export') . '?' . $queryString . '&format=pdf') ?>">
                            <?= icon('pdf') ?> Export PDF
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
