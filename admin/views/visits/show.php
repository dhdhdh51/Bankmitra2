<?php
/**
 * The Digital BC Field Visit Report, rendered from the snapshot stored on the
 * report row (not from current customer data).
 *
 * @var array<string,mixed>       $report
 * @var list<array<string,mixed>> $photos
 * @var list<array<string,mixed>> $documents
 * @var list<array<string,mixed>> $signatures
 */

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

<div class="alert alert-info no-print">
    <?= icon('info') ?>
    <div>
        This is an append-only record submitted from
        <strong><?= e($report['source']) ?><?= $report['app_version'] === null ? '' : ' v' . e($report['app_version']) ?></strong>
        on <?= e(fmt_datetime((string) $report['created_at'])) ?>. It cannot be edited.
    </div>
</div>

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
                <?php foreach (['customer' => 'Customer signature', 'agent' => 'Agent signature'] as $type => $label): ?>
                    <div class="mb-3">
                        <div class="text-muted mb-1" style="font-size:.6875rem;text-transform:uppercase;letter-spacing:.05em;font-weight:650">
                            <?= e($label) ?>
                        </div>
                        <?php if (isset($signatureByType[$type])): ?>
                            <div class="lrms-signature">
                                <img src="<?= e(Url::media((string) $signatureByType[$type]['file_path'])) ?>"
                                     alt="<?= e($label) ?>">
                                <?php if (!empty($signatureByType[$type]['signed_name'])): ?>
                                    <div class="cap"><?= e($signatureByType[$type]['signed_name']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="lrms-signature" style="background:var(--lrms-bg);padding:20px 8px">
                                <span class="text-muted" style="font-size:.8125rem">Not captured</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <h2><?= icon('image') ?> Photos</h2>
                <span class="text-muted" style="font-size:.75rem"><?= e((string) count($photos)) ?></span>
            </div>
            <div class="lrms-card-body">
                <?php if ($photos === []): ?>
                    <p class="text-muted mb-0" style="font-size:.875rem">No photos attached to this visit.</p>
                <?php else: ?>
                    <div class="lrms-gallery">
                        <?php foreach ($photos as $photo): ?>
                            <a class="lrms-thumb" target="_blank" rel="noopener"
                               href="<?= e(Url::media((string) $photo['file_path'])) ?>">
                                <img src="<?= e(Url::media((string) $photo['file_path'])) ?>" alt="" loading="lazy">
                                <span class="lrms-thumb-label">
                                    <?= e(str_replace('_', ' ', (string) $photo['photo_type'])) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
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
