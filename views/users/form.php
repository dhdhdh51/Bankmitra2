<?php
/**
 * @var array<string,mixed>|null   $user     null when creating
 * @var list<array<string,mixed>>  $roles
 * @var list<array<string,mixed>>  $branches
 * @var string|null                $mobile   decrypted, edit only
 * @var string|null                $generatedPass
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

$isEdit = $user !== null;
$action = $isEdit ? url('/users/' . (int) $user['id'] . '/edit') : url('/users/create');

$value = static function (string $key, mixed $fallback = '') use ($old, $user): string {
    if (array_key_exists($key, $old)) {
        return e($old[$key]);
    }
    if ($user !== null && array_key_exists($key, $user)) {
        return e($user[$key]);
    }
    return e($fallback);
};

$currentRoleId = (string) ($old['role_id'] ?? ($user['role_id'] ?? ''));
$currentBranchId = (string) ($old['branch_id'] ?? ($user['branch_id'] ?? ''));
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/users')) ?>" class="text-muted">Managers &amp; Agents</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted"><?= $isEdit ? 'Edit' : 'New' ?></span>
        </nav>
        <h1><?= $isEdit ? 'Edit user' : 'Add user' ?></h1>
        <p>
            <?= $isEdit
                ? e((string) $user['name']) . ' · ' . e((string) $user['employee_code'])
                : 'Create a branch manager or BC/DC agent account' ?>
        </p>
    </div>
</div>

<div class="row">
    <div class="col-lg-9 col-xl-7">
        <form method="post" action="<?= e($action) ?>" novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card mb-3">
                <div class="lrms-card-head"><h2>Identity</h2></div>
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="employee_code">Employee code <span class="req">*</span></label>
                            <input type="text" class="form-control text-uppercase<?= has_error($errors, 'employee_code') ?>"
                                   id="employee_code" name="employee_code" value="<?= $value('employee_code') ?>"
                                   maxlength="40" required autofocus spellcheck="false" placeholder="e.g. AGT001">
                            <?= field_error($errors, 'employee_code') ?>
                            <div class="form-text">This is the login identifier.</div>
                        </div>

                        <div class="col-md-7">
                            <label class="form-label" for="name">Full name <span class="req">*</span></label>
                            <input type="text" class="form-control<?= has_error($errors, 'name') ?>"
                                   id="name" name="name" value="<?= $value('name') ?>" maxlength="150" required>
                            <?= field_error($errors, 'name') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="mobile">Mobile</label>
                            <input type="tel" class="form-control<?= has_error($errors, 'mobile') ?>"
                                   id="mobile" name="mobile"
                                   value="<?= array_key_exists('mobile', $old) ? e($old['mobile']) : e($mobile ?? '') ?>"
                                   maxlength="13" inputmode="numeric" placeholder="10-digit number">
                            <?= field_error($errors, 'mobile') ?>
                            <div class="form-text">Stored encrypted. Also usable as an alternative login and for OTP resets.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" class="form-control<?= has_error($errors, 'email') ?>"
                                   id="email" name="email" value="<?= $value('email') ?>" maxlength="190">
                            <?= field_error($errors, 'email') ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lrms-card mb-3">
                <div class="lrms-card-head"><h2>Role &amp; posting</h2></div>
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="role_id">Role <span class="req">*</span></label>
                            <select class="form-select<?= has_error($errors, 'role_id') ?>" id="role_id" name="role_id" required>
                                <option value="">Select role…</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= e((string) $role['id']) ?>"
                                        <?= $currentRoleId === (string) $role['id'] ? 'selected' : '' ?>>
                                        <?= e($role['display_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'role_id') ?>
                            <div class="form-text">BC/DC Agents sign in through the Android app only.</div>
                        </div>

                        <?php if (count($branches) > 1): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="branch_id">Branch</label>
                                <select class="form-select" id="branch_id" name="branch_id">
                                    <option value="">None (Super Admin only)</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= e((string) $branch['id']) ?>"
                                            <?= $currentBranchId === (string) $branch['id'] ? 'selected' : '' ?>>
                                            <?= e($branch['name']) ?> (<?= e($branch['branch_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Required for every role except Super Admin.</div>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="branch_id" value="<?= e((string) ($branches[0]['id'] ?? '')) ?>">
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="bc_code">BC / DC code</label>
                            <input type="text" class="form-control<?= has_error($errors, 'bc_code') ?>"
                                   id="bc_code" name="bc_code" value="<?= $value('bc_code') ?>" maxlength="40">
                            <?= field_error($errors, 'bc_code') ?>
                            <div class="form-text">Printed on the agent's field visit reports.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="designation">Designation</label>
                            <input type="text" class="form-control" id="designation" name="designation"
                                   value="<?= $value('designation') ?>" maxlength="100">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="status">Status <span class="req">*</span></label>
                            <?php $currentStatus = $value('status', 'active'); ?>
                            <select class="form-select" id="status" name="status" required>
                                <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= $currentStatus === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isEdit): ?>
                <div class="lrms-card mb-3">
                    <div class="lrms-card-head">
                        <div>
                            <h2>Initial password</h2>
                            <p>The user must change this at their first sign-in</p>
                        </div>
                    </div>
                    <div class="lrms-card-body">
                        <label class="form-label" for="password">Temporary password</label>
                        <input type="text" class="form-control<?= has_error($errors, 'password') ?>"
                               id="password" name="password"
                               value="<?= array_key_exists('password', $old) ? '' : e($generatedPass ?? '') ?>"
                               autocomplete="off">
                        <?= field_error($errors, 'password') ?>
                        <div class="form-text">
                            Leave blank to generate one automatically. It is displayed once after saving.
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <?= icon('info') ?>
                    <div>
                        Passwords are not editable here. Use <strong>Reset password</strong> from the user
                        list to issue a new temporary password.
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Create user' ?>
                </button>
                <a href="<?= e(url('/users')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
