<?php
/**
 * Permission matrix.
 *
 * @var list<array<string,mixed>>                   $roles
 * @var array<string,list<array<string,mixed>>>     $grouped   module => permissions
 * @var array<int,array<int,bool>>                  $assigned  role_id => [permission_id => true]
 * @var int                                         $selectedRoleId
 * @var bool                                        $canManage
 */

$selected = null;
foreach ($roles as $role) {
    if ((int) $role['id'] === $selectedRoleId) {
        $selected = $role;
        break;
    }
}
$selected ??= $roles[0] ?? null;
$isSuperAdmin = $selected !== null && (string) $selected['slug'] === 'super_admin';
$roleAssigned = $assigned[(int) ($selected['id'] ?? 0)] ?? [];
?>

<div class="lrms-page-head">
    <div>
        <h1>Roles &amp; Permissions</h1>
        <p>Control what each role can see and do</p>
    </div>
</div>

<div class="row g-3">
    <!-- Role list -->
    <div class="col-lg-4 col-xl-3">
        <div class="lrms-card">
            <div class="lrms-card-head"><h2>Roles</h2></div>
            <div class="lrms-nav" style="padding:10px">
                <?php foreach ($roles as $role): ?>
                    <a class="lrms-nav-item<?= (int) $role['id'] === (int) ($selected['id'] ?? 0) ? ' active' : '' ?>"
                       href="<?= e(url('/roles?role_id=' . (int) $role['id'])) ?>">
                        <?= icon('shield') ?>
                        <span class="flex-grow-1">
                            <span class="d-block"><?= e($role['display_name']) ?></span>
                            <span class="d-block text-muted" style="font-size:.6875rem">
                                <?= e((string) (int) $role['user_count']) ?> user(s) ·
                                <?= e((string) (int) $role['permission_count']) ?> permission(s)
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Permission matrix -->
    <div class="col-lg-8 col-xl-9">
        <?php if ($selected === null): ?>
            <div class="lrms-card"><div class="lrms-card-body">No roles defined.</div></div>
        <?php else: ?>
            <form method="post" action="<?= e(url('/roles/' . (int) $selected['id'] . '/permissions')) ?>">
                <?= csrf_field() ?>

                <div class="lrms-card">
                    <div class="lrms-card-head">
                        <div>
                            <h2><?= e($selected['display_name']) ?></h2>
                            <p><?= e($selected['description'] ?? 'Permission set for this role') ?></p>
                        </div>
                        <span class="lrms-badge badge-promise">
                            <?= e((string) count($roleAssigned)) ?> granted
                        </span>
                    </div>

                    <?php if ($isSuperAdmin): ?>
                        <div class="lrms-card-body">
                            <div class="alert alert-info mb-0">
                                <?= icon('shield-check') ?>
                                <div>
                                    The <strong>Super Admin</strong> role always holds every permission, including
                                    any added by future modules. It is intentionally not editable &mdash; removing a
                                    permission here could lock every administrator out of the system.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="lrms-card-body<?= $isSuperAdmin ? ' pt-0' : '' ?>">
                        <?php foreach ($grouped as $module => $permissions): ?>
                            <fieldset class="lrms-fieldset" <?= $isSuperAdmin ? 'disabled' : '' ?>>
                                <legend class="d-flex align-items-center justify-content-between">
                                    <span><?= e($module) ?></span>
                                    <?php if (!$isSuperAdmin && $canManage): ?>
                                        <label class="d-flex align-items-center gap-2"
                                               style="font-size:.6875rem;text-transform:none;letter-spacing:0;font-weight:500;color:var(--lrms-slate)">
                                            <input type="checkbox" class="form-check-input mt-0"
                                                   data-module-toggle="<?= e($module) ?>">
                                            Select all
                                        </label>
                                    <?php endif; ?>
                                </legend>

                                <div class="lrms-check-grid">
                                    <?php foreach ($permissions as $permission): ?>
                                        <?php $isOn = $isSuperAdmin || isset($roleAssigned[(int) $permission['id']]); ?>
                                        <label class="lrms-check-tile">
                                            <input type="checkbox" class="form-check-input"
                                                   name="permissions[]"
                                                   value="<?= e((string) $permission['id']) ?>"
                                                   data-module="<?= e($module) ?>"
                                                   <?= $isOn ? 'checked' : '' ?>
                                                   <?= ($isSuperAdmin || !$canManage) ? 'disabled' : '' ?>>
                                            <span>
                                                <?= e($permission['display_name']) ?>
                                                <span class="d-block text-muted font-mono" style="font-size:.625rem">
                                                    <?= e($permission['code']) ?>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$isSuperAdmin && $canManage): ?>
                        <div class="lrms-card-foot d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <?= icon('check') ?> Save permissions
                            </button>
                            <a href="<?= e(url('/roles?role_id=' . (int) $selected['id'])) ?>"
                               class="btn btn-outline-secondary">Reset</a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
