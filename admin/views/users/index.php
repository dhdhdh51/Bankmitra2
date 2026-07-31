<?php
/**
 * @var \App\Core\Paginator       $users
 * @var list<array<string,mixed>> $roles
 * @var list<array<string,mixed>> $branches
 * @var array<string,mixed>       $filters
 * @var string                    $sortBy
 * @var string                    $sortDir
 */
?>

<div class="lrms-page-head">
    <div>
        <h1>Managers &amp; Agents</h1>
        <p>User accounts, roles, branch assignment and password resets</p>
    </div>
    <?php if (can('users.create')): ?>
        <a href="<?= e(url('/users/create')) ?>" class="btn btn-primary btn-sm">
            <?= icon('plus') ?> Add user
        </a>
    <?php endif; ?>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/users')) ?>">
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="u-search">Search</label>
                    <input type="search" class="form-control" id="u-search" name="search"
                           value="<?= e($filters['search']) ?>" placeholder="Name, code, email, BC code, mobile">
                </div>

                <div>
                    <label class="form-label" for="u-role">Role</label>
                    <select class="form-select" id="u-role" name="role_id" data-auto-submit>
                        <option value="">All roles</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= e((string) $role['id']) ?>"
                                <?= ($filters['role_id'] ?? null) === (int) $role['id'] ? 'selected' : '' ?>>
                                <?= e($role['display_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (count($branches) > 1): ?>
                    <div>
                        <label class="form-label" for="u-branch">Branch</label>
                        <select class="form-select" id="u-branch" name="branch_id" data-auto-submit>
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
                    <label class="form-label" for="u-status">Status</label>
                    <select class="form-select" id="u-status" name="status" data-auto-submit>
                        <option value="">All</option>
                        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="suspended" <?= $filters['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Filter</button>
                    <a href="<?= e(url('/users')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lrms-card">
    <?php if ($users->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'     => 'No users found',
            'message'     => 'Create branch managers and BC/DC agent accounts to start assigning leads.',
            'iconName'    => 'users',
            'actionLabel' => can('users.create') ? 'Add user' : null,
            'actionUrl'   => can('users.create') ? url('/users/create') : null,
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th><?= sort_link('Employee code', 'employee_code', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Name', 'name', $sortBy, $sortDir) ?></th>
                        <th>Role</th>
                        <th>Branch</th>
                        <th>Contact</th>
                        <th class="text-end">Leads</th>
                        <th><?= sort_link('Status', 'status', $sortBy, $sortDir) ?></th>
                        <th><?= sort_link('Last sign-in', 'last_login_at', $sortBy, $sortDir) ?></th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users->items as $user): ?>
                        <tr>
                            <td class="font-mono" style="font-size:.8125rem;font-weight:620">
                                <?= e($user['employee_code']) ?>
                                <?php if ((int) $user['must_change_password'] === 1): ?>
                                    <div>
                                        <span class="lrms-badge badge-pending" title="Must change password at next sign-in"
                                              data-bs-toggle="tooltip">New</span>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="lrms-avatar" style="width:26px;height:26px;font-size:.6875rem">
                                        <?= e(mb_substr((string) $user['name'], 0, 1)) ?>
                                    </span>
                                    <span>
                                        <span class="d-block" style="font-weight:550"><?= e($user['name']) ?></span>
                                        <?php if (!empty($user['designation'])): ?>
                                            <span class="d-block text-muted" style="font-size:.6875rem">
                                                <?= e($user['designation']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <span class="lrms-badge <?= (string) $user['role_slug'] === 'agent' ? 'badge-promise' : 'badge-followup' ?>">
                                    <?= e($user['role_name']) ?>
                                </span>
                                <?php if (!empty($user['bc_code'])): ?>
                                    <div class="text-muted font-mono" style="font-size:.6875rem">
                                        <?= e($user['bc_code']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td style="font-size:.8125rem"><?= nullable($user['branch_name']) ?></td>

                            <td style="font-size:.75rem">
                                <span class="font-mono"><?= nullable($user['mobile_masked']) ?></span>
                                <?php if (!empty($user['email'])): ?>
                                    <div class="text-muted"><?= e($user['email']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td class="num"><?= e(number_format((int) $user['assigned_leads'])) ?></td>

                            <td>
                                <?php
                                $statusClass = match ((string) $user['status']) {
                                    'active'    => 'badge-visited',
                                    'suspended' => 'badge-legal',
                                    default     => 'badge-closed',
                                };
                                ?>
                                <span class="lrms-badge <?= $statusClass ?>"><?= e(ucfirst((string) $user['status'])) ?></span>
                            </td>

                            <td class="text-muted" style="font-size:.75rem">
                                <?= $user['last_login_at'] === null ? 'Never' : e(time_ago((string) $user['last_login_at'])) ?>
                            </td>

                            <td class="text-end nowrap">
                                <div class="dropdown">
                                    <button class="btn btn-ghost btn-sm btn-icon" data-bs-toggle="dropdown"
                                            aria-expanded="false" aria-label="Actions">⋯</button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <?php if (can('users.update')): ?>
                                            <li>
                                                <a class="dropdown-item d-flex align-items-center gap-2"
                                                   href="<?= e(url('/users/' . (int) $user['id'] . '/edit')) ?>">
                                                    <?= icon('edit') ?> Edit details
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php if (can('users.reset_password')): ?>
                                            <li>
                                                <button type="button" class="dropdown-item d-flex align-items-center gap-2"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#resetModal<?= e((string) $user['id']) ?>">
                                                    <?= icon('key') ?> Reset password
                                                </button>
                                            </li>
                                        <?php endif; ?>

                                        <?php if (can('users.toggle_status')): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php foreach (['active' => 'Activate', 'suspended' => 'Suspend', 'inactive' => 'Deactivate'] as $status => $label): ?>
                                                <?php if ((string) $user['status'] !== $status): ?>
                                                    <li>
                                                        <form method="post" class="m-0"
                                                              action="<?= e(url('/users/' . (int) $user['id'] . '/status')) ?>">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="status" value="<?= e($status) ?>">
                                                            <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                                                                <?= icon($status === 'active' ? 'unlock' : 'lock') ?> <?= e($label) ?>
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <?php if (can('users.delete')): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="post" class="m-0"
                                                      action="<?= e(url('/users/' . (int) $user['id'] . '/delete')) ?>"
                                                      data-confirm="Delete &quot;<?= e($user['name']) ?>&quot;? Users with visit history cannot be deleted &mdash; suspend them instead.">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="dropdown-item d-flex align-items-center gap-2 text-danger">
                                                        <?= icon('trash') ?> Delete user
                                                    </button>
                                                </form>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="lrms-card-foot">
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $users, 'label' => 'users']) ?>
        </div>
    <?php endif; ?>
</div>

<!-- ==================== Reset password modals ==================== -->
<?php if (can('users.reset_password')): ?>
    <?php foreach ($users->items as $user): ?>
        <div class="modal fade" id="resetModal<?= e((string) $user['id']) ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="post"
                      action="<?= e(url('/users/' . (int) $user['id'] . '/reset-password')) ?>">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Reset password</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted" style="font-size:.8438rem">
                            Setting a new password for <strong><?= e($user['name']) ?></strong>
                            (<code><?= e($user['employee_code']) ?></code>).
                            They will be required to change it at their next sign-in.
                        </p>
                        <label class="form-label" for="reset-pass-<?= e((string) $user['id']) ?>">
                            New password
                        </label>
                        <input type="text" class="form-control" name="password"
                               id="reset-pass-<?= e((string) $user['id']) ?>"
                               placeholder="Leave blank to generate one automatically" autocomplete="off">
                        <div class="form-text">
                            The password is shown once after saving so you can pass it on.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Reset password</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
