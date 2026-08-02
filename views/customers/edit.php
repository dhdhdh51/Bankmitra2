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

                        <?php
                        /*
                         * The second number, and whose it is.
                         *
                         * Before this an agent at the door had two bad options: overwrite
                         * the number the bank was given at sanction, or write the working
                         * number into a remarks field where nothing can dial it. The label
                         * matters as much as the number - "who am I speaking to" is the
                         * whole of a recovery call's first ten seconds - and no importer
                         * touches either column, so what is collected here stays.
                         */
                        ?>
                        <div class="col-md-6">
                            <label class="form-label" for="alt_mobile">Second mobile</label>
                            <input type="tel" class="form-control<?= has_error($errors, 'alt_mobile') ?>"
                                   id="alt_mobile" name="alt_mobile" maxlength="13" inputmode="numeric"
                                   value="<?= array_key_exists('alt_mobile', $old) ? e($old['alt_mobile']) : e($lead['alt_mobile'] ?? '') ?>">
                            <?= field_error($errors, 'alt_mobile') ?>
                            <div class="form-text">A number that actually reaches them. Searchable like the first.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="alt_mobile_label">Whose number is it</label>
                            <input type="text" class="form-control<?= has_error($errors, 'alt_mobile_label') ?>"
                                   id="alt_mobile_label" name="alt_mobile_label" maxlength="60"
                                   list="alt_mobile_label_options" placeholder="Son, brother, shop&hellip;"
                                   value="<?= array_key_exists('alt_mobile_label', $old) ? e($old['alt_mobile_label']) : e($lead['alt_mobile_label'] ?? '') ?>">
                            <datalist id="alt_mobile_label_options">
                                <option value="Son"></option>
                                <option value="Daughter"></option>
                                <option value="Wife"></option>
                                <option value="Husband"></option>
                                <option value="Brother"></option>
                                <option value="Neighbour"></option>
                                <option value="Shop"></option>
                                <option value="Guarantor"></option>
                            </datalist>
                            <?= field_error($errors, 'alt_mobile_label') ?>
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

                        <?php
                        /*
                         * The account number, editable.
                         *
                         * It is the identity of the row and the key an import matches on, so
                         * it was read-only - which left a number typed wrong at creation
                         * wrong forever. It can be corrected now, a collision is refused
                         * with the name of the borrower who holds it, and the rename gets
                         * its own timeline entry because every visit and promise already
                         * attached to this row keeps pointing at it under the new name.
                         */
                        ?>
                        <div class="col-md-6">
                            <label class="form-label" for="loan_account_number">Loan account number</label>
                            <input type="text" class="form-control font-mono<?= has_error($errors, 'loan_account_number') ?>"
                                   id="loan_account_number" name="loan_account_number" maxlength="60" required
                                   value="<?= $value('loan_account_number') ?>">
                            <?= field_error($errors, 'loan_account_number') ?>
                            <div class="form-text">
                                Correcting this renames the account everywhere &mdash; the visits and promises
                                already recorded stay with it.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="current_status">Status</label>
                            <select class="form-select<?= has_error($errors, 'current_status') ?>"
                                    id="current_status" name="current_status">
                                <?php foreach (\App\Models\LoanAccount::STATUSES as $statusOption): ?>
                                    <option value="<?= e($statusOption) ?>"
                                        <?= (string) ($old['current_status'] ?? $lead['current_status']) === $statusOption ? 'selected' : '' ?>>
                                        <?= e(ucfirst($statusOption)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'current_status') ?>
                            <div class="form-text">
                                Normally moves on its own when a visit or a promise is filed. Changing it here
                                is recorded in the timeline like any other status change.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="bc_code">BC code on the account</label>
                            <input type="text" class="form-control<?= has_error($errors, 'bc_code') ?>"
                                   id="bc_code" name="bc_code" maxlength="40"
                                   value="<?= $value('bc_code') ?>">
                            <?= field_error($errors, 'bc_code') ?>
                            <?php if (isset($overriddenSet['bc_code'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

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
                            <label class="form-label" for="npa_date">Probable NPA date / NPA date</label>
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

                        <?php
                        /*
                         * The sanction side of the passbook. These were import-owned and
                         * unreachable, which made the passbook a borrower holds out at the
                         * door useless: the agent could read the sanction limit straight
                         * off it and had nowhere to put it.
                         */
                        ?>
                        <div class="col-md-4">
                            <label class="form-label" for="sanction_date">Sanction date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'sanction_date') ?>"
                                   id="sanction_date" name="sanction_date"
                                   value="<?= $value('sanction_date') ?>">
                            <?= field_error($errors, 'sanction_date') ?>
                            <?php if (isset($overriddenSet['sanction_date'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="sanction_limit">Sanction limit (&#8377;)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'sanction_limit') ?>"
                                   id="sanction_limit" name="sanction_limit" min="0" step="0.01" inputmode="decimal"
                                   value="<?= $value('sanction_limit') ?>">
                            <?= field_error($errors, 'sanction_limit') ?>
                            <?php if (isset($overriddenSet['sanction_limit'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="drawing_power">Drawing power (&#8377;)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'drawing_power') ?>"
                                   id="drawing_power" name="drawing_power" min="0" step="0.01" inputmode="decimal"
                                   value="<?= $value('drawing_power') ?>">
                            <?= field_error($errors, 'drawing_power') ?>
                            <?php if (isset($overriddenSet['drawing_power'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="interest_overdue">Interest overdue (&#8377;)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'interest_overdue') ?>"
                                   id="interest_overdue" name="interest_overdue" min="0" step="0.01" inputmode="decimal"
                                   value="<?= $value('interest_overdue') ?>">
                            <?= field_error($errors, 'interest_overdue') ?>
                            <?php if (isset($overriddenSet['interest_overdue'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
                        </div>

                        <?php
                        /*
                         * The settlement / renewal eligibility flags the file carries.
                         *
                         * Three-state on purpose: "no" and "the file never said" are
                         * different facts about an account and the customer sheet prints
                         * them differently, so a checkbox - which cannot tell them apart -
                         * would quietly turn every silence into a no.
                         */
                        ?>
                        <?php foreach ([
                            'ots_eligible' => 'Eligible for OTS',
                            'krm_eligible' => 'Eligible for KRM',
                        ] as $flag => $flagLabel): ?>
                            <div class="col-md-3">
                                <label class="form-label" for="<?= e($flag) ?>"><?= e($flagLabel) ?></label>
                                <?php $flagValue = $old[$flag] ?? ($lead[$flag] === null ? '' : (string) (int) $lead[$flag]); ?>
                                <select class="form-select<?= has_error($errors, $flag) ?>"
                                        id="<?= e($flag) ?>" name="<?= e($flag) ?>">
                                    <option value="" <?= (string) $flagValue === '' ? 'selected' : '' ?>>Not stated</option>
                                    <option value="1" <?= (string) $flagValue === '1' ? 'selected' : '' ?>>Yes</option>
                                    <option value="0" <?= (string) $flagValue === '0' ? 'selected' : '' ?>>No</option>
                                </select>
                                <?= field_error($errors, $flag) ?>
                                <?php if (isset($overriddenSet[$flag])): ?>
                                    <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="next_followup_date">Next follow-up date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'next_followup_date') ?>"
                                   id="next_followup_date" name="next_followup_date"
                                   value="<?= $value('next_followup_date') ?>">
                            <?= field_error($errors, 'next_followup_date') ?>
                            <div class="form-text">
                                Filing a promise sets this by itself; a date typed here holds until then.
                            </div>
                        </div>

                        <?php
                        /*
                         * The field that gets used most, and the one that was missing.
                         *
                         * What an agent learns at a doorstep is rarely a number - "shifted
                         * to Delhi, brother works the land", "wife says he is in hospital",
                         * "shop is shut, neighbours say he sold the buffalo". There was
                         * nowhere for any of it, so it stayed in somebody's notebook. A
                         * visit report captures one visit; this is what is true about the
                         * account, and it prints on the customer sheet.
                         */
                        ?>
                        <div class="col-12">
                            <label class="form-label" for="remarks">Notes on this account</label>
                            <textarea class="form-control<?= has_error($errors, 'remarks') ?>"
                                      id="remarks" name="remarks" rows="3" maxlength="1000"
                                      placeholder="What somebody opening this account next needs to know"><?= $value('remarks') ?></textarea>
                            <?= field_error($errors, 'remarks') ?>
                            <div class="form-text">
                                Standing notes about the account, not a visit report. Visits keep their own
                                remarks and are append-only.
                            </div>
                            <?php if (isset($overriddenSet['remarks'])): ?>
                                <div class="form-text text-warning">Hand-edited &mdash; imports skip this.</div>
                            <?php endif; ?>
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
