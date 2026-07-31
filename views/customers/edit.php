<?php
/**
 * Borrower contact details. Loan figures are not editable: they come from the
 * core banking Excel import and would be overwritten by the next upload.
 *
 * @var array<string,mixed>        $lead
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

$value = static function (string $key, mixed $fallback = '') use ($old, $lead): string {
    if (array_key_exists($key, $old)) {
        return e($old[$key]);
    }
    return e($lead[$key] ?? $fallback);
};
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/customers')) ?>" class="text-muted">Customers &amp; Leads</a>
            <span class="text-muted mx-1">/</span>
            <a href="<?= e(url('/customers/' . (int) $lead['id'])) ?>" class="text-muted">
                <?= e($lead['loan_account_number']) ?>
            </a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted">Edit</span>
        </nav>
        <h1>Edit borrower</h1>
        <p>
            <span class="font-mono"><?= e($lead['loan_account_number']) ?></span>
            · <?= e($lead['branch_name']) ?>
        </p>
    </div>
</div>

<div class="alert alert-info">
    <?= icon('info') ?>
    <div>
        Loan amounts, NPA date and loan type are maintained by the Excel import and are not editable here &mdash;
        a manual change would be overwritten by the next upload. Only borrower contact details can be corrected.
    </div>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-6">
        <form method="post" action="<?= e(url('/customers/' . (int) $lead['id'] . '/edit')) ?>"
              novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card">
                <div class="lrms-card-head"><h2>Borrower details</h2></div>
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="name">Customer name <span class="req">*</span></label>
                            <input type="text" class="form-control<?= has_error($errors, 'name') ?>"
                                   id="name" name="name" value="<?= $value('customer_name') ?>"
                                   maxlength="150" required autofocus>
                            <?= field_error($errors, 'name') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="father_husband_name">Father / husband name</label>
                            <input type="text" class="form-control<?= has_error($errors, 'father_husband_name') ?>"
                                   id="father_husband_name" name="father_husband_name"
                                   value="<?= $value('father_husband_name') ?>" maxlength="150">
                            <?= field_error($errors, 'father_husband_name') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="mobile">Mobile</label>
                            <input type="tel" class="form-control<?= has_error($errors, 'mobile') ?>"
                                   id="mobile" name="mobile"
                                   value="<?= array_key_exists('mobile', $old) ? e($old['mobile']) : e($lead['mobile'] ?? '') ?>"
                                   maxlength="13" inputmode="numeric" placeholder="10-digit number">
                            <?= field_error($errors, 'mobile') ?>
                            <div class="form-text">Stored encrypted; a masked form is shown in lists.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="aadhaar">Aadhaar</label>
                            <input type="text" class="form-control<?= has_error($errors, 'aadhaar') ?>"
                                   id="aadhaar" name="aadhaar"
                                   value="<?= array_key_exists('aadhaar', $old) ? e($old['aadhaar']) : e($lead['aadhaar'] ?? '') ?>"
                                   maxlength="14" inputmode="numeric" placeholder="12 digits">
                            <?= field_error($errors, 'aadhaar') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="village">Village</label>
                            <input type="text" class="form-control<?= has_error($errors, 'village') ?>"
                                   id="village" name="village" value="<?= $value('village') ?>" maxlength="150">
                            <?= field_error($errors, 'village') ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="address">Address</label>
                            <textarea class="form-control<?= has_error($errors, 'address') ?>" id="address"
                                      name="address" rows="3" maxlength="500"><?= $value('address') ?></textarea>
                            <?= field_error($errors, 'address') ?>
                        </div>
                    </div>
                </div>

                <div class="lrms-card-foot d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?> Save changes</button>
                    <a href="<?= e(url('/customers/' . (int) $lead['id'])) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
