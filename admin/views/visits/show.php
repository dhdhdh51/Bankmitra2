<?php
/**
 * The Digital BC Field Visit Report, rendered from the snapshot stored on the
 * report row (not from current customer data).
 *
 * @var array<string,mixed>       $report
 * @var array<string,mixed>|null  $ots   KRM / OTS settlement section, when filed
 * @var array<string,mixed>|null  $ckcc  CKCC OD-2 renewal section, when filed
 * @var list<array<string,mixed>> $photos
 * @var list<array<string,mixed>> $documents
 * @var list<array<string,mixed>> $signatures
 */

use App\Controllers\Admin\VisitController;
use App\Core\Geo;
use App\Core\Url;
use App\Models\VisitReport;

/** Renders a group of yes/no flags as tick tiles. */
$flagBlock = static function (array $map, array $row): string {
    $html = '<div class="lrms-check-grid">';
    foreach ($map as $column => $label) {
        $on = (int) ($row[$column] ?? 0) === 1;
        $html .= sprintf(
            '<div class="lrms-check-tile" style="cursor:default;%s">%s<span>%s</span></div>',
            $on ? 'background:var(--lrms-primary-light);border-color:var(--lrms-primary)' : 'opacity:.62',
            $on
                ? '<span style="color:var(--lrms-success);line-height:0">' . icon('check-circle') . '</span>'
                : '<span style="color:var(--lrms-muted);line-height:0">' . icon('x') . '</span>',
            e($label)
        );
    }
    return $html . '</div>';
};

$signatureByType = [];
foreach ($signatures as $signature) {
    $signatureByType[(string) $signature['signature_type']] = $signature;
}

// How many photographs actually carry a position. Shown in the card header because
// "6 photographs" and "6 photographs, none of which record where they were taken"
// are very different things to be looking at before approving a report.
// Only somebody who may correct a report may attach evidence to one.
$canUploadSignature = \App\Core\Auth::can('visits.revise');

$geoTagged = 0;
foreach ($photos as $photo) {
    if (Geo::has($photo)) {
        $geoTagged++;
    }
}
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/visits')) ?>" class="text-muted">Visit Reports</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted">#<?= e((string) $report['id']) ?></span>
        </nav>
        <h1>Digital BC Field Visit Report</h1>
        <p>
            <a href="<?= e(url('/customers/' . (int) $report['loan_account_id'])) ?>" class="font-mono">
                <?= e($report['loan_account_number']) ?>
            </a>
            · <?= e($report['customer_name']) ?>
            · <?= e(fmt_date((string) $report['visit_date'])) ?>
            at <?= e(fmt_time((string) $report['visit_time'])) ?>
        </p>
    </div>

    <div class="d-flex gap-2 no-print">
        <a href="<?= e(url('/visits/' . (int) $report['id'] . '/pdf')) ?>" class="btn btn-outline-secondary btn-sm">
            <?= icon('pdf') ?> Download PDF
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <?= icon('print') ?> Print
        </button>
        <a href="<?= e(url('/customers/' . (int) $report['loan_account_id'])) ?>" class="btn btn-primary btn-sm">
            <?= icon('user') ?> Customer profile
        </a>
    </div>
</div>

<?php
/**
 * Provenance and approval state.
 *
 * This block used to end with "It cannot be edited", which stopped being true when
 * reviewers were given a way to correct a misheard name. The append-only guarantee
 * still holds and is stated precisely instead: nothing is deleted, and no value
 * changes without the previous one being kept.
 */
$approvalStatus = (string) ($report['approval_status'] ?? 'pending');
$revisionCount = (int) ($report['revision_count'] ?? 0);
?>
<div class="alert alert-info no-print">
    <?= icon('info') ?>
    <div>
        Submitted from
        <strong><?= e($report['source']) ?><?= $report['app_version'] === null ? '' : ' v' . e($report['app_version']) ?></strong>
        on <?= e(fmt_datetime((string) $report['created_at'])) ?>.
        <?php if ($revisionCount === 0): ?>
            Nothing has been changed since.
        <?php else: ?>
            Corrected <strong><?= e((string) $revisionCount) ?></strong> time(s) since;
            every previous value is retained below.
        <?php endif; ?>
    </div>
</div>

