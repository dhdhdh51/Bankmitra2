<?php
/**
 * Add a borrower and a loan account by hand.
 *
 * A deliberately separate view from customers/edit.php rather than one template with
 * `$isEdit` running through it. The edit form's whole spine is the manual-override
 * machinery - the banner, the per-field "hand-edited, imports skip this" warnings, the
 * fields it refuses to show because assignment and status live elsewhere. None of that
 * exists for a row that does not exist yet, and threading a flag through 300 lines to
 * switch it all off produces a form where you cannot tell which half you are reading.
 *
 * @var array<string,mixed>|null      $existing    borrower this account is being added to
 * @var list<array<string,mixed>>     $branches
 * @var list<array<string,mixed>>     $agents      empty when the caller is an agent
 * @var int|null                      $ownAgentId
 * @var array<string,string>          $facilities
 * @var array<string,mixed>           $old
 * @var array<string,list<string>>    $errors
 */

$value = static fn (string $key, string $fallback = ''): string => e($old[$key] ?? $fallback);
$action = url('/customers/create') . ($existing !== null ? '?customer_id=' . (int) $existing['id'] : '');
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/customers')) ?>" class="text-muted">
                <?= is_agent() ? 'My Borrowers' : 'Customers &amp; Leads' ?>
            </a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted"><?= $existing === null ? 'New borrower' : 'New loan account' ?></span>
        </nav>
        <h1><?= $existing === null ? 'Add borrower' : 'Add another loan account' ?></h1>
        <?php if ($existing === null): ?>
            <p>For an account the bank&rsquo;s Excel export has not reached yet</p>
        <?php else: ?>
            <p>
                A second account for <strong><?= e((string) $existing['name']) ?></strong>
                <?php if (!empty($existing['village'])): ?> &middot; <?= e((string) $existing['village']) ?><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info">
    <?= icon('info') ?>
    <div>
        <strong>The next import wins.</strong>
        Figures typed here are a placeholder until this account appears in an import from the
        bank&rsquo;s export &mdash; then the core banking numbers replace them. A figure you
        correct later from the borrower&rsquo;s own page is treated differently: that one is
        stamped as hand-edited and imports leave it alone.
    </div>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-6">
        <form method="post" action="<?= e($action) ?>" novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <?php if ($existing === null): ?>
                <div class="lrms-card" id="borrower">
                    <div class="lrms-card-head"><h2>Borrower details</h2></div>
                    <div class="lrms-card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control<?= has_error($errors, 'name') ?>"
                                       id="name" name="name" maxlength="150" required autofocus
                                       value="<?= $value('name') ?>">
                                <?= field_error($errors, 'name') ?>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="father_husband_name">Father / husband name</label>
                                <input type="text" class="form-control<?= has_error($errors, 'father_husband_name') ?>"
                                       id="father_husband_name" name="father_husband_name" maxlength="150"
                                       value="<?= $value('father_husband_name') ?>">
                                <?= field_error($errors, 'father_husband_name') ?>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="mobile">Mobile</label>
                                <input type="tel" class="form-control<?= has_error($errors, 'mobile') ?>"
                                       id="mobile" name="mobile" maxlength="13" inputmode="numeric"
                                       value="<?= $value('mobile') ?>">
                                <?= field_error($errors, 'mobile') ?>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="alt_mobile">Second mobile</label>
                                <input type="tel" class="form-control<?= has_error($errors, 'alt_mobile') ?>"
                                       id="alt_mobile" name="alt_mobile" maxlength="13" inputmode="numeric"
                                       value="<?= $value('alt_mobile') ?>">
                                <?= field_error($errors, 'alt_mobile') ?>
                                <div class="form-text">The number that actually reaches them, if it is not theirs.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="alt_mobile_label">Whose number is it</label>
                                <input type="text" class="form-control<?= has_error($errors, 'alt_mobile_label') ?>"
                                       id="alt_mobile_label" name="alt_mobile_label" maxlength="60"
                                       list="alt_mobile_label_options" placeholder="Son, brother, shop&hellip;"
                                       value="<?= $value('alt_mobile_label') ?>">
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
                                       id="aadhaar" name="aadhaar" maxlength="14" inputmode="numeric"
                                       value="<?= $value('aadhaar') ?>">
                                <?= field_error($errors, 'aadhaar') ?>
                                <div class="form-text">Stored encrypted and shown masked.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="village">Village</label>
                                <input type="text" class="form-control<?= has_error($errors, 'village') ?>"
                                       id="village" name="village" maxlength="150"
                                       value="<?= $value('village') ?>">
                                <?= field_error($errors, 'village') ?>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="address">Address</label>
                                <textarea class="form-control<?= has_error($errors, 'address') ?>"
                                          id="address" name="address" rows="2" maxlength="500"><?= $value('address') ?></textarea>
                                <?= field_error($errors, 'address') ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="lrms-card">
                    <div class="lrms-card-head"><h2>Borrower</h2></div>
                    <div class="lrms-card-body">
                        <p class="mb-1" style="font-weight:620"><?= e((string) $existing['name']) ?></p>
                        <p class="text-muted mb-0" style="font-size:.8125rem">
                            <?php if (!empty($existing['father_husband_name'])): ?>
                                C/o <?= e((string) $existing['father_husband_name']) ?> &middot;
                            <?php endif; ?>
                            <?= e((string) ($existing['branch_name'] ?? '')) ?>
                            <?php if (!empty($existing['village'])): ?> &middot; <?= e((string) $existing['village']) ?><?php endif; ?>
                        </p>
                        <p class="text-muted mb-0 mt-2" style="font-size:.75rem">
                            Their details are not asked for again here. Correct them from the
                            borrower&rsquo;s own page if they are wrong.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="lrms-card mt-3" id="loan">
                <div class="lrms-card-head"><h2>Loan account</h2></div>
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="loan_account_number">
                                Loan account number <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control font-mono<?= has_error($errors, 'loan_account_number') ?>"
                                   id="loan_account_number" name="loan_account_number" maxlength="60" required
                                   <?= $existing !== null ? 'autofocus' : '' ?>
                                   value="<?= $value('loan_account_number') ?>">
                            <?= field_error($errors, 'loan_account_number') ?>
                            <div class="form-text">Must not already exist &mdash; it is how an import finds this account later.</div>
                        </div>

                        <?php
                        /*
                         * Branch. A scoped user gets a hidden field they cannot change: the
                         * account is created in their own branch or not at all. Only a super
                         * admin sees a choice, and Branch::options() has already limited the
                         * list, so the select cannot offer a branch the caller may not use.
                         */
                        ?>
                        <?php if ($existing !== null): ?>
                            <div class="col-md-6">
                                <label class="form-label">Branch</label>
                                <p class="form-control-plaintext mb-0">
                                    <?= e((string) ($existing['branch_name'] ?? '')) ?>
                                </p>
                                <div class="form-text">The borrower&rsquo;s branch. Moving an account between branches is a transfer.</div>
                            </div>
                        <?php elseif (count($branches) > 1): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="branch_id">Branch <span class="text-danger">*</span></label>
                                <select class="form-select<?= has_error($errors, 'branch_id') ?>"
                                        id="branch_id" name="branch_id" required>
                                    <option value="">Choose a branch&hellip;</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= e((string) $branch['id']) ?>"
                                            <?= ($old['branch_id'] ?? '') === (string) $branch['id'] ? 'selected' : '' ?>>
                                            <?= e((string) $branch['name']) ?> (<?= e((string) $branch['branch_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error($errors, 'branch_id') ?>
                            </div>
                        <?php elseif ($branches !== []): ?>
                            <div class="col-md-6">
                                <label class="form-label">Branch</label>
                                <p class="form-control-plaintext mb-0"><?= e((string) $branches[0]['name']) ?></p>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="loan_type">Loan type</label>
                            <input type="text" class="form-control<?= has_error($errors, 'loan_type') ?>"
                                   id="loan_type" name="loan_type" maxlength="80" list="loan_type_options"
                                   value="<?= $value('loan_type') ?>">
                            <datalist id="loan_type_options">
                                <option value="KCC"></option>
                                <option value="KCC OD-2"></option>
                                <option value="Crop Loan"></option>
                                <option value="Term Loan"></option>
                                <option value="Cash Credit"></option>
                                <option value="Gold Loan"></option>
                                <option value="Housing Loan"></option>
                            </datalist>
                            <?= field_error($errors, 'loan_type') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="facility_type">Facility</label>
                            <select class="form-select<?= has_error($errors, 'facility_type') ?>"
                                    id="facility_type" name="facility_type">
                                <option value="">Not determined</option>
                                <?php foreach ($facilities as $key => $label): ?>
                                    <option value="<?= e($key) ?>"
                                        <?= ($old['facility_type'] ?? '') === $key ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'facility_type') ?>
                            <div class="form-text">Decides which renewal worklist this account appears on.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="outstanding_amount">Outstanding (&#8377;)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'outstanding_amount') ?>"
                                   id="outstanding_amount" name="outstanding_amount" min="0" step="0.01"
                                   inputmode="decimal" value="<?= $value('outstanding_amount') ?>">
                            <?= field_error($errors, 'outstanding_amount') ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="overdue_amount">Overdue (&#8377;)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'overdue_amount') ?>"
                                   id="overdue_amount" name="overdue_amount" min="0" step="0.01"
                                   inputmode="decimal" value="<?= $value('overdue_amount') ?>">
                            <?= field_error($errors, 'overdue_amount') ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="cif_number">CIF number</label>
                            <input type="text" class="form-control<?= has_error($errors, 'cif_number') ?>"
                                   id="cif_number" name="cif_number" maxlength="40"
                                   value="<?= $value('cif_number') ?>">
                            <?= field_error($errors, 'cif_number') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="npa_date">Probable NPA date / NPA date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'npa_date') ?>"
                                   id="npa_date" name="npa_date" value="<?= $value('npa_date') ?>">
                            <?= field_error($errors, 'npa_date') ?>
                            <div class="form-text">Setting it marks the account NPA.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="ckcc_renewal_due_date">CKCC renewal due date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'ckcc_renewal_due_date') ?>"
                                   id="ckcc_renewal_due_date" name="ckcc_renewal_due_date"
                                   value="<?= $value('ckcc_renewal_due_date') ?>">
                            <?= field_error($errors, 'ckcc_renewal_due_date') ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="asset_classification">Asset classification</label>
                            <input type="text" class="form-control<?= has_error($errors, 'asset_classification') ?>"
                                   id="asset_classification" name="asset_classification" maxlength="40"
                                   list="classification_options" value="<?= $value('asset_classification') ?>">
                            <datalist id="classification_options">
                                <option value="Standard"></option>
                                <option value="SMA-0"></option>
                                <option value="SMA-1"></option>
                                <option value="SMA-2"></option>
                                <option value="Sub-Standard"></option>
                                <option value="Doubtful-1"></option>
                                <option value="Doubtful-2"></option>
                                <option value="Doubtful-3"></option>
                                <option value="Loss"></option>
                            </datalist>
                            <?= field_error($errors, 'asset_classification') ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="interest_rate">Interest rate (%)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'interest_rate') ?>"
                                   id="interest_rate" name="interest_rate" min="0" step="0.001"
                                   value="<?= $value('interest_rate') ?>">
                            <?= field_error($errors, 'interest_rate') ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="days_past_due">Days past due</label>
                            <input type="number" class="form-control<?= has_error($errors, 'days_past_due') ?>"
                                   id="days_past_due" name="days_past_due" min="0" step="1"
                                   value="<?= $value('days_past_due') ?>">
                            <?= field_error($errors, 'days_past_due') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="guarantor_name">Guarantor</label>
                            <input type="text" class="form-control<?= has_error($errors, 'guarantor_name') ?>"
                                   id="guarantor_name" name="guarantor_name" maxlength="150"
                                   value="<?= $value('guarantor_name') ?>">
                            <?= field_error($errors, 'guarantor_name') ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="sanction_date">Sanction date</label>
                            <input type="date" class="form-control<?= has_error($errors, 'sanction_date') ?>"
                                   id="sanction_date" name="sanction_date" value="<?= $value('sanction_date') ?>">
                            <?= field_error($errors, 'sanction_date') ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="sanction_limit">Sanction limit (&#8377;)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'sanction_limit') ?>"
                                   id="sanction_limit" name="sanction_limit" min="0" step="0.01" inputmode="decimal"
                                   value="<?= $value('sanction_limit') ?>">
                            <?= field_error($errors, 'sanction_limit') ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="drawing_power">Drawing power (&#8377;)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'drawing_power') ?>"
                                   id="drawing_power" name="drawing_power" min="0" step="0.01" inputmode="decimal"
                                   value="<?= $value('drawing_power') ?>">
                            <?= field_error($errors, 'drawing_power') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="purpose">Purpose</label>
                            <input type="text" class="form-control<?= has_error($errors, 'purpose') ?>"
                                   id="purpose" name="purpose" maxlength="150"
                                   value="<?= $value('purpose') ?>">
                            <?= field_error($errors, 'purpose') ?>
                        </div>

                        <?php
                        /*
                         * Assignment. An agent never sees this: the lead is theirs, because
                         * the panel shows an agent only the leads assigned to them and an
                         * unassigned new lead would disappear the moment they saved it.
                         */
                        ?>
                        <?php if ($ownAgentId === null && $agents !== []): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="assigned_agent_id">Assign to</label>
                                <select class="form-select" id="assigned_agent_id" name="assigned_agent_id">
                                    <option value="">Leave unassigned</option>
                                    <?php foreach ($agents as $agent): ?>
                                        <option value="<?= e((string) $agent['id']) ?>"
                                            <?= ($old['assigned_agent_id'] ?? '') === (string) $agent['id'] ? 'selected' : '' ?>>
                                            <?= e((string) $agent['name']) ?> (<?= e((string) $agent['employee_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Or leave it and distribute later from the borrower list.</div>
                            </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <label class="form-label" for="remarks">Remarks</label>
                            <textarea class="form-control<?= has_error($errors, 'remarks') ?>"
                                      id="remarks" name="remarks" rows="2" maxlength="1000"
                                      placeholder="Where this account came from, if it is worth recording"><?= $value('remarks') ?></textarea>
                            <?= field_error($errors, 'remarks') ?>
                        </div>
                    </div>
                </div>

                <div class="lrms-card-foot">
                    <button type="submit" class="btn btn-primary">
                        <?= icon('check') ?> <?= $existing === null ? 'Add borrower' : 'Add loan account' ?>
                    </button>
                    <a href="<?= e(url($existing === null ? '/customers' : '/customers')) ?>"
                       class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
