<?php
/**
 * Leads list with search, filters and bulk actions.
 *
 * @var \App\Core\Paginator       $leads
 * @var array<string,int>         $counts
 * @var array<string,mixed>       $filters
 * @var string                    $sortBy
 * @var string                    $sortDir
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $agents
 * @var list<string>              $villages
 * @var list<string>              $loanTypes
 * @var bool                      $canAssign
 * @var bool                      $canTransfer
 * @var bool                      $canClose
 */

use App\Core\Url;

$statusChips = [
    ''         => 'All',
    'pending'  => 'Pending',
    'visited'  => 'Visited',
    'promise'  => 'Promise',
    'followup' => 'Follow-up',
    'legal'    => 'Legal',
    'closed'   => 'Closed',
];

$returnQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
$hasBulk = $canAssign || $canTransfer || $canClose;
?>

<div class="lrms-page-head">
    <div>
        <h1>Customers &amp; Leads</h1>
        <p>Search by loan account number, customer name, mobile, Aadhaar or village</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (can('reports.export')): ?>
            <a href="<?= e(url('/customers/export') . ($returnQuery !== '' ? '?' . $returnQuery : '')) ?>"
               class="btn btn-outline-secondary btn-sm">
                <?= icon('excel') ?> Export Excel
            </a>
        <?php endif; ?>
        <?php if (can('import.upload')): ?>
            <a href="<?= e(url('/import')) ?>" class="btn btn-primary btn-sm">
                <?= icon('upload') ?> Import leads
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ==================== Filters ==================== -->
<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/customers')) ?>">
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="f-search">Search</label>
                    <input type="search" class="form-control" id="f-search" name="search"
                           value="<?= e($filters['search']) ?>"
                           placeholder="Account no, name, mobile, Aadhaar, village">
                </div>

                <?php if (count($branches) > 1): ?>
                    <div>
                        <label class="form-label" for="f-branch">Branch</label>
                        <select class="form-select" id="f-branch" name="branch_id" data-auto-submit>
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
                    <label class="form-label" for="f-agent">Agent</label>
                    <select class="form-select" id="f-agent" name="agent_id" data-auto-submit>
                        <option value="">All agents</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= e((string) $agent['id']) ?>"
                                <?= ($filters['agent_id'] ?? null) === (int) $agent['id'] ? 'selected' : '' ?>>
                                <?= e($agent['name']) ?> (<?= e($agent['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="f-village">Village</label>
                    <select class="form-select" id="f-village" name="village" data-auto-submit>
                        <option value="">All villages</option>
                        <?php foreach ($villages as $village): ?>
                            <option value="<?= e($village) ?>" <?= $filters['village'] === $village ? 'selected' : '' ?>>
                                <?= e($village) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="f-loan-type">Loan type</label>
                    <select class="form-select" id="f-loan-type" name="loan_type" data-auto-submit>
                        <option value="">All types</option>
                        <?php foreach ($loanTypes as $type): ?>
                            <option value="<?= e($type) ?>" <?= $filters['loan_type'] === $type ? 'selected' : '' ?>>
                                <?= e($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Filter</button>
                    <a href="<?= e(url('/customers')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-3 mt-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="f-npa" name="npa_only"
                           <?= !empty($filters['npa_only']) ? 'checked' : '' ?> data-auto-submit>
                    <label class="form-check-label" for="f-npa">NPA cases only</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="f-unassigned" name="unassigned"
                           <?= !empty($filters['unassigned']) ? 'checked' : '' ?> data-auto-submit>
                    <label class="form-check-label" for="f-unassigned">Unassigned only</label>
                </div>
            </div>

            <?php /* Keep the active status filter when other filters are submitted. */ ?>
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        </form>
    </div>
</div>

<!-- ==================== Status chips ==================== -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($statusChips as $value => $label): ?>
        <a class="lrms-chip<?= $filters['status'] === $value ? ' active' : '' ?>"
           href="<?= e(Url::withQuery(['status' => $value === '' ? null : $value, 'page' => null])) ?>">
            <?= e($label) ?>
            <span class="count"><?= e(number_format((int) ($counts[$value === '' ? 'all' : $value] ?? 0))) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- ==================== Table ==================== -->
<form method="post" action="<?= e(url('/customers/bulk')) ?>" data-bulk-form>
    <?= csrf_field() ?>
    <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">

    <div class="lrms-card">
        <?php if ($leads->isEmpty()): ?>
            <?= \App\Core\View::partial('partials/empty', [
                'heading'     => 'No leads found',
                'message'     => $filters['search'] !== ''
                    ? 'Nothing matched "' . $filters['search'] . '". Try a different account number, name, mobile or village.'
                    : 'Import a lead file to get started, or clear the filters.',
                'iconName'    => 'customers',
                'actionLabel' => can('import.upload') ? 'Import leads' : null,
                'actionUrl'   => can('import.upload') ? url('/import') : null,
            ]) ?>
        <?php else: ?>
            <div class="lrms-table-wrap">
                <table class="lrms-table">
                    <thead>
                        <tr>
                            <?php if ($hasBulk): ?>
                                <th class="col-check">
                                    <input type="checkbox" class="form-check-input" data-bulk-master
                                           aria-label="Select all leads on this page">
                                </th>
                            <?php endif; ?>
                            <th><?= sort_link('Loan Account', 'loan_account_number', $sortBy, $sortDir) ?></th>
                            <th><?= sort_link('Customer', 'customer_name', $sortBy, $sortDir) ?></th>
                            <th><?= sort_link('Village', 'village', $sortBy, $sortDir) ?></th>
                            <th>Contact</th>
                            <th class="text-end"><?= sort_link('Outstanding', 'outstanding_amount', $sortBy, $sortDir) ?></th>
                            <th class="text-end"><?= sort_link('Overdue', 'overdue_amount', $sortBy, $sortDir) ?></th>
                            <th>Agent</th>
                            <th><?= sort_link('Status', 'current_status', $sortBy, $sortDir) ?></th>
                            <th class="text-end"><?= sort_link('Last visit', 'last_visit_at', $sortBy, $sortDir) ?></th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads->items as $lead): ?>
                            <tr>
                                <?php if ($hasBulk): ?>
                                    <td class="col-check">
                                        <input type="checkbox" class="form-check-input" name="lead_ids[]"
                                               value="<?= e((string) $lead['id']) ?>" data-bulk-item
                                               aria-label="Select <?= e($lead['loan_account_number']) ?>">
                                    </td>
                                <?php endif; ?>

                                <td class="nowrap">
                                    <a href="<?= e(url('/customers/' . (int) $lead['id'])) ?>"
                                       class="font-mono fw-semibold" style="font-size:.8125rem">
                                        <?= e($lead['loan_account_number']) ?>
                                    </a>
                                    <?php if ((int) $lead['is_npa'] === 1): ?>
                                        <span class="lrms-npa">NPA</span>
                                    <?php endif; ?>
                                    <div class="text-muted" style="font-size:.6875rem">
                                        <?= e($lead['loan_type'] ?? '—') ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="d-block" style="font-weight:550"><?= e($lead['customer_name']) ?></span>
                                    <?php if (!empty($lead['father_husband_name'])): ?>
                                        <span class="d-block text-muted" style="font-size:.6875rem">
                                            C/o <?= e($lead['father_husband_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td style="font-size:.8125rem"><?= nullable($lead['village']) ?></td>

                                <td class="nowrap" style="font-size:.75rem">
                                    <span class="font-mono"><?= nullable($lead['mobile_masked']) ?></span>
                                    <div class="text-muted font-mono"><?= nullable($lead['aadhaar_masked']) ?></div>
                                </td>

                                <td class="num"><?= e(money($lead['outstanding_amount'], false)) ?></td>
                                <td class="num" style="color:var(--lrms-danger)">
                                    <?= e(money($lead['overdue_amount'], false)) ?>
                                </td>

                                <td style="font-size:.8125rem">
                                    <?php if (!empty($lead['agent_name'])): ?>
                                        <?= e($lead['agent_name']) ?>
                                        <div class="text-muted" style="font-size:.6875rem">
                                            <?= e($lead['agent_code'] ?? '') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="lrms-badge badge-pending">Unassigned</span>
                                    <?php endif; ?>
                                </td>

                                <td><?= status_badge($lead['current_status']) ?></td>

                                <td class="num text-muted" style="font-size:.75rem">
                                    <?php if ($lead['last_visit_at'] !== null): ?>
                                        <?= e(fmt_date((string) $lead['last_visit_at'], 'd M y')) ?>
                                        <div style="font-size:.6875rem">
                                            <?= e((string) (int) $lead['visit_count']) ?> visit(s)
                                        </div>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <?php
                                /*
                                 * View AND edit, on the row.
                                 *
                                 * Edit used to live only on the profile page. That is one click
                                 * further than it sounds: an operator fixing ten mistyped mobile
                                 * numbers off a list had to open a borrower, find Edit, save, go
                                 * back, and lose their filter and page position each time. The
                                 * list is where somebody notices a field is wrong, so it is where
                                 * the pencil belongs.
                                 */
                                ?>
                                <td class="text-end nowrap">
                                    <a href="<?= e(url('/customers/' . (int) $lead['id'])) ?>"
                                       class="btn btn-ghost btn-sm btn-icon" title="View profile"
                                       data-bs-toggle="tooltip">
                                        <?= icon('eye') ?>
                                    </a>
                                    <?php if (can('customers.update')): ?>
                                        <a href="<?= e(url('/customers/' . (int) $lead['id'] . '/edit')) ?>"
                                           class="btn btn-ghost btn-sm btn-icon" title="Edit borrower details"
                                           data-bs-toggle="tooltip">
                                            <?= icon('edit') ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="lrms-card-foot">
                <?= \App\Core\View::partial('partials/pagination', ['paginator' => $leads, 'label' => 'leads']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== Bulk action bar ==================== -->
    <?php if ($hasBulk && !$leads->isEmpty()): ?>
        <div class="lrms-bulkbar" data-bulk-bar>
            <span class="count" data-bulk-count>0 leads selected</span>

            <select class="form-select" name="bulk_action" data-bulk-action aria-label="Bulk action">
                <option value="">Choose action…</option>
                <?php if ($canAssign): ?>
                    <option value="assign">Assign to agent</option>
                    <?php
                    /*
                     * Spreads the selection across the branch's agents, balancing what
                     * each of them is already carrying rather than dealing the rows out
                     * in turn - two imports dealt round-robin both start at the same
                     * agent, which is how one person ends up with every other lead.
                     */
                    ?>
                    <option value="distribute">Distribute equally among agents</option>
                <?php endif; ?>
                <?php if (can('leads.reassign')): ?>
                    <option value="reassign">Reassign to agent</option>
                    <option value="unassign">Remove assignment</option>
                <?php endif; ?>
                <?php if ($canTransfer): ?>
                    <option value="transfer">Transfer to branch</option>
                <?php endif; ?>
                <?php if ($canClose): ?>
                    <option value="followup">Mark as follow-up</option>
                    <option value="close">Close leads</option>
                    <option value="reopen">Reopen leads</option>
                <?php endif; ?>
            </select>

            <div data-bulk-when="assign,reassign" class="d-none">
                <select class="form-select" name="agent_id_action" data-bulk-agent aria-label="Target agent">
                    <option value="">Select agent…</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?= e((string) $agent['id']) ?>">
                            <?= e($agent['name']) ?> (<?= e($agent['employee_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($canTransfer): ?>
                <div data-bulk-when="transfer" class="d-none">
                    <select class="form-select" name="branch_id_action" data-bulk-branch aria-label="Destination branch">
                        <option value="">Select branch…</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= e((string) $branch['id']) ?>">
                                <?= e($branch['name']) ?> (<?= e($branch['branch_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-light btn-sm ms-auto">Apply</button>
        </div>
    <?php endif; ?>
</form>