<div class="lrms-card mb-3 no-print">
    <div class="lrms-card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>
            <div class="lrms-stat-label mb-1"><?= icon('shield') ?> Approval</div>
            <?php if ($approvalStatus === 'pending'): ?>
                <span class="lrms-badge badge-pending">Awaiting review</span>
            <?php elseif ($approvalStatus === 'approved'): ?>
                <span class="lrms-badge badge-visited">Approved</span>
                <span class="text-muted" style="font-size:.8125rem">
                    by <?= e((string) ($report['approver_name'] ?? '')) ?>
                    on <?= e(fmt_datetime((string) $report['approved_at'])) ?>
                </span>
            <?php else: ?>
                <span class="lrms-badge badge-legal">Rejected</span>
                <span class="text-muted" style="font-size:.8125rem">
                    by <?= e((string) ($report['approver_name'] ?? '')) ?>
                    on <?= e(fmt_datetime((string) $report['approved_at'])) ?>
                </span>
            <?php endif; ?>

            <?php if (($report['approval_remarks'] ?? '') !== ''): ?>
                <div class="text-muted mt-1" style="font-size:.8125rem">
                    &ldquo;<?= e((string) $report['approval_remarks']) ?>&rdquo;
                </div>
            <?php endif; ?>

            <?php if ($approvalStatus !== 'pending'): ?>
                <div class="text-muted mt-1" style="font-size:.75rem">
                    <?php if ((string) ($report['approval_gps_source'] ?? '') === 'device'
                        && $report['approval_gps_latitude'] !== null): ?>
                        Approved at
                        <span class="font-mono">
                            <?= e(sprintf('%.6F, %.6F', (float) $report['approval_gps_latitude'], (float) $report['approval_gps_longitude'])) ?>
                        </span>
                        <?= $report['approval_gps_accuracy_m'] === null
                            ? ''
                            : e(sprintf('(±%d m)', (int) $report['approval_gps_accuracy_m'])) ?>
                    <?php elseif ((string) ($report['approval_gps_source'] ?? '') === 'denied'): ?>
                        The approver declined to share their position.
                    <?php else: ?>
                        No position was available at approval.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="d-flex gap-2">
            <?php if (can('visits.approve')): ?>
                <a href="<?= e(url('/visits/' . (int) $report['id'] . '/approve')) ?>" class="btn btn-primary btn-sm">
                    <?= icon('check-circle') ?> <?= $approvalStatus === 'pending' ? 'Approve or reject' : 'Change decision' ?>
                </a>
            <?php endif; ?>
            <?php if (can('visits.revise')): ?>
                <a href="<?= e(url('/visits/' . (int) $report['id'] . '/revise')) ?>" class="btn btn-outline-secondary btn-sm">
                    <?= icon('pen') ?> Correct a field
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (($report['approval_photo_path'] ?? null) !== null || ($report['approval_signature_path'] ?? null) !== null): ?>
        <div class="lrms-card-body border-top">
            <div class="d-flex flex-wrap gap-4">
                <?php if (($report['approval_photo_path'] ?? null) !== null): ?>
                    <div>
                        <div class="lrms-stat-label mb-1">Approver photograph</div>
                        <img src="<?= e(Url::media((string) $report['approval_photo_path'])) ?>"
                             alt="Approver photograph"
                             style="height:110px;border:1px solid var(--lrms-border);border-radius:6px;background:#fff">
                    </div>
                <?php endif; ?>
                <?php if (($report['approval_signature_path'] ?? null) !== null): ?>
                    <div>
                        <div class="lrms-stat-label mb-1">Approver signature</div>
                        <img src="<?= e(Url::media((string) $report['approval_signature_path'])) ?>"
                             alt="Approver signature"
                             style="height:110px;border:1px solid var(--lrms-border);border-radius:6px;background:#fff">
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($revisions)): ?>
    <div class="lrms-card mb-3">
        <div class="lrms-card-head"><h2>Corrections</h2></div>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr><th>#</th><th>When</th><th>By</th><th>Changed</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($revisions as $revision): ?>
                        <tr>
                            <td class="num"><?= (int) $revision['revision_no'] ?></td>
                            <td style="font-size:.8125rem"><?= fmt_datetime($revision['changed_at']) ?></td>
                            <td style="font-size:.8125rem"><?= nullable($revision['changed_by_name']) ?></td>
                            <td style="font-size:.75rem">
                                <?php foreach ($revision['changes_decoded'] as $field => $change): ?>
                                    <div>
                                        <strong><?= e(VisitReport::CORRECTABLE[$field] ?? $field) ?>:</strong>
                                        <span class="text-muted" style="text-decoration:line-through">
                                            <?= e((string) ($change['from'] ?? '')) ?>
                                        </span>
                                        &rarr; <?= e((string) ($change['to'] ?? '')) ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td style="font-size:.75rem"><?= nullable($revision['reason']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-8">

        <!-- General -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head"><h2>General</h2></div>
            <div class="lrms-card-body">
                <dl class="lrms-dl">
                    <div><dt>Visit date</dt><dd><?= e(fmt_date((string) $report['visit_date'])) ?></dd></div>
                    <div><dt>Visit time</dt><dd><?= e(fmt_time((string) $report['visit_time'])) ?></dd></div>
                    <div><dt>BC code</dt><dd><?= nullable($report['bc_code']) ?></dd></div>
                    <div><dt>Branch</dt><dd><?= e($report['branch_name'] ?: $report['branch_display_name']) ?></dd></div>
                    <div><dt>Agent name</dt><dd><?= e($report['agent_name']) ?></dd></div>
                    <div><dt>Village</dt><dd><?= nullable($report['village']) ?></dd></div>
                </dl>
            </div>
        </div>

        <!-- Borrower -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <h2>Borrower details</h2>
                <p>As recorded at the time of the visit</p>
            </div>
            <div class="lrms-card-body">
                <dl class="lrms-dl">
                    <div><dt>Customer name</dt><dd><?= e($report['customer_name']) ?></dd></div>
                    <div><dt>Father / husband name</dt><dd><?= nullable($report['father_husband_name']) ?></dd></div>
                    <div>
                        <dt>Mobile</dt>
                        <dd class="font-mono">
                            <?= isset($report['mobile']) && $report['mobile'] !== null
                                ? e($report['mobile'])
                                : nullable($report['mobile_masked']) ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Aadhaar</dt>
                        <dd class="font-mono">
                            <?= isset($report['aadhaar']) && $report['aadhaar'] !== null
                                ? e(trim(chunk_split((string) $report['aadhaar'], 4, ' ')))
                                : nullable($report['aadhaar_masked']) ?>
                        </dd>
                    </div>
                    <div style="grid-column:1/-1"><dt>Address</dt><dd><?= nullable($report['address']) ?></dd></div>
                </dl>
            </div>
        </div>

        <!-- Loan -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <h2>Loan details</h2>
                <p>Snapshot taken when the report was filed</p>
            </div>
            <div class="lrms-card-body">
                <dl class="lrms-dl">
                    <div><dt>Loan account number</dt><dd class="font-mono"><?= e($report['loan_account_number']) ?></dd></div>
                    <div><dt>Loan type</dt><dd><?= nullable($report['loan_type']) ?></dd></div>
                    <div><dt>Outstanding amount</dt><dd class="lg"><?= e(rupees($report['outstanding_amount'])) ?></dd></div>
                    <div>
                        <dt>Overdue amount</dt>
                        <dd class="lg" style="color:var(--lrms-danger)"><?= e(rupees($report['overdue_amount'])) ?></dd>
                    </div>
                    <div>
                        <dt>NPA date</dt>
                        <dd><?= $report['npa_date'] === null ? '<span class="text-muted">Not classified</span>' : e(fmt_date((string) $report['npa_date'])) ?></dd>
                    </div>
                    <div><dt>Current status</dt><dd><?= status_badge($report['current_status']) ?></dd></div>
                </dl>
            </div>
        </div>

        <!-- Customer contact -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head"><h2>Customer contact</h2></div>
            <div class="lrms-card-body">
                <?= $flagBlock(VisitReport::CONTACT_FLAGS, $report) ?>

                <?php if ((int) $report['family_member_met'] === 1): ?>
                    <dl class="lrms-dl mt-3">
                        <div><dt>Family member name</dt><dd><?= nullable($report['family_member_name']) ?></dd></div>
                        <div><dt>Relationship</dt><dd><?= nullable($report['family_member_relationship']) ?></dd></div>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <!-- Physical verification -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head"><h2>Physical verification</h2></div>
            <div class="lrms-card-body">
                <dl class="lrms-dl">
                    <div><dt>Borrower alive</dt><dd><?= yes_no($report['borrower_alive']) ?></dd></div>
                    <div><dt>Same address</dt><dd><?= yes_no($report['same_address']) ?></dd></div>
                    <div><dt>Shifted</dt><dd><?= yes_no($report['shifted']) ?></dd></div>
                    <div>
                        <dt>Occupation</dt>
                        <dd>
                            <?= e(occupation_label($report['occupation'])) ?>
                            <?php if (!empty($report['occupation_other_text'])): ?>
                                <span class="text-muted">(<?= e($report['occupation_other_text']) ?>)</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- ================= KRM / OTS settlement =================
             Only rendered when the agent filed this section. -->
        <?php if ($ots !== null): ?>
            <div class="lrms-card lrms-card-accent mb-3">
                <div class="lrms-card-head">
                    <h2>KRM / OTS settlement</h2>
                    <?php if (!empty($ots['scheme'])): ?>
                        <span class="lrms-badge badge-promise">
                            <?= e(VisitReport::OTS_SCHEMES[$ots['scheme']] ?? $ots['scheme']) ?>
                        </span>
                    <?php endif; ?>
                    <?php
                    $statusTone = [
                        'approved' => 'badge-visited',
                        'rejected' => 'badge-legal',
                        'pending'  => 'badge-pending',
                    ][$ots['approval_status']] ?? 'badge-pending';
                    ?>
                    <span class="lrms-badge <?= e($statusTone) ?>">
                        <?= e(VisitReport::OTS_APPROVAL_STATUSES[$ots['approval_status']] ?? $ots['approval_status']) ?>
                    </span>
                </div>
                <div class="lrms-card-body">
                    <dl class="lrms-dl">
                        <div><dt>Borrower&rsquo;s name</dt><dd><?= nullable($ots['borrower_name'] ?? $report['customer_name']) ?></dd></div>
                        <div>
                            <dt>NPA date</dt>
                            <dd><?= empty($ots['npa_date']) ? '<span class="text-muted">Not classified</span>' : fmt_date($ots['npa_date']) ?></dd>
                        </div>
                        <div><dt>Eligible for KRM / OTS</dt><dd><?= yes_no($ots['eligible_for_ots']) ?></dd></div>
                        <div><dt>Outstanding at visit</dt><dd><?= e(rupees($ots['outstanding_amount'])) ?></dd></div>
                        <div>
                            <dt>Relief / waiver</dt>
                            <dd><?= $ots['relief_waiver_percent'] === null ? '&mdash;' : e(rtrim(rtrim(number_format((float) $ots['relief_waiver_percent'], 2), '0'), '.')) . '%' ?></dd>
                        </div>
                        <div><dt>Residual loan balance</dt><dd><?= e(rupees($ots['rlb_amount'])) ?></dd></div>
                    </dl>

                    <!-- The settlement figures are what the branch acts on, so they
                         are given the prominence a table row would not. -->
                    <div class="lrms-figures mt-3">
                        <div class="lrms-figure">
                            <span class="lrms-figure-label">Borrower&rsquo;s payable
                                <?php if ($ots['payable_percent'] !== null): ?>
                                    (<?= e(rtrim(rtrim(number_format((float) $ots['payable_percent'], 2), '0'), '.')) ?>%)
                                <?php endif; ?>
                            </span>
                            <span class="lrms-figure-value"><?= e(rupees($ots['borrower_payable_amount'])) ?></span>
                        </div>
                        <div class="lrms-figure">
                            <span class="lrms-figure-label">Total settlement</span>
                            <span class="lrms-figure-value"><?= e(rupees($ots['total_settlement_amount'])) ?></span>
                        </div>
                        <div class="lrms-figure">
                            <span class="lrms-figure-label">Balance payable</span>
                            <span class="lrms-figure-value"><?= e(rupees($ots['balance_payable'])) ?></span>
                        </div>
                    </div>

                    <h3 class="lrms-subhead mt-4">Initial deposit</h3>
                    <!-- Stated on screen, not just in the schema: the agent records
                         a payment the borrower made to the bank. -->
                    <p class="lrms-note">
                        Paid by the borrower at the bank and evidenced by the bank&rsquo;s own
                        receipt. Agents never collect money.
                    </p>
                    <dl class="lrms-dl">
                        <div>
                            <dt>Required deposit
                                <?php if ($ots['initial_deposit_percent'] !== null): ?>
                                    (<?= e(rtrim(rtrim(number_format((float) $ots['initial_deposit_percent'], 2), '0'), '.')) ?>%)
                                <?php endif; ?>
                            </dt>
                            <dd><?= e(rupees($ots['required_deposit_amount'])) ?></dd>
                        </div>
                        <div><dt>Deposit received</dt><dd><?= yes_no($ots['deposit_received']) ?></dd></div>
                        <div><dt>Deposit amount</dt><dd><?= e(rupees($ots['deposit_amount'])) ?></dd></div>
                        <div><dt>Deposit date</dt><dd><?= $ots['deposit_date'] === null ? '&mdash;' : fmt_date($ots['deposit_date']) ?></dd></div>
                        <div><dt>Receipt / transaction ID</dt><dd><?= nullable($ots['deposit_reference']) ?></dd></div>
                        <div><dt>Proposed final payment</dt><dd><?= $ots['proposed_final_payment_date'] === null ? '&mdash;' : fmt_date($ots['proposed_final_payment_date']) ?></dd></div>
                    </dl>

                    <h3 class="lrms-subhead mt-4">Validity</h3>
                    <dl class="lrms-dl">
                        <div><dt>Valid from</dt><dd><?= $ots['validity_from'] === null ? '&mdash;' : fmt_date($ots['validity_from']) ?></dd></div>
                        <div><dt>Valid to</dt><dd><?= $ots['validity_to'] === null ? '&mdash;' : fmt_date($ots['validity_to']) ?></dd></div>
                        <div><dt>Expected closure</dt><dd><?= $ots['expected_closure_date'] === null ? '&mdash;' : fmt_date($ots['expected_closure_date']) ?></dd></div>
                        <div><dt>Borrower accepted terms</dt><dd><?= yes_no($ots['borrower_accepted']) ?></dd></div>
                    </dl>

                    <?php if (!empty($ots['rejection_reason'])): ?>
                        <div class="lrms-callout lrms-callout-danger mt-3">
                            <strong>Not accepted:</strong> <?= e($ots['rejection_reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= CKCC OD-2 renewal ================= -->
        <?php if ($ckcc !== null): ?>
            <?php
            $days = $ckcc['days_remaining'] === null ? null : (int) $ckcc['days_remaining'];
            // The deadline is the whole point of this report, so its consequence is
            // spelled out rather than left as arithmetic for the reader.
            $tone = 'lrms-callout-info';
            if ($days !== null) {
                $tone = $days < 0 ? 'lrms-callout-danger' : ($days <= 7 ? 'lrms-callout-warning' : 'lrms-callout-info');
            }
            ?>
            <div class="lrms-card lrms-card-accent mb-3">
                <div class="lrms-card-head">
                    <h2>CKCC OD-2 renewal</h2>
                    <?php if (!empty($ckcc['renewal_due_bucket'])): ?>
                        <span class="lrms-badge <?= $ckcc['renewal_due_bucket'] === 'overdue' ? 'badge-legal' : 'badge-pending' ?>">
                            <?= e(VisitReport::CKCC_DUE_BUCKETS[$ckcc['renewal_due_bucket']] ?? $ckcc['renewal_due_bucket']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="lrms-card-body">
                    <?php if ($days !== null): ?>
                        <div class="lrms-callout <?= e($tone) ?>">
                            <strong>
                                <?php if ($days < 0): ?>
                                    Renewal overdue by <?= e((string) abs($days)) ?> day<?= abs($days) === 1 ? '' : 's' ?>
                                <?php elseif ($days === 0): ?>
                                    Renewal is due today
                                <?php else: ?>
                                    <?= e((string) $days) ?> day<?= $days === 1 ? '' : 's' ?> left to renew
                                <?php endif; ?>
                            </strong>
                            <?php if (!empty($ckcc['expected_npa_date'])): ?>
                                <div class="mt-1">
                                    If the renewal is not completed, this account is expected to turn
                                    NPA on <strong><?= fmt_date($ckcc['expected_npa_date']) ?></strong>.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <dl class="lrms-dl mt-3">
                        <div><dt>CIF number</dt><dd><?= nullable($ckcc['cif_number']) ?></dd></div>
                        <div><dt>Sanction date</dt><dd><?= $ckcc['sanction_date'] === null ? '&mdash;' : fmt_date($ckcc['sanction_date']) ?></dd></div>
                        <div><dt>Sanction limit</dt><dd><?= e(rupees($ckcc['sanction_limit'])) ?></dd></div>
                        <div><dt>Drawing power</dt><dd><?= e(rupees($ckcc['drawing_power'])) ?></dd></div>
                        <div><dt>Outstanding</dt><dd><?= e(rupees($ckcc['outstanding_amount'])) ?></dd></div>
                        <div><dt>Interest overdue</dt><dd><?= e(rupees($ckcc['interest_overdue'])) ?></dd></div>
                        <div><dt>Renewal due</dt><dd><?= $ckcc['renewal_due_date'] === null ? '&mdash;' : fmt_date($ckcc['renewal_due_date']) ?></dd></div>
                        <div><dt>KYC status</dt><dd><?= $ckcc['kyc_status'] === null ? '&mdash;' : e(ucfirst((string) $ckcc['kyc_status'])) ?></dd></div>
                    </dl>

                    <h3 class="lrms-subhead mt-4">Renewal eligibility</h3>
                    <?= $flagBlock(VisitReport::CKCC_ELIGIBILITY_FLAGS, $ckcc) ?>

                    <h3 class="lrms-subhead mt-4">Documents the borrower had</h3>
                    <p class="lrms-note">What exists, whether or not it was photographed.</p>
                    <?= $flagBlock(VisitReport::CKCC_DOCUMENT_FLAGS, $ckcc) ?>
                    <?php if (!empty($ckcc['doc_other_text'])): ?>
                        <p class="text-muted mt-2">Other: <?= e($ckcc['doc_other_text']) ?></p>
                    <?php endif; ?>

                    <h3 class="lrms-subhead mt-4">Renewal consent</h3>
                    <?= $flagBlock(VisitReport::CKCC_CONSENT_FLAGS, $ckcc) ?>

                    <?php if (!empty($ckcc['agent_observation'])): ?>
                        <h3 class="lrms-subhead mt-4">BC agent observation</h3>
                        <p class="lrms-prose"><?= nl2br(e($ckcc['agent_observation'])) ?></p>
                    <?php endif; ?>

                    <h3 class="lrms-subhead mt-4">BC agent recommendation</h3>
                    <?= $flagBlock(VisitReport::CKCC_RECOMMENDATION_FLAGS, $ckcc) ?>
                    <?php if (!empty($ckcc['rec_other_text'])): ?>
                        <p class="text-muted mt-2">Other: <?= e($ckcc['rec_other_text']) ?></p>
                    <?php endif; ?>

                    <h3 class="lrms-subhead mt-4">Report status</h3>
                    <?= $flagBlock(VisitReport::CKCC_STATUS_FLAGS, $ckcc) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recovery possibility -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head"><h2>Recovery possibility</h2></div>
            <div class="lrms-card-body">
                <?= $flagBlock(VisitReport::RECOVERY_FLAGS, $report) ?>

                <?php if ((float) ($report['promise_amount'] ?? 0) > 0 || $report['promise_date'] !== null): ?>
                    <div class="lrms-card mt-3" style="background:var(--lrms-primary-light);border-color:var(--lrms-primary)">
                        <div class="lrms-card-body">
                            <dl class="lrms-dl mb-0">
                                <div>
                                    <dt>Promise amount</dt>
                                    <dd class="lg"><?= e(rupees($report['promise_amount'])) ?></dd>
                                </div>
                                <div>
                                    <dt>Promise date</dt>
                                    <dd class="lg">
                                        <?= $report['promise_date'] === null ? '—' : e(fmt_date((string) $report['promise_date'])) ?>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Non-payment reason -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head"><h2>Non-payment reason</h2></div>
            <div class="lrms-card-body">
                <?= $flagBlock(VisitReport::REASON_FLAGS, $report) ?>
                <?php if (!empty($report['reason_other_text'])): ?>
                    <p class="mt-3 mb-0" style="font-size:.875rem">
                        <span class="text-muted" style="font-size:.75rem;text-transform:uppercase">Other reason:</span><br>
                        <?= e($report['reason_other_text']) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Agent recommendation -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head"><h2>Agent recommendation</h2></div>
            <div class="lrms-card-body">
                <?= $flagBlock(VisitReport::RECOMMENDATION_FLAGS, $report) ?>
                <?php if (!empty($report['rec_other_text'])): ?>
                    <p class="mt-3 mb-0" style="font-size:.875rem">
                        <span class="text-muted" style="font-size:.75rem;text-transform:uppercase">Other recommendation:</span><br>
                        <?= e($report['rec_other_text']) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Remarks -->
        <div class="lrms-card">
            <div class="lrms-card-head"><h2>Remarks</h2></div>
            <div class="lrms-card-body">
                <?php if (!empty($report['remarks'])): ?>
                    <p class="mb-0" style="font-size:.9375rem;white-space:pre-wrap"><?= e($report['remarks']) ?></p>
                <?php else: ?>
                    <p class="text-muted mb-0" style="font-size:.875rem">No remarks recorded.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right column: attachments -->
    <div class="col-xl-4">
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <h2><?= icon('pen') ?> Signatures</h2>
            </div>
            <div class="lrms-card-body">
                <?php foreach (['customer' => 'Borrower signature', 'agent' => 'Agent signature'] as $type => $label): ?>
                    <div class="mb-3">
                        <div class="text-muted mb-1" style="font-size:.6875rem;text-transform:uppercase;letter-spacing:.05em;font-weight:650">
                            <?= e($label) ?>
                        </div>
                        <?php if (isset($signatureByType[$type])): ?>
                            <?php $signature = $signatureByType[$type]; ?>
                            <div class="lrms-signature">
                                <img src="<?= e(Url::media((string) $signature['file_path'])) ?>"
                                     alt="<?= e($label) ?>">
                                <?php if (!empty($signature['signed_name'])): ?>
                                    <div class="cap"><?= e($signature['signed_name']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="lrms-photo-geo mt-1" style="padding-left:2px">
                                <?php if (Geo::has($signature)): ?>
                                    <span style="line-height:0"><?= icon('map-pin') ?></span>
                                    <a href="<?= e(Geo::mapUrl($signature['gps_latitude'], $signature['gps_longitude'])) ?>"
                                       target="_blank" rel="noopener noreferrer">
                                        <?= e(Geo::coordinates($signature['gps_latitude'], $signature['gps_longitude'])) ?>
                                    </a>
                                    <?php if (($signature['gps_accuracy_m'] ?? null) !== null): ?>
                                        <span class="<?= Geo::isPrecise($signature['gps_accuracy_m']) ? 'text-muted' : 'lrms-geo-coarse' ?>">
                                            <?= e(Geo::accuracy($signature['gps_accuracy_m'])) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted"><?= e(Geo::signature($signature)) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="lrms-signature" style="background:var(--lrms-bg);padding:20px 8px">
                                <span class="text-muted" style="font-size:.8125rem">Not captured</span>
                            </div>
                        <?php endif; ?>

                        <?php
                        // Uploading an image is offered only where nothing was drawn on
                        // the device. A pad signature was made in front of the person
                        // signing it; replacing that with a photograph from a desk is a
                        // downgrade, and the server refuses it too.
                        $isPadSignature = isset($signatureByType[$type])
                            && (string) ($signatureByType[$type]['capture_method'] ?? 'device_pad') === 'device_pad';
                        ?>
                        <?php if ($canUploadSignature && !$isPadSignature): ?>
                            <form method="post" action="<?= e(url('/visits/' . $report['id'] . '/signature')) ?>"
                                  enctype="multipart/form-data" class="mt-2" data-no-double-submit>
                                <?= csrf_field() ?>
                                <input type="hidden" name="signature_type" value="<?= e($type) ?>">
                                <details>
                                    <summary style="font-size:.75rem;cursor:pointer;color:var(--lrms-primary)">
                                        <?= isset($signatureByType[$type]) ? 'Replace the uploaded image' : 'Upload an image of the signature' ?>
                                    </summary>
                                    <div class="mt-2">
                                        <input type="file" class="form-control form-control-sm mb-2"
                                               name="signature_file" required
                                               accept="image/jpeg,image/png,image/webp">
                                        <input type="text" class="form-control form-control-sm mb-2"
                                               name="signed_name" maxlength="150"
                                               placeholder="Name as signed (optional)"
                                               value="<?= e($type === 'agent' ? (string) $report['agent_name'] : (string) $report['customer_name']) ?>">
                                        <input type="text" class="form-control form-control-sm mb-2"
                                               name="uploaded_note" maxlength="255"
                                               placeholder="Why is this being uploaded? (optional)">
                                        <p class="text-muted mb-2" style="font-size:.6875rem">
                                            It will print as an uploaded image with no position, because the
                                            only position available now is this office.
                                        </p>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Attach</button>
                                    </div>
                                </details>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <h2><?= icon('image') ?> Photographs</h2>
                <span class="text-muted" style="font-size:.75rem">
                    <?= e((string) count($photos)) ?><?php if ($geoTagged > 0): ?> &middot; <?= e((string) $geoTagged) ?> geo-tagged<?php endif; ?>
                </span>
            </div>
            <div class="lrms-card-body">
                <?php if ($photos === []): ?>
                    <p class="text-muted mb-0" style="font-size:.875rem">No photographs attached to this visit.</p>
                <?php else: ?>
                    <?php foreach ($photos as $photo): ?>
                        <?php
                        $hasFix = Geo::has($photo);
                        $source = (string) ($photo['capture_source'] ?? 'unknown');
                        ?>
                        <figure class="lrms-photo">
                            <a class="lrms-photo-frame" target="_blank" rel="noopener"
                               href="<?= e(Url::media((string) $photo['file_path'])) ?>"
                               title="Open the full-size photograph">
                                <img src="<?= e(Url::media((string) $photo['file_path'])) ?>"
                                     alt="<?= e(VisitController::photoLabel((string) $photo['photo_type'])) ?>"
                                     loading="lazy">
                            </a>
                            <figcaption>
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                    <strong style="font-size:.8125rem">
                                        <?= e(VisitController::photoLabel((string) $photo['photo_type'])) ?>
                                    </strong>
                                    <?= geo_source_badge($source) ?>
                                </div>

                                <?php if ($hasFix): ?>
                                    <div class="lrms-photo-geo">
                                        <span style="line-height:0"><?= icon('map-pin') ?></span>
                                        <a href="<?= e(Geo::mapUrl($photo['gps_latitude'], $photo['gps_longitude'])) ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           title="Open these coordinates in a map">
                                            <?= e(Geo::coordinates($photo['gps_latitude'], $photo['gps_longitude'])) ?>
                                        </a>
                                        <?php if (($photo['gps_accuracy_m'] ?? null) !== null): ?>
                                            <span class="<?= Geo::isPrecise($photo['gps_accuracy_m']) ? 'text-muted' : 'lrms-geo-coarse' ?>"
                                                  <?php if (!Geo::isPrecise($photo['gps_accuracy_m'])): ?>
                                                      title="Too coarse to place a particular house - this is roughly a cell-tower fix"
                                                  <?php endif; ?>>
                                                <?= e(Geo::accuracy($photo['gps_accuracy_m'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted" style="font-size:.75rem">
                                        <?= e(Geo::photo($photo)) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (($photo['captured_at'] ?? null) !== null): ?>
                                    <div class="text-muted" style="font-size:.6875rem;margin-top:2px">
                                        Taken <?= e(fmt_datetime((string) $photo['captured_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($documents !== []): ?>
            <div class="lrms-card">
                <div class="lrms-card-head"><h2><?= icon('file') ?> Documents</h2></div>
                <div class="lrms-card-body">
                    <?php foreach ($documents as $document): ?>
                        <a href="<?= e(Url::media((string) $document['file_path'])) ?>" target="_blank" rel="noopener"
                           class="d-flex align-items-center gap-2 py-2"
                           style="border-bottom:1px solid var(--lrms-border);font-size:.8438rem">
                            <?= icon('file') ?>
                            <span class="flex-grow-1"><?= e($document['title'] ?? $document['original_name']) ?></span>
                            <?= icon('external') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
