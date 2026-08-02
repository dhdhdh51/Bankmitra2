<?php
/**
 * Borrower contact details and loan figures, all editable. Every figure changed here
 * is stamped into loan_accounts.manual_overrides so the next import leaves it alone and
 * reports that it skipped it.
 *
 * Reached from the page header or from either card on the borrower profile, whose links
 * carry #borrower / #loan so somebody correcting one field is not dropped at the top of
 * the whole form.
 *
 * This file's own comment used to say loan figures were not editable because the import
 * would overwrite them. That stopped being true when the override tracking landed, and
 * the stale comment is worth mentioning only because it is exactly the kind that gets
 * believed by the next person reading it.
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
        Loan figures come from the Excel import. You can correct them here, and any figure
        you change is <strong>marked as hand-edited</strong> so later imports leave it alone
        and report that they skipped it. Clear the override from the loan record if you want
        the import to take over that figure again.
    </div>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-6">
        <form method="post" action="<?= e(url('/customers/' . (int) $lead['id'] . '/edit')) ?>"
              novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card" id="borrower">
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

                    <?php if (($customerFields ?? []) !== []): ?>
                        <hr class="my-3">
                        <h3 class="h6 mb-2">Additional borrower details</h3>
                        <?= \App\Core\View::partial('partials/custom-fields', [
                            'fields' => $customerFields,
                            'old'    => $old,
                            'errors' => $errors,
                        ]) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lrms-card mt-3" id="loan">
                <div class="lrms-card-head"><h2>Loan figures</h2></div>
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <?php
                        $moneyFields = [
                            'outstanding_amount'  => 'Outstanding amount',
                            'overdue_amount'      => 'Overdue amount',
                            'closure_amount'      => 'Closure amount',
                            'ots_amount'          => 'OTS amount',
                            'deposit_amount'      => 'Deposit amount',
                            'installment_amount'  => 'Instalment / EMI',
                            'last_payment_amount' => 'Last payment amount',
                            'security_value'      => 'Security value',
                        ];
                        $overriddenSet = array_flip($overridden ?? []);
                        ?>

                        <div class="col-md-6">
                            <label class="form-label" for="loan_type">Loan type</label>
                            <input type="text" class="form-control<?= has_error($errors, 'loan_type') ?>"
                                   id="loan_type" name="loan_type" value="<?= $value('loan_type') ?>" maxlength="80">
                            <?= field_error($errors, 'loan_type') ?>
                            <?php if (isset($overriddenSet['loan_type'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="cif_number">CIF number</label>
                            <input type="text" class="form-control<?= has_error($errors, 'cif_number') ?>"
                                   id="cif_number" name="cif_number" value="<?= $value('cif_number') ?>" maxlength="40">
                            <?= field_error($errors, 'cif_number') ?>
                        </div>

                        <?php foreach ($moneyFields as $column => $label): ?>
                            <div class="col-md-4">
                                <label class="form-label" for="<?= e($column) ?>"><?= e($label) ?> (&#8377;)</label>
                                <input type="number" class="form-control<?= has_error($errors, $column) ?>"
                                       id="<?= e($column) ?>" name="<?= e($column) ?>"
                                       value="<?= $value($column) ?>" min="0" step="0.01" inputmode="decimal">
                                <?= field_error($errors, $column) ?>
                                <?php if ($column === 'closure_amount'): ?>
                                    <div class="form-text">What the borrower must pay to close the account outright.</div>
                                <?php endif; ?>
                                <?php if (isset($overriddenSet[$column])): ?>
                                    <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="npa_date">NPA date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'npa_date') ?>"
                                   id="npa_date" name="npa_date" value="<?= $value('npa_date') ?>">
                            <?= field_error($errors, 'npa_date') ?>
                            <div class="form-text">Clearing it removes the NPA flag too.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="facility_type">Facility</label>
                            <?php $currentFacility = $value('facility_type'); ?>
                            <select class="form-select<?= has_error($errors, 'facility_type') ?>"
                                    id="facility_type" name="facility_type">
                                <option value="">Not determined</option>
                                <?php foreach (\App\Models\LoanAccount::FACILITIES as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $currentFacility === $key ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'facility_type') ?>
                            <div class="form-text">
                                Read off the loan type on import. KCC and OD-2 have their own
                                renewal worklists, so this decides which one this account appears in.
                            </div>
                            <?php if (isset($overriddenSet['facility_type'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="asset_classification">Asset classification</label>
                            <input type="text" class="form-control<?= has_error($errors, 'asset_classification') ?>"
                                   id="asset_classification" name="asset_classification"
                                   value="<?= $value('asset_classification') ?>" maxlength="40"
                                   list="classification_options">
                            <datalist id="classification_options">
                                <?php foreach (['Standard', 'SMA-0', 'SMA-1', 'SMA-2', 'Sub-Standard',
                                                'Doubtful-1', 'Doubtful-2', 'Doubtful-3', 'Loss'] as $option): ?>
                                    <option value="<?= e($option) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <?= field_error($errors, 'asset_classification') ?>
                            <div class="form-text">Whatever the bank's statement says. Suggestions are the canonical spellings.</div>
                            <?php if (isset($overriddenSet['asset_classification'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="interest_rate">Interest rate (% p.a.)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'interest_rate') ?>"
                                   id="interest_rate" name="interest_rate"
                                   value="<?= $value('interest_rate') ?>" min="0" step="0.001" inputmode="decimal">
                            <?= field_error($errors, 'interest_rate') ?>
                            <?php if (isset($overriddenSet['interest_rate'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="days_past_due">Days past due</label>
                            <input type="number" class="form-control<?= has_error($errors, 'days_past_due') ?>"
                                   id="days_past_due" name="days_past_due"
                                   value="<?= $value('days_past_due') ?>" min="0" step="1" inputmode="numeric">
                            <?= field_error($errors, 'days_past_due') ?>
                            <div class="form-text">As the bank computed it, not derived here.</div>
                            <?php if (isset($overriddenSet['days_past_due'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="guarantor_name">Guarantor name</label>
                            <input type="text" class="form-control<?= has_error($errors, 'guarantor_name') ?>"
                                   id="guarantor_name" name="guarantor_name"
                                   value="<?= $value('guarantor_name') ?>" maxlength="150">
                            <?= field_error($errors, 'guarantor_name') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="purpose">Purpose / activity</label>
                            <input type="text" class="form-control<?= has_error($errors, 'purpose') ?>"
                                   id="purpose" name="purpose"
                                   value="<?= $value('purpose') ?>" maxlength="150">
                            <?= field_error($errors, 'purpose') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="last_payment_date">Last payment date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'last_payment_date') ?>"
                                   id="last_payment_date" name="last_payment_date"
                                   value="<?= $value('last_payment_date') ?>">
                            <?= field_error($errors, 'last_payment_date') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="maturity_date">Maturity date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'maturity_date') ?>"
                                   id="maturity_date" name="maturity_date"
                                   value="<?= $value('maturity_date') ?>">
                            <?= field_error($errors, 'maturity_date') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="ckcc_renewal_due_date">CKCC renewal due date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'ckcc_renewal_due_date') ?>"
                                   id="ckcc_renewal_due_date" name="ckcc_renewal_due_date"
                                   value="<?= $value('ckcc_renewal_due_date') ?>">
                            <?= field_error($errors, 'ckcc_renewal_due_date') ?>
                        </div>
                    </div>

                    <?php if (($loanFields ?? []) !== []): ?>
                        <hr class="my-3">
                        <h3 class="h6 mb-2">Additional loan details</h3>
                        <?= \App\Core\View::partial('partials/custom-fields', [
                            'fields' => $loanFields,
                            'old'    => $old,
                            'errors' => $errors,
                        ]) ?>
                    <?php endif; ?>
                </div>

                <div class="lrms-card-foot d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?> Save changes</button>
                    <a href="<?= e(url('/customers/' . (int) $lead['id'])) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
