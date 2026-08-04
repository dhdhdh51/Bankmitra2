<?php
/**
 * @var \App\Core\Paginator       $promises
 * @var array<string,mixed>       $filters
 * @var array<string,int>         $counts
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $agents
 */

use App\Core\Url;

$chips = [
    ''          => 'All',
    'pending'   => 'Pending',
    'kept'      => 'Kept',
    'broken'    => 'Broken',
    'cancelled' => 'Cancelled',
];
?>

<div class="lrms-page-head">
    <div>
        <h1>Promises</h1>
        <p>Promise-to-pay cases recorded during field visits</p>
    </div>
    <?php if (can('reports.export')): ?>
        <a href="<?= e(url('/reports/promise')) ?>" class="btn btn-outline-secondary btn-sm">
            <?= icon('reports') ?> Promise report
        </a>
    <?php endif; ?>
</div>

<div class="lrms-stats">
    <div class="lrms-stat lrms-stat-accent is-warning">
        <div class="lrms-stat-label">Pending</div>
        <div class="lrms-stat-value"><?= e(number_format((int) ($counts['pending'] ?? 0))) ?></div>
    </div>
    <div class="lrms-stat lrms-stat-accent is-success">
        <div class="lrms-stat-label">Kept</div>
        <div class="lrms-stat-value"><?= e(number_format((int) ($counts['kept'] ?? 0))) ?></div>
    </div>
    <div class="lrms-stat lrms-stat-accent is-danger">
        <div class="lrms-stat-label">Broken</div>
        <div class="lrms-stat-value"><?= e(number_format((int) ($counts['broken'] ?? 0))) ?></div>
    </div>
    <div class="lrms-stat lrms-stat-accent is-slate">
        <div class="lrms-stat-label">Cancelled</div>
        <div class="lrms-stat-value"><?= e(number_format((int) ($counts['cancelled'] ?? 0))) ?></div>
    </div>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/promises')) ?>">
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="p-search">Search</label>
                    <input type="search" class="form-control" id="p-search" name="search"
                           value="<?= e($filters['search']) ?>" placeholder="Account no or customer">
                </div>

                <?php if (count($branches) > 1): ?>
                    <div>
                        <label class="form-label" for="p-branch">Branch</label>
                        <select class="form-select" id="p-branch" name="branch_id" data-auto-submit>
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

                <div>
                    <label class="form-label" for="p-agent">Agent</label>
                    <select class="form-select" id="p-agent" name="agent_id" data-auto-submit>
                        <option value="">All agents</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= e((string) $agent['id']) ?>"
                                <?= ($filters['agent_id'] ?? null) === (int) $agent['id'] ? 'selected' : '' ?>>
                                <?= e($agent['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="p-from">Promise date from</label>
                    <input type="date" class="form-control" id="p-from" name="date_from"
                           value="<?= e($filters['date_from']) ?>">
                </div>
                <div>
                    <label class="form-label" for="p-to">To</label>
                    <input type="date" class="form-control" id="p-to" name="date_to"
                           value="<?= e($filters['date_to']) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Filter</button>
                    <a href="<?= e(url('/promises')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        </form>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($chips as $value => $label): ?>
        <a class="lrms-chip<?= $filters['status'] === $value ? ' active' : '' ?>"
           href="<?= e(Url::withQuery(['status' => $value === '' ? null : $value, 'page' => null])) ?>">
            <?= e($label) ?>
            <?php if ($value !== ''): ?>
                <span class="count"><?= e(number_format((int) ($counts[$value] ?? 0))) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="lrms-card">
    <?php if ($promises->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'  => 'No promises found',
            'message'  => 'Promises are created automatically when an agent records a promise amount and date during a visit.',
            'iconName' => 'handshake',
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th>Loan account</th>
                        <th>Customer</th>
                        <th>Address</th>
                        <th>Agent</th>
                        <th class="text-end">Promise</th>
                        <th>Due date</th>
                        <th>Status</th>
                        <th class="text-end">Outstanding</th>
                        <?php if (can('promises.update')): ?><th class="text-end">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promises->items as $promise): ?>
                        <?php $overdue = (string) $promise['status'] === 'pending' && (int) $promise['days_overdue'] > 0; ?>
                        <tr>
                            <td class="nowrap">
                                <a href="<?= e(url('/customers/' . (int) $promise['loan_account_id'])) ?>"
                                   class="font-mono" style="font-size:.75rem">
                                    <?= e($promise['loan_account_number']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="d-block" style="font-weight:550"><?= e($promise['customer_name']) ?></span>
                                <span class="d-block text-muted font-mono" style="font-size:.6875rem">
                                    <?= e($promise['mobile_masked'] ?? '') ?>
                                </span>
                            </td>
                            <td style="font-size:.8125rem"><?= nullable($promise['address']) ?></td>
                            <td style="font-size:.8125rem"><?= e($promise['agent_name']) ?></td>
                            <td class="num" style="font-weight:620"><?= e(money($promise['promise_amount'])) ?></td>
                            <td class="nowrap" style="font-size:.8125rem">
                                <?= e(fmt_date((string) $promise['promise_date'], 'd M y')) ?>
                                <?php if ($overdue): ?>
                                    <div>
                                        <span class="lrms-badge badge-legal">
                                            <?= e((string) (int) $promise['days_overdue']) ?> days late
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= promise_badge($promise['status']) ?></td>
                            <td class="num"><?= e(money($promise['outstanding_amount'], false)) ?></td>

                            <?php if (can('promises.update')): ?>
                                <td class="text-end nowrap">
                                    <?php if ((string) $promise['status'] === 'pending'): ?>
                                        <form method="post" class="d-inline m-0"
                                              action="<?= e(url('/promises/' . (int) $promise['id'] . '/settle')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="status" value="kept">
                                            <button class="btn btn-ghost btn-sm text-success" title="Mark kept"
                                                    data-bs-toggle="tooltip"><?= icon('check') ?></button>
                                        </form>
                                        <form method="post" class="d-inline m-0"
                                              action="<?= e(url('/promises/' . (int) $promise['id'] . '/settle')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="status" value="broken">
                                            <button class="btn btn-ghost btn-sm text-danger" title="Mark broken"
                                                    data-bs-toggle="tooltip"><?= icon('x') ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:.75rem">
                                            <?= e(fmt_date((string) ($promise['settled_at'] ?? ''), 'd M y')) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="lrms-card-foot">
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $promises, 'label' => 'promises']) ?>
        </div>
    <?php endif; ?>
</div>
