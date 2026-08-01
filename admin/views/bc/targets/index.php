<?php
/**
 * @var \App\Core\Paginator        $targets
 * @var array<string,string>       $countFields
 * @var string                     $search
 * @var string                     $month
 * @var list<string>               $months
 * @var int|null                   $branchId
 * @var int|null                   $agentId
 * @var list<array<string,mixed>>  $branches
 * @var list<array<string,mixed>>  $agents
 * @var string                     $sortBy
 * @var string                     $sortDir
 */
?>

<div class="lrms-page-head">
    <div>
        <h1>BC targets</h1>
        <p>Monthly targets per agent &mdash; what the nightly warning check measures against</p>
    </div>
    <?php if (can('bc_targets.manage')): ?>
        <a href="<?= e(url('/bc/targets/create')) ?>" class="btn btn-primary btn-sm">
            <?= icon('plus') ?> Set targets
        </a>
    <?php endif; ?>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/bc/targets')) ?>">
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="t-search">Search</label>
                    <input type="search" class="form-control" id="t-search" name="search"
                           value="<?= e($search) ?>" placeholder="Agent name, employee or BC code">
                </div>
                <div>
                    <label class="form-label" for="t-month">Month</label>
                    <select class="form-select" id="t-month" name="month" data-auto-submit>
                        <option value="">All months</option>
                        <?php foreach ($months as $option): ?>
                            <option value="<?= e($option) ?>" <?= $month === $option ? 'selected' : '' ?>>
                                <?= e(date('F Y', (int) strtotime($option))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (count($branches) > 1): ?>
                    <div>
                        <label class="form-label" for="t-branch">Branch</label>
                        <select class="form-select" id="t-branch" name="branch_id" data-auto-submit>
                            <option value="">All branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $branch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div>
                    <label class="form-label" for="t-agent">Agent</label>
                    <select class="form-select" id="t-agent" name="agent_id" data-auto-submit>
                        <option value="">All agents</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= (int) $agent['id'] ?>" <?= $agentId === (int) $agent['id'] ? 'selected' : '' ?>>
                                <?= e((string) $agent['name']) ?> (<?= e((string) $agent['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Filter</button>
                    <a href="<?= e(url('/bc/targets')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lrms-card">
    <?php if ($targets->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'     => 'No targets set',
            'message'     => 'Until a month has targets, no warnings are raised for it - the nightly check treats a missing target as "not assessed" rather than as zero.',
            'iconName'    => 'reports',
            'actionLabel' => can('bc_targets.manage') ? 'Set targets' : null,
            'actionUrl'   => can('bc_targets.manage') ? url('/bc/targets/create') : null,
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th><?= sort_link('Month', 'target_month', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Agent', 'agent_name', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Code', 'employee_code', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Branch', 'branch_name', $sortBy, $sortDir) ?></th>
                        <?php foreach ($countFields as $field => $label): ?>
                            <th class="text-end" title="<?= e($label) ?>">
                                <?= e($field === 'daily_visit_target' ? 'Visits/day' : str_replace(['APY ', ' enrolments', ' accounts', 'OD-2 / CKCC '], '', $label)) ?>
                            </th>
                        <?php endforeach; ?>
                        <th class="text-end">NPA recovery</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($targets->items as $target): ?>
                        <tr>
                            <td style="font-weight:550">
                                <?= e(date('M Y', (int) strtotime((string) $target['target_month']))) ?>
                            </td>
                            <td style="font-weight:550"><?= e((string) $target['agent_name']) ?></td>
                            <td class="font-mono" style="font-size:.8125rem"><?= e((string) $target['employee_code']) ?></td>
                            <td style="font-size:.8125rem"><?= nullable($target['branch_name']) ?></td>
                            <?php foreach (array_keys($countFields) as $field): ?>
                                <td class="num"><?= e(number_format((int) $target[$field])) ?></td>
                            <?php endforeach; ?>
                            <td class="num"><?= rupees($target['npa_recovery_target'], false) ?></td>
                            <td class="text-end nowrap">
                                <?php if (can('bc_targets.manage')): ?>
                                    <a href="<?= e(url('/bc/targets/' . (int) $target['id'] . '/edit')) ?>"
                                       class="btn btn-ghost btn-sm btn-icon" title="Edit"
                                       data-bs-toggle="tooltip"><?= icon('edit') ?></a>
                                    <form method="post" class="d-inline m-0"
                                          action="<?= e(url('/bc/targets/' . (int) $target['id'] . '/delete')) ?>"
                                          data-confirm="Delete the targets for <?= e((string) $target['agent_name']) ?>, <?= e(date('F Y', (int) strtotime((string) $target['target_month']))) ?>?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-ghost btn-sm btn-icon text-danger"
                                                title="Delete" data-bs-toggle="tooltip"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="lrms-card-foot">
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $targets, 'label' => 'target rows']) ?>
        </div>
    <?php endif; ?>
</div>
