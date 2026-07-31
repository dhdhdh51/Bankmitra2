<?php
/**
 * @var \App\Core\Paginator       $visits
 * @var array<string,mixed>       $filters
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $agents
 * @var list<string>              $villages
 * @var list<string>              $loanTypes
 */
?>

<div class="lrms-page-head">
    <div>
        <h1>Visit Reports</h1>
        <p>Digital BC field visit reports submitted from the Android app</p>
    </div>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/visits')) ?>">
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="v-search">Search</label>
                    <input type="search" class="form-control" id="v-search" name="search"
                           value="<?= e($filters['search']) ?>" placeholder="Account no, customer, village">
                </div>

                <?php if (count($branches) > 1): ?>
                    <div>
                        <label class="form-label" for="v-branch">Branch</label>
                        <select class="form-select" id="v-branch" name="branch_id" data-auto-submit>
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
                    <label class="form-label" for="v-agent">Agent</label>
                    <select class="form-select" id="v-agent" name="agent_id" data-auto-submit>
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
                    <label class="form-label" for="v-from">Visit date from</label>
                    <input type="date" class="form-control" id="v-from" name="date_from"
                           value="<?= e($filters['date_from']) ?>">
                </div>

                <div>
                    <label class="form-label" for="v-to">To</label>
                    <input type="date" class="form-control" id="v-to" name="date_to"
                           value="<?= e($filters['date_to']) ?>">
                </div>

                <div>
                    <label class="form-label" for="v-village">Village</label>
                    <select class="form-select" id="v-village" name="village" data-auto-submit>
                        <option value="">All villages</option>
                        <?php foreach ($villages as $village): ?>
                            <option value="<?= e($village) ?>" <?= $filters['village'] === $village ? 'selected' : '' ?>>
                                <?= e($village) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Filter</button>
                    <a href="<?= e(url('/visits')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lrms-card">
    <?php if ($visits->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'  => 'No visit reports',
            'message'  => 'Field visits submitted by BC/DC agents from the Android app will appear here.',
            'iconName' => 'clipboard',
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th>Visit date</th>
                        <th>Loan account</th>
                        <th>Customer</th>
                        <th>Village</th>
                        <th>Agent</th>
                        <th>Contact</th>
                        <th class="text-end">Promise</th>
                        <th class="text-end">Outstanding</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visits->items as $visit): ?>
                        <tr>
                            <td class="nowrap" style="font-size:.8125rem">
                                <?= e(fmt_date((string) $visit['visit_date'], 'd M y')) ?>
                                <div class="text-muted" style="font-size:.6875rem">
                                    <?= e(fmt_time((string) $visit['visit_time'])) ?>
                                </div>
                            </td>
                            <td class="nowrap">
                                <a href="<?= e(url('/customers/' . (int) $visit['loan_account_id'])) ?>"
                                   class="font-mono" style="font-size:.75rem">
                                    <?= e($visit['loan_account_number']) ?>
                                </a>
                                <div class="text-muted" style="font-size:.6875rem">
                                    <?= e($visit['loan_type'] ?? '—') ?>
                                </div>
                            </td>
                            <td style="font-weight:550"><?= e($visit['customer_name']) ?></td>
                            <td style="font-size:.8125rem"><?= nullable($visit['village']) ?></td>
                            <td style="font-size:.8125rem"><?= e($visit['agent_name']) ?></td>
                            <td>
                                <?php if ((int) $visit['customer_met'] === 1): ?>
                                    <span class="lrms-badge badge-visited">Customer met</span>
                                <?php elseif ((int) $visit['house_locked'] === 1): ?>
                                    <span class="lrms-badge badge-pending">House locked</span>
                                <?php else: ?>
                                    <span class="lrms-badge badge-followup">Recorded</span>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if ((float) ($visit['promise_amount'] ?? 0) > 0): ?>
                                    <span style="font-weight:620"><?= e(money($visit['promise_amount'], false)) ?></span>
                                    <div class="text-muted" style="font-size:.6875rem">
                                        <?= e(fmt_date((string) $visit['promise_date'], 'd M y')) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= e(money($visit['outstanding_amount'], false)) ?></td>
                            <td class="text-end nowrap">
                                <a href="<?= e(url('/visits/' . (int) $visit['id'])) ?>"
                                   class="btn btn-ghost btn-sm btn-icon" title="Open report"
                                   data-bs-toggle="tooltip"><?= icon('eye') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="lrms-card-foot">
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $visits, 'label' => 'visit reports']) ?>
        </div>
    <?php endif; ?>
</div>
