<?php
/**
 * @var \App\Core\Paginator        $entries
 * @var array<string,string>       $schemeFields
 * @var array<string,int>          $summary
 * @var string                     $search
 * @var string                     $from
 * @var string                     $to
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
        <h1>SSS enrolment</h1>
        <p>APY, PMJJBY, PMSBY and PMJDY enrolments recorded per agent per day</p>
    </div>
    <?php if (can('sss.manage')): ?>
        <a href="<?= e(url('/bc/sss/create')) ?>" class="btn btn-primary btn-sm">
            <?= icon('plus') ?> Record enrolment
        </a>
    <?php endif; ?>
</div>

<div class="lrms-stats">
    <div class="lrms-stat lrms-stat-accent">
        <div class="lrms-stat-label"><?= icon('handshake') ?> Total enrolments</div>
        <div class="lrms-stat-value"><?= e(number_format($summary['total'])) ?></div>
        <div class="lrms-stat-sub">
            <?= e(number_format($summary['agents'])) ?> agent(s) over <?= e(number_format($summary['days'])) ?> day(s)
        </div>
    </div>
    <?php foreach ($schemeFields as $field => $label): ?>
        <?php $key = str_replace('_count', '', $field); ?>
        <div class="lrms-stat">
            <div class="lrms-stat-label"><?= e($label) ?></div>
            <div class="lrms-stat-value sm"><?= e(number_format((int) $summary[$key])) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/bc/sss')) ?>">
            <?php
            /*
             * The sort travels with the filter. Without these two hidden fields the form
             * submits only its own inputs, so changing any dropdown silently dropped the
             * column the user had chosen to sort by - while sort_link() kept the filters,
             * making the loss one-directional and baffling.
             */
            ?>
            <?= sort_hidden($sortBy, $sortDir) ?>
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="s-search">Search</label>
                    <input type="search" class="form-control" id="s-search" name="search"
                           value="<?= e($search) ?>" placeholder="Agent name, employee or BC code">
                </div>
                <div>
                    <label class="form-label" for="s-from">From</label>
                    <input type="date" class="form-control" id="s-from" name="from" value="<?= e($from) ?>">
                </div>
                <div>
                    <label class="form-label" for="s-to">To</label>
                    <input type="date" class="form-control" id="s-to" name="to" value="<?= e($to) ?>">
                </div>
                <?php if (count($branches) > 1): ?>
                    <div>
                        <label class="form-label" for="s-branch">Branch</label>
                        <select class="form-select" id="s-branch" name="branch_id" data-auto-submit>
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
                    <label class="form-label" for="s-agent">Agent</label>
                    <select class="form-select" id="s-agent" name="agent_id" data-auto-submit>
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
                    <a href="<?= e(url('/bc/sss')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lrms-card">
    <?php if ($entries->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'     => 'No enrolments in this period',
            'message'     => 'Record the day\'s enrolments here. The reminder cron nudges agents who have not entered anything on a working day.',
            'iconName'    => 'users',
            'actionLabel' => can('sss.manage') ? 'Record enrolment' : null,
            'actionUrl'   => can('sss.manage') ? url('/bc/sss/create') : null,
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th><?= sort_link('Date', 'enrollment_date', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Agent', 'agent_name', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Code', 'employee_code', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Branch', 'branch_name', $sortBy, $sortDir) ?></th>
                        <?php foreach ($schemeFields as $label): ?>
                            <th class="text-end"><?= e($label) ?></th>
                        <?php endforeach; ?>
                        <th class="text-end"><?= sort_link('Total', 'total', $sortBy, $sortDir) ?></th>
                        <th>Remarks</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries->items as $entry): ?>
                        <tr>
                            <td style="font-weight:550"><?= fmt_date($entry['enrollment_date']) ?></td>
                            <td style="font-weight:550"><?= e((string) $entry['agent_name']) ?></td>
                            <td class="font-mono" style="font-size:.8125rem"><?= e((string) $entry['employee_code']) ?></td>
                            <td style="font-size:.8125rem"><?= nullable($entry['branch_name']) ?></td>
                            <?php foreach (array_keys($schemeFields) as $field): ?>
                                <td class="num"><?= e(number_format((int) $entry[$field])) ?></td>
                            <?php endforeach; ?>
                            <td class="num" style="font-weight:620"><?= e(number_format((int) $entry['total'])) ?></td>
                            <td style="font-size:.8125rem"><?= nullable($entry['remarks']) ?></td>
                            <td class="text-end nowrap">
                                <?php if (can('sss.manage')): ?>
                                    <a href="<?= e(url('/bc/sss/' . (int) $entry['id'] . '/edit')) ?>"
                                       class="btn btn-ghost btn-sm btn-icon" title="Edit"
                                       data-bs-toggle="tooltip"><?= icon('edit') ?></a>
                                    <form method="post" class="d-inline m-0"
                                          action="<?= e(url('/bc/sss/' . (int) $entry['id'] . '/delete')) ?>"
                                          data-confirm="Delete the entry for <?= e((string) $entry['agent_name']) ?> on <?= fmt_date($entry['enrollment_date']) ?>?">
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
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $entries, 'label' => 'entries']) ?>
        </div>
    <?php endif; ?>
</div>
