<?php
/**
 * @var \App\Core\Paginator $branches
 * @var string              $search
 * @var string              $status
 * @var string              $sortBy
 * @var string              $sortDir
 */
?>

<div class="lrms-page-head">
    <div>
        <h1>Branches</h1>
        <p>Branch master &mdash; code, name, district, state, PIN and status</p>
    </div>
    <?php if (can('branches.create')): ?>
        <a href="<?= e(url('/branches/create')) ?>" class="btn btn-primary btn-sm">
            <?= icon('plus') ?> Add branch
        </a>
    <?php endif; ?>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/branches')) ?>">
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
                    <label class="form-label" for="b-search">Search</label>
                    <input type="search" class="form-control" id="b-search" name="search"
                           value="<?= e($search) ?>" placeholder="Code, name, district, state, PIN">
                </div>
                <div>
                    <label class="form-label" for="b-status">Status</label>
                    <select class="form-select" id="b-status" name="status" data-auto-submit>
                        <option value="">All</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Filter</button>
                    <a href="<?= e(url('/branches')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lrms-card">
    <?php if ($branches->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'     => 'No branches',
            'message'     => 'Create the branches your BC agents work under before importing leads.',
            'iconName'    => 'branch',
            'actionLabel' => can('branches.create') ? 'Add branch' : null,
            'actionUrl'   => can('branches.create') ? url('/branches/create') : null,
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th><?= sort_link('Code', 'branch_code', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Branch name', 'name', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('District', 'district', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('State', 'state', $sortBy, $sortDir) ?></th>
                        <th>PIN</th>
                        <th class="text-end">Users</th>
                        <th class="text-end">Leads</th>
                        <th><?= sort_link('Status', 'status', $sortBy, $sortDir) ?></th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($branches->items as $branch): ?>
                        <tr>
                            <td class="font-mono" style="font-size:.8125rem;font-weight:620">
                                <?= e($branch['branch_code']) ?>
                            </td>
                            <td style="font-weight:550"><?= e($branch['name']) ?></td>
                            <td style="font-size:.8125rem"><?= nullable($branch['district']) ?></td>
                            <td style="font-size:.8125rem"><?= nullable($branch['state']) ?></td>
                            <td class="font-mono" style="font-size:.75rem"><?= nullable($branch['pincode']) ?></td>
                            <td class="num"><?= e(number_format((int) $branch['user_count'])) ?></td>
                            <td class="num"><?= e(number_format((int) $branch['lead_count'])) ?></td>
                            <td>
                                <span class="lrms-badge <?= (string) $branch['status'] === 'active' ? 'badge-visited' : 'badge-closed' ?>">
                                    <?= e(ucfirst((string) $branch['status'])) ?>
                                </span>
                            </td>
                            <td class="text-end nowrap">
                                <?php if (can('branches.update')): ?>
                                    <a href="<?= e(url('/branches/' . (int) $branch['id'] . '/edit')) ?>"
                                       class="btn btn-ghost btn-sm btn-icon" title="Edit"
                                       data-bs-toggle="tooltip"><?= icon('edit') ?></a>
                                <?php endif; ?>

                                <?php if (can('branches.delete')): ?>
                                    <form method="post" class="d-inline m-0"
                                          action="<?= e(url('/branches/' . (int) $branch['id'] . '/delete')) ?>"
                                          data-confirm="Delete branch &quot;<?= e($branch['name']) ?>&quot;? This cannot be undone.">
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
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $branches, 'label' => 'branches']) ?>
        </div>
    <?php endif; ?>
</div>
