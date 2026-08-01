<?php
/**
 * @var list<array<string,mixed>>  $rows
 * @var array<string,array{weight:float,divisor:float,label:string}> $weights
 * @var string                     $from
 * @var string                     $to
 * @var int|null                   $branchId
 * @var list<array<string,mixed>>  $branches
 * @var array<string,float|int>    $totals
 */

$query = ['from' => $from, 'to' => $to];
if ($branchId !== null) {
    $query['branch_id'] = $branchId;
}
$queryString = http_build_query($query);

/** Standing drives the row tint. An agent on a final warning should be visible at a glance. */
$standingBadge = static function (?string $status): string {
    return match ($status) {
        'final_warning' => 'badge-legal',
        'warning_2'     => 'badge-pending',
        'warning_1'     => 'badge-followup',
        default         => 'badge-visited',
    };
};

$standingLabel = static function (?string $status): string {
    return match ($status) {
        'final_warning' => 'Final warning',
        'warning_2'     => 'Warning 2',
        'warning_1'     => 'Warning 1',
        default         => 'Normal',
    };
};
?>

<div class="lrms-page-head">
    <div>
        <h1>BC summary report</h1>
        <p>Activity, weighted score and standing for every agent in the period</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(url('/bc/scorecard/export') . '?' . $queryString . '&format=excel') ?>"
           class="btn btn-outline-secondary btn-sm"><?= icon('download') ?> Excel</a>
        <a href="<?= e(url('/bc/scorecard/export') . '?' . $queryString . '&format=pdf') ?>"
           class="btn btn-outline-secondary btn-sm"><?= icon('file') ?> PDF</a>
    </div>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/bc/scorecard')) ?>">
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="sc-from">From</label>
                    <input type="date" class="form-control" id="sc-from" name="from" value="<?= e($from) ?>">
                </div>
                <div>
                    <label class="form-label" for="sc-to">To</label>
                    <input type="date" class="form-control" id="sc-to" name="to" value="<?= e($to) ?>">
                </div>
                <?php if (count($branches) > 1): ?>
                    <div>
                        <label class="form-label" for="sc-branch">Branch</label>
                        <select class="form-select" id="sc-branch" name="branch_id" data-auto-submit>
                            <option value="">All branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $branch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Apply</button>
                    <a href="<?= e(url('/bc/scorecard')) ?>" class="btn btn-outline-secondary">This month</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lrms-card">
    <?php if ($rows === []): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'  => 'No agents to score',
            'message'  => 'No active BC/DC agents were found for this branch, so there is nothing to rank.',
            'iconName' => 'chart',
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th class="text-end">#</th>
                        <th>Agent</th>
                        <th>Branch</th>
                        <th class="text-end">Allocated</th>
                        <th class="text-end">Visits</th>
                        <th class="text-end">Contacts</th>
                        <th class="text-end">PTP</th>
                        <th class="text-end">NPA recovery</th>
                        <th class="text-end">OD-2</th>
                        <th class="text-end">APY</th>
                        <th class="text-end">PMJJBY</th>
                        <th class="text-end">PMSBY</th>
                        <th class="text-end">PMJDY</th>
                        <th class="text-end">Score</th>
                        <th>Standing</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="num" style="font-weight:700"><?= (int) $row['rank'] ?></td>
                            <td>
                                <div style="font-weight:550"><?= e((string) $row['agent_name']) ?></div>
                                <div class="font-mono text-muted" style="font-size:.75rem">
                                    <?= e((string) $row['employee_code']) ?>
                                </div>
                            </td>
                            <td style="font-size:.8125rem"><?= nullable($row['branch_name']) ?></td>
                            <td class="num"><?= e(number_format((int) $row['allocated'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['visits'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['contacts'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['ptp'])) ?></td>
                            <td class="num"><?= rupees($row['npa_recovery'], false) ?></td>
                            <td class="num"><?= e(number_format((int) $row['od2_renewal'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['apy'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['pmjjby'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['pmsby'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['pmjdy'])) ?></td>
                            <td class="num" style="font-weight:700"><?= e(number_format((float) $row['total_score'], 2)) ?></td>
                            <td>
                                <span class="lrms-badge <?= $standingBadge($row['dashboard_status'] ?? null) ?>">
                                    <?= e($standingLabel($row['dashboard_status'] ?? null)) ?>
                                </span>
                                <?php if ((int) ($row['escalation_flag'] ?? 0) === 1): ?>
                                    <span class="lrms-badge badge-legal" title="Escalated beyond the branch">
                                        <?= icon('alert') ?> Escalated
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" class="text-end">Total</th>
                        <th class="num"><?= e(number_format((int) $totals['allocated'])) ?></th>
                        <th class="num"><?= e(number_format((int) $totals['visits'])) ?></th>
                        <th class="num"><?= e(number_format((int) $totals['contacts'])) ?></th>
                        <th class="num"><?= e(number_format((int) $totals['ptp'])) ?></th>
                        <th class="num"><?= rupees($totals['npa_recovery'], false) ?></th>
                        <th class="num"><?= e(number_format((int) $totals['od2_renewal'])) ?></th>
                        <th class="num"><?= e(number_format((int) $totals['apy'])) ?></th>
                        <th class="num"><?= e(number_format((int) $totals['pmjjby'])) ?></th>
                        <th class="num"><?= e(number_format((int) $totals['pmsby'])) ?></th>
                        <th class="num"><?= e(number_format((int) $totals['pmjdy'])) ?></th>
                        <th class="num"><?= e(number_format((float) $totals['total_score'], 2)) ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($weights !== []): ?>
    <div class="lrms-card mt-3">
        <div class="lrms-card-body">
            <h2 class="h6 mb-2">How the score is calculated</h2>
            <p class="text-muted mb-2" style="font-size:.8125rem">
                Agents are ranked on this arithmetic, so it is printed rather than hidden.
                Equal scores share a rank &mdash; two agents on identical figures are not
                placed above one another.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($weights as $metric => $weight): ?>
                    <span class="lrms-badge badge-pending" style="font-weight:500">
                        <?= e((string) $weight['label']) ?>:
                        <?php if ((float) $weight['divisor'] > 1.0): ?>
                            <?= e(number_format((float) $weight['weight'], 2)) ?>
                            per <?= rupees($weight['divisor'], false) ?>
                        <?php else: ?>
                            <?= e(number_format((float) $weight['weight'], 2)) ?> each
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
