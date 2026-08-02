<?php
/**
 * @var array<string,mixed>|null   $branch  null when creating
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

$isEdit = $branch !== null;
$action = $isEdit ? url('/branches/' . (int) $branch['id'] . '/edit') : url('/branches/create');

/** Old input wins (failed validation), then the stored row. */
$value = static function (string $key, mixed $fallback = '') use ($old, $branch): string {
    if (array_key_exists($key, $old)) {
        return e($old[$key]);
    }
    if ($branch !== null && array_key_exists($key, $branch)) {
        return e($branch[$key]);
    }
    return e($fallback);
};
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/branches')) ?>" class="text-muted">Branches</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted"><?= $isEdit ? 'Edit' : 'New' ?></span>
        </nav>
        <h1><?= $isEdit ? 'Edit branch' : 'Add branch' ?></h1>
        <p><?= $isEdit ? e((string) $branch['name']) : 'Create a new branch for BC/DC operations' ?></p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-6">
        <form method="post" action="<?= e($action) ?>" novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card">
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="branch_code">Branch code <span class="req">*</span></label>
                            <input type="text" class="form-control text-uppercase<?= has_error($errors, 'branch_code') ?>"
                                   id="branch_code" name="branch_code" value="<?= $value('branch_code') ?>"
                                   maxlength="30" required autofocus placeholder="e.g. BR001" spellcheck="false">
                            <?= field_error($errors, 'branch_code') ?>
                            <div class="form-text">Used to auto-map rows during Excel import.</div>
                        </div>

                        <div class="col-md-7">
                            <label class="form-label" for="name">Branch name <span class="req">*</span></label>
                            <input type="text" class="form-control<?= has_error($errors, 'name') ?>"
                                   id="name" name="name" value="<?= $value('name') ?>" maxlength="150" required>
                            <?= field_error($errors, 'name') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="district">District</label>
                            <input type="text" class="form-control<?= has_error($errors, 'district') ?>"
                                   id="district" name="district" value="<?= $value('district') ?>" maxlength="100">
                            <?= field_error($errors, 'district') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="state">State</label>
                            <input type="text" class="form-control<?= has_error($errors, 'state') ?>"
                                   id="state" name="state" value="<?= $value('state') ?>" maxlength="100">
                            <?= field_error($errors, 'state') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="pincode">PIN code</label>
                            <input type="text" class="form-control<?= has_error($errors, 'pincode') ?>"
                                   id="pincode" name="pincode" value="<?= $value('pincode') ?>"
                                   maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="6 digits">
                            <?= field_error($errors, 'pincode') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="regional_office">Regional office</label>
                            <input type="text" class="form-control<?= has_error($errors, 'regional_office') ?>"
                                   id="regional_office" name="regional_office"
                                   value="<?= $value('regional_office') ?>" maxlength="150">
                            <?= field_error($errors, 'regional_office') ?>
                            <div class="form-text">Printed at the top of every field visit report.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="zone">Zone</label>
                            <input type="text" class="form-control<?= has_error($errors, 'zone') ?>"
                                   id="zone" name="zone" value="<?= $value('zone') ?>" maxlength="150">
                            <?= field_error($errors, 'zone') ?>
                            <div class="form-text">Filled in on the report for you, so nobody retypes it at a doorstep.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="status">Status <span class="req">*</span></label>
                            <?php $currentStatus = $value('status', 'active'); ?>
                            <select class="form-select<?= has_error($errors, 'status') ?>" id="status" name="status" required>
                                <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <?= field_error($errors, 'status') ?>
                            <div class="form-text">Inactive branches are hidden from assignment pickers.</div>
                        </div>
                    </div>
                </div>

                <div class="lrms-card-foot d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Create branch' ?>
                    </button>
                    <a href="<?= e(url('/branches')) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
