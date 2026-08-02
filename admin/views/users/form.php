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
                : 'Create a branch manager or BC agent account' ?>
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
                            <div class="form-text">BC Agents sign in through the Android app only.</div>
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
                            <label class="form-label" for="bc_code">BC code</label>
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

            <div class="lrms-card mb-3">
                <div class="lrms-card-head">
                    <div>
                        <h2>BC Basic Details</h2>
                        <p>The bank's reporting hierarchy and the agent's registration numbers</p>
                    </div>
                </div>
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="sp_cbc_name">SP / CBC Name</label>
                            <input type="text" class="form-control<?= has_error($errors, 'sp_cbc_name') ?>"
                                   id="sp_cbc_name" name="sp_cbc_name" value="<?= $value('sp_cbc_name') ?>" maxlength="150">
                            <?= field_error($errors, 'sp_cbc_name') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="bc_name">BC Name</label>
                            <input type="text" class="form-control<?= has_error($errors, 'bc_name') ?>"
                                   id="bc_name" name="bc_name" value="<?= $value('bc_name') ?>" maxlength="150">
                            <?= field_error($errors, 'bc_name') ?>
                            <div class="form-text">The BC point's registered name, if different from the login name above.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="bcbf_code">BCBF Code</label>
                            <input type="text" class="form-control<?= has_error($errors, 'bcbf_code') ?>"
                                   id="bcbf_code" name="bcbf_code" value="<?= $value('bcbf_code') ?>" maxlength="40">
                            <?= field_error($errors, 'bcbf_code') ?>
                            <div class="form-text">Issued by the bank; separate from the BC code above.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="ssa">SSA</label>
                            <input type="text" class="form-control<?= has_error($errors, 'ssa') ?>"
                                   id="ssa" name="ssa" value="<?= $value('ssa') ?>" maxlength="150">
                            <?= field_error($errors, 'ssa') ?>
                            <div class="form-text">Sub Service Area covered.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="link_branch">Link Branch</label>
                            <input type="text" class="form-control<?= has_error($errors, 'link_branch') ?>"
                                   id="link_branch" name="link_branch" value="<?= $value('link_branch') ?>" maxlength="150">
                            <?= field_error($errors, 'link_branch') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="district">District</label>
                            <input type="text" class="form-control<?= has_error($errors, 'district') ?>"
                                   id="district" name="district" value="<?= $value('district') ?>" maxlength="100">
                            <?= field_error($errors, 'district') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="region_ro">Region (RO)</label>
                            <input type="text" class="form-control<?= has_error($errors, 'region_ro') ?>"
                                   id="region_ro" name="region_ro" value="<?= $value('region_ro') ?>" maxlength="100">
                            <?= field_error($errors, 'region_ro') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="iibf_number">IIBF No.</label>
                            <input type="text" class="form-control<?= has_error($errors, 'iibf_number') ?>"
                                   id="iibf_number" name="iibf_number" value="<?= $value('iibf_number') ?>" maxlength="40">
                            <?= field_error($errors, 'iibf_number') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="dra_name_id">DRA Name / ID</label>
                            <input type="text" class="form-control<?= has_error($errors, 'dra_name_id') ?>"
                                   id="dra_name_id" name="dra_name_id" value="<?= $value('dra_name_id') ?>" maxlength="150">
                            <?= field_error($errors, 'dra_name_id') ?>
                            <div class="form-text">If this BC works through a Direct Recovery Agent, their name or ID.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="aadhaar">Aadhaar Card No.</label>
                            <input type="text" class="form-control<?= has_error($errors, 'aadhaar') ?>"
                                   id="aadhaar" name="aadhaar"
                                   value="<?= array_key_exists('aadhaar', $old) ? e($old['aadhaar']) : e($user['aadhaar'] ?? '') ?>"
                                   maxlength="14" inputmode="numeric" placeholder="12-digit number" autocomplete="off">
                            <?= field_error($errors, 'aadhaar') ?>
                            <div class="form-text">Stored encrypted, same as a borrower's Aadhaar.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="pan">PAN Card No.</label>
                            <input type="text" class="form-control text-uppercase<?= has_error($errors, 'pan') ?>"
                                   id="pan" name="pan"
                                   value="<?= array_key_exists('pan', $old) ? e($old['pan']) : e($user['pan'] ?? '') ?>"
                                   maxlength="20" spellcheck="false" autocomplete="off">
                            <?= field_error($errors, 'pan') ?>
                            <div class="form-text">Stored encrypted.</div>
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
