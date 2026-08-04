<?php
/**
 * Customer profile: loan info, promise history, visit history, media and the
 * append-only timeline.
 *
 * @var array<string,mixed>       $lead
 * @var bool                      $showPii
 * @var list<array<string,mixed>> $timeline
 * @var list<array<string,mixed>> $visits
 * @var list<array<string,mixed>> $promises
 * @var list<array<string,mixed>> $photos
 * @var list<array<string,mixed>> $documents
 * @var list<array<string,mixed>> $otherLoans
 * @var list<array<string,mixed>> $agents
 * @var list<array<string,mixed>> $branches
 */

use App\Controllers\Admin\VisitController;
use App\Core\Geo;
use App\Core\Url;

$mobile = $showPii ? ($lead['mobile'] ?? null) : null;
$aadhaar = $showPii ? ($lead['aadhaar'] ?? null) : null;
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/customers')) ?>" class="text-muted">Customers &amp; Leads</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted"><?= e($lead['loan_account_number']) ?></span>
        </nav>
        <h1 class="d-flex align-items-center gap-2 flex-wrap">
            <?= e($lead['customer_name']) ?>
            <?= status_badge($lead['current_status']) ?>
            <?php if ((int) $lead['is_npa'] === 1): ?>
                <span class="lrms-npa">NPA</span>
            <?php endif; ?>
        </h1>
        <p>
            <span class="font-mono"><?= e($lead['loan_account_number']) ?></span>
            <a href="#" data-copy="<?= e($lead['loan_account_number']) ?>" class="text-muted ms-1"
               title="Copy account number" data-bs-toggle="tooltip">⧉</a>
            · <?= e($lead['branch_name']) ?>
            <?php if (!empty($lead['address'])): ?> · <?= e($lead['address']) ?><?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-2 flex-wrap no-print">
        <?php if (can('customers.update')): ?>
            <a href="<?= e(url('/customers/' . (int) $lead['id'] . '/edit')) ?>" class="btn btn-outline-secondary btn-sm">
                <?= icon('edit') ?> Edit borrower
            </a>
        <?php endif; ?>

        <?php
        /*
         * A borrower can owe on more than one account - a KCC and an OD-2 are two accounts
         * and one person - so this adds an account TO them rather than starting a second
         * copy of the person, which is what "Add borrower" from the list would do.
         */
        ?>
        <?php if (can('customers.create')): ?>
            <a href="<?= e(url('/customers/create') . '?customer_id=' . (int) $lead['customer_id']) ?>"
               class="btn btn-outline-secondary btn-sm">
                <?= icon('plus') ?> Add another account
            </a>
        <?php endif; ?>

        <?php if ($agents !== [] && (string) $lead['current_status'] !== 'closed'): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#assignModal">
                <?= icon('user') ?> <?= $lead['assigned_agent_id'] === null ? 'Assign agent' : 'Reassign' ?>
            </button>
        <?php endif; ?>

        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="dropdown" aria-expanded="false">
                More
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <button class="dropdown-item d-flex align-items-center gap-2" onclick="window.print()">
                        <?= icon('print') ?> Print profile
                    </button>
                </li>
                <?php if (can('leads.close')): ?>
                    <li><hr class="dropdown-divider"></li>
                    <?php if ((string) $lead['current_status'] !== 'closed'): ?>
                        <li>
                            <form method="post" action="<?= e(url('/customers/bulk')) ?>" class="m-0"
                                  data-confirm="Close this lead? Visit history is preserved.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="lead_ids[]" value="<?= e((string) $lead['id']) ?>">
                                <input type="hidden" name="bulk_action" value="close">
                                <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                                    <?= icon('lock') ?> Close lead
                                </button>
                            </form>
                        </li>
                    <?php else: ?>
                        <li>
                            <form method="post" action="<?= e(url('/customers/bulk')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="lead_ids[]" value="<?= e((string) $lead['id']) ?>">
                                <input type="hidden" name="bulk_action" value="reopen">
                                <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                                    <?= icon('unlock') ?> Reopen lead
                                </button>
                            </form>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<!-- ==================== Loan summary ==================== -->
<div class="lrms-stats">
    <div class="lrms-stat lrms-stat-accent">
        <div class="lrms-stat-label"><?= icon('money') ?> Outstanding</div>
        <div class="lrms-stat-value sm"><?= e(rupees($lead['outstanding_amount'])) ?></div>
    </div>
    <div class="lrms-stat lrms-stat-accent is-danger">
        <div class="lrms-stat-label"><?= icon('alert') ?> Overdue</div>
        <div class="lrms-stat-value sm" style="color:var(--lrms-danger)">
            <?= e(rupees($lead['overdue_amount'])) ?>
        </div>
    </div>
    <div class="lrms-stat lrms-stat-accent is-success">
        <div class="lrms-stat-label"><?= icon('clipboard') ?> Visits</div>
        <div class="lrms-stat-value sm"><?= e((string) (int) $lead['visit_count']) ?></div>
        <div class="lrms-stat-sub">
            <?= $lead['last_visit_at'] !== null ? 'Last ' . e(time_ago((string) $lead['last_visit_at'])) : 'Never visited' ?>
        </div>
    </div>
    <div class="lrms-stat lrms-stat-accent is-warning">
        <div class="lrms-stat-label"><?= icon('handshake') ?> Promises</div>
        <div class="lrms-stat-value sm"><?= e((string) count($promises)) ?></div>
        <div class="lrms-stat-sub">
            <?php
            $kept = 0;
            $broken = 0;
            foreach ($promises as $p) {
                if ((string) $p['status'] === 'kept') { $kept++; }
                if ((string) $p['status'] === 'broken') { $broken++; }
            }
            ?>
            <?= e((string) $kept) ?> kept · <?= e((string) $broken) ?> broken
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- ==================== Left column ==================== -->
    <div class="col-xl-5">

        <!-- Borrower -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <h2><?= icon('user') ?> Borrower details</h2>
                <?php
                /*
                 * Edit sits on the card, not only in the page header.
                 *
                 * There was one "Edit borrower" button at the top of a page that scrolls
                 * for several screens, so by the time somebody is looking at the field
                 * they want to correct, the way to correct it is off-screen and reads as
                 * absent. The fragment lands them on the matching card of the edit form
                 * rather than at the top of it.
                 */
                ?>
                <?php if (can('customers.update')): ?>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= e(url('/customers/' . (int) $lead['id'] . '/edit') . '#borrower') ?>">
                        <?= icon('edit') ?> Edit these details
                    </a>
                <?php endif; ?>
            </div>
            <div class="lrms-card-body">
                <dl class="lrms-dl">
                    <div>
                        <dt>Customer name</dt>
                        <dd><?= e($lead['customer_name']) ?></dd>
                    </div>
                    <div>
                        <dt>Father / husband name</dt>
                        <dd><?= nullable($lead['father_husband_name']) ?></dd>
                    </div>
                    <div>
                        <dt>Mobile</dt>
                        <dd class="font-mono">
                            <?php if ($showPii && $mobile !== null): ?>
                                <a href="tel:<?= e($mobile) ?>"><?= e($mobile) ?></a>
                            <?php else: ?>
                                <?= nullable($lead['mobile_masked']) ?>
                                <?php if (!$showPii && !empty($lead['mobile_masked'])): ?>
                                    <span class="text-muted" style="font-size:.6875rem"
                                          title="You do not have permission to view full PII"
                                          data-bs-toggle="tooltip"><?= icon('lock') ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php
                    /*
                     * The second number sits next to the first, dialable and labelled.
                     *
                     * Shown even when empty, with a prompt: a blank row is how the next
                     * agent finds out this is a thing they can fill in. Hiding it until it
                     * has a value means the field exists for whoever already knew about it.
                     */
                    ?>
                    <div>
                        <dt>Second mobile</dt>
                        <dd class="font-mono">
                            <?php if ($showPii && !empty($lead['alt_mobile'])): ?>
                                <a href="tel:<?= e((string) $lead['alt_mobile']) ?>"><?= e((string) $lead['alt_mobile']) ?></a>
                            <?php elseif (!empty($lead['alt_mobile_masked'])): ?>
                                <?= e((string) $lead['alt_mobile_masked']) ?>
                            <?php else: ?>
                                <span class="text-muted" style="font-family:var(--bs-body-font-family)">
                                    Not recorded
                                    <?php if (can('customers.update')): ?>
                                        &mdash; <a href="<?= e(url('/customers/' . (int) $lead['id'] . '/edit') . '#borrower') ?>">add one</a>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($lead['alt_mobile_label'])): ?>
                                <span class="lrms-badge badge-pending" style="font-family:var(--bs-body-font-family)">
                                    <?= e((string) $lead['alt_mobile_label']) ?>
                                </span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Aadhaar</dt>
                        <dd class="font-mono">
                            <?= $showPii && $aadhaar !== null
                                ? e(trim(chunk_split($aadhaar, 4, ' ')))
                                : nullable($lead['aadhaar_masked']) ?>
                        </dd>
                    </div>
                    <div style="grid-column:1/-1">
                        <dt>Address</dt>
                        <dd><?= nullable($lead['address']) ?></dd>
                    </div>
                </dl>
            </div>
        </div>

        <?php
        /*
         * Operator-defined fields. Rendered from the definitions rather than hardcoded,
         * so a field added in Settings shows up here with no release. A field with no
         * answer is still listed - "not recorded" is information, and hiding the row
         * would make it look as though the field does not exist.
         */
        $extraBlocks = array_filter([
            'Additional borrower details' => $customerFields ?? [],
            'Additional loan details'     => $loanFields ?? [],
        ]);
        ?>
        <?php foreach ($extraBlocks as $blockTitle => $blockFields): ?>
            <div class="lrms-card mb-3">
                <div class="lrms-card-head"><h2><?= icon('clipboard') ?> <?= e($blockTitle) ?></h2></div>
                <div class="lrms-card-body">
                    <dl class="lrms-dl">
                        <?php foreach ($blockFields as $definition): ?>
                            <?php $display = \App\Models\CustomField::display($definition); ?>
                            <div<?= (string) $definition['field_type'] === 'textarea' ? ' style="grid-column:1/-1"' : '' ?>>
                                <dt><?= e((string) $definition['label']) ?></dt>
                                <dd>
                                    <?php if ($display === ''): ?>
                                        <span class="text-muted">Not recorded</span>
                                    <?php else: ?>
                                        <?= e($display) ?>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Loan -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <h2><?= icon('money') ?> Loan details</h2>
                <?php if (can('customers.update')): ?>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= e(url('/customers/' . (int) $lead['id'] . '/edit') . '#loan') ?>">
                        <?= icon('edit') ?> Edit these figures
                    </a>
                <?php endif; ?>
            </div>
            <div class="lrms-card-body">
                <dl class="lrms-dl">
                    <div>
                        <dt>Loan account number</dt>
                        <dd class="font-mono"><?= e($lead['loan_account_number']) ?></dd>
                    </div>
                    <div>
                        <dt>Loan type</dt>
                        <dd><?= nullable($lead['loan_type']) ?></dd>
                    </div>
                    <div>
                        <dt>Outstanding</dt>
                        <dd class="lg"><?= e(rupees($lead['outstanding_amount'])) ?></dd>
                    </div>
                    <div>
                        <dt>Overdue</dt>
                        <dd class="lg" style="color:var(--lrms-danger)"><?= e(rupees($lead['overdue_amount'])) ?></dd>
                    </div>
                    <div>
                        <?php
                        /*
                         * What it costs to close the account outright. Deliberately
                         * distinct from the OTS figure below: a settlement is what the
                         * branch is willing to accept, a closure amount is the whole
                         * number, and an agent quoting one for the other is quoting a
                         * discount nobody approved.
                         */
                        ?>
                        <dt>Closure amount</dt>
                        <dd class="lg">
                            <?php if ($lead['closure_amount'] !== null): ?>
                                <?= e(rupees($lead['closure_amount'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php
                    /*
                     * The rest of the bank's statement. Rendered only when the file
                     * actually carried the column: a dash against "Security value" is
                     * information, but eleven dashes in a row is just noise that pushes
                     * the figures people came for off the screen.
                     */
                    $classification = $lead['asset_classification'] ?? null;
                    ?>
                    <?php if ($classification !== null && $classification !== ''): ?>
                        <div>
                            <dt>Asset classification</dt>
                            <dd><span class="lrms-badge badge-pending"><?= e((string) $classification) ?></span></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($lead['facility_type'])): ?>
                        <div>
                            <dt>Facility</dt>
                            <dd>
                                <?= e(\App\Models\LoanAccount::FACILITIES[(string) $lead['facility_type']]
                                    ?? (string) $lead['facility_type']) ?>
                                <?php if (in_array((string) $lead['facility_type'], ['kcc', 'od2'], true)): ?>
                                    <span class="text-muted" style="font-size:.75rem">
                                        appears in the
                                        <?= e((string) $lead['facility_type']) === 'kcc' ? 'KCC' : 'OD-2' ?>
                                        renewal worklist
                                    </span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if (($lead['days_past_due'] ?? null) !== null): ?>
                        <div>
                            <dt>Days past due</dt>
                            <dd><?= e((string) (int) $lead['days_past_due']) ?>
                                <span class="text-muted" style="font-size:.75rem">as the bank computed it</span></dd>
                        </div>
                    <?php endif; ?>
                    <?php foreach ([
                        'installment_amount'  => 'Instalment / EMI',
                        'last_payment_amount' => 'Last payment',
                        'security_value'      => 'Security value',
                    ] as $moneyColumn => $moneyLabel): ?>
                        <?php if (($lead[$moneyColumn] ?? null) !== null): ?>
                            <div>
                                <dt><?= e($moneyLabel) ?></dt>
                                <dd><?= e(rupees($lead[$moneyColumn])) ?></dd>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (($lead['interest_rate'] ?? null) !== null): ?>
                        <div>
                            <dt>Interest rate</dt>
                            <dd><?= e(rtrim(rtrim(number_format((float) $lead['interest_rate'], 3), '0'), '.')) ?>% p.a.</dd>
                        </div>
                    <?php endif; ?>
                    <?php foreach ([
                        'last_payment_date' => 'Last payment date',
                        'maturity_date'     => 'Maturity date',
                    ] as $dateColumn => $dateLabel): ?>
                        <?php if (($lead[$dateColumn] ?? null) !== null): ?>
                            <div>
                                <dt><?= e($dateLabel) ?></dt>
                                <dd><?= e(fmt_date((string) $lead[$dateColumn])) ?></dd>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php foreach ([
                        'guarantor_name' => 'Guarantor',
                        'purpose'        => 'Purpose / activity',
                    ] as $textColumn => $textLabel): ?>
                        <?php if (!empty($lead[$textColumn])): ?>
                            <div>
                                <dt><?= e($textLabel) ?></dt>
                                <dd><?= e((string) $lead[$textColumn]) ?></dd>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div>
                        <dt>Probable NPA date / NPA date</dt>
                        <dd>
                            <?php if ($lead['npa_date'] !== null): ?>
                                <?= e(fmt_date((string) $lead['npa_date'])) ?>
                                <span class="lrms-npa">NPA</span>
                            <?php else: ?>
                                <span class="text-muted">Not classified</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Current status</dt>
                        <dd><?= status_badge($lead['current_status']) ?></dd>
                    </div>
                    <div>
                        <dt>Branch</dt>
                        <dd><?= e($lead['branch_name']) ?> <span class="text-muted">(<?= e($lead['branch_code']) ?>)</span></dd>
                    </div>
                    <div>
                        <dt>Assigned agent</dt>
                        <dd>
                            <?php if (!empty($lead['agent_name'])): ?>
                                <?= e($lead['agent_name']) ?>
                                <span class="text-muted" style="font-size:.75rem">(<?= e($lead['agent_code'] ?? '') ?>)</span>
                            <?php else: ?>
                                <span class="lrms-badge badge-pending">Unassigned</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if ($lead['next_followup_date'] !== null): ?>
                        <div>
                            <dt>Next follow-up</dt>
                            <dd><?= e(fmt_date((string) $lead['next_followup_date'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php
                    /*
                     * The sanction side of the passbook, shown only when there is something
                     * to show: three empty rows on every account would push the figures
                     * people came here for off the first screen.
                     */
                    ?>
                    <?php foreach ([
                        'sanction_limit'   => 'Sanction limit',
                        'drawing_power'    => 'Drawing power',
                        'interest_overdue' => 'Interest overdue',
                    ] as $column => $label): ?>
                        <?php if (($lead[$column] ?? null) !== null): ?>
                            <div>
                                <dt><?= e($label) ?></dt>
                                <dd><?= e(money($lead[$column])) ?></dd>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (($lead['sanction_date'] ?? null) !== null): ?>
                        <div>
                            <dt>Sanction date</dt>
                            <dd><?= e(fmt_date((string) $lead['sanction_date'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php
                    /*
                     * Was "Remarks from import", which stopped being true when this became
                     * editable - it is now the standing note on the account, and mostly what
                     * an agent learned at the door. Shown empty with a prompt for the same
                     * reason as the second mobile: a field nobody can see is a field nobody
                     * fills in.
                     */
                    ?>
                    <div style="grid-column:1/-1">
                        <dt>Notes on this account</dt>
                        <dd style="font-weight:400">
                            <?php if (!empty($lead['remarks'])): ?>
                                <?= nl2br(e((string) $lead['remarks'])) ?>
                            <?php else: ?>
                                <span class="text-muted">
                                    Nothing recorded
                                    <?php if (can('customers.update')): ?>
                                        &mdash; <a href="<?= e(url('/customers/' . (int) $lead['id'] . '/edit') . '#loan') ?>">add a note</a>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Other loans for the same borrower -->
        <?php if (count($otherLoans) > 1 || can('customers.create')): ?>
            <div class="lrms-card mb-3">
                <div class="lrms-card-head">
                    <h2><?= icon('file') ?> Other accounts for this borrower</h2>
                    <?php if (can('customers.create')): ?>
                        <a class="btn btn-ghost btn-sm"
                           href="<?= e(url('/customers/create') . '?customer_id=' . (int) $lead['customer_id']) ?>">
                            <?= icon('plus') ?> Add an account
                        </a>
                    <?php endif; ?>
                </div>
                <?php if (count($otherLoans) <= 1): ?>
                    <div class="lrms-card-body">
                        <p class="text-muted mb-0" style="font-size:.8125rem">
                            This is the only account on record for this borrower.
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (count($otherLoans) > 1): ?>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead>
                            <tr><th>Account</th><th>Type</th><th class="text-end">Outstanding</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($otherLoans as $other): ?>
                                <?php if ((int) $other['id'] === (int) $lead['id']) { continue; } ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(url('/customers/' . (int) $other['id'])) ?>"
                                           class="font-mono" style="font-size:.75rem">
                                            <?= e($other['loan_account_number']) ?>
                                        </a>
                                    </td>
                                    <td style="font-size:.8125rem"><?= nullable($other['loan_type']) ?></td>
                                    <td class="num"><?= e(money($other['outstanding_amount'], false)) ?></td>
                                    <td><?= status_badge($other['current_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Promise history -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <h2><?= icon('handshake') ?> Promise history</h2>
                <span class="text-muted" style="font-size:.75rem"><?= e((string) count($promises)) ?> record(s)</span>
            </div>

            <?php if ($promises === []): ?>
                <div class="lrms-card-body">
                    <p class="text-muted mb-0" style="font-size:.875rem">No promises recorded for this account.</p>
                </div>
            <?php else: ?>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead>
                            <tr>
                                <th class="text-end">Amount</th>
                                <th>Promise date</th>
                                <th>Agent</th>
                                <th>Status</th>
                                <?php if (can('promises.update')): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($promises as $promise): ?>
                                <tr>
                                    <td class="num" style="font-weight:620">
                                        <?= e(money($promise['promise_amount'])) ?>
                                    </td>
                                    <td class="nowrap" style="font-size:.8125rem">
                                        <?= e(fmt_date((string) $promise['promise_date'])) ?>
                                    </td>
                                    <td style="font-size:.8125rem"><?= e($promise['agent_name']) ?></td>
                                    <td><?= promise_badge($promise['status']) ?></td>
                                    <?php if (can('promises.update')): ?>
                                        <td class="text-end nowrap">
                                            <?php if ((string) $promise['status'] === 'pending'): ?>
                                                <form method="post" class="d-inline m-0"
                                                      action="<?= e(url('/promises/' . (int) $promise['id'] . '/settle')) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="status" value="kept">
                                                    <input type="hidden" name="return_to"
                                                           value="<?= e('/customers/' . (int) $lead['id']) ?>">
                                                    <button class="btn btn-ghost btn-sm text-success" title="Mark kept"
                                                            data-bs-toggle="tooltip"><?= icon('check') ?></button>
                                                </form>
                                                <form method="post" class="d-inline m-0"
                                                      action="<?= e(url('/promises/' . (int) $promise['id'] . '/settle')) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="status" value="broken">
                                                    <input type="hidden" name="return_to"
                                                           value="<?= e('/customers/' . (int) $lead['id']) ?>">
                                                    <button class="btn btn-ghost btn-sm text-danger" title="Mark broken"
                                                            data-bs-toggle="tooltip"><?= icon('x') ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== Right column ==================== -->
    <div class="col-xl-7">

        <!-- Timeline -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <div>
                    <h2><?= icon('clock') ?> Timeline</h2>
                    <p>Append-only history &mdash; entries are never edited or removed</p>
                </div>
                <span class="text-muted" style="font-size:.75rem"><?= e((string) count($timeline)) ?> event(s)</span>
            </div>
            <div class="lrms-card-body">
                <?php if ($timeline === []): ?>
                    <p class="text-muted mb-0" style="font-size:.875rem">No events recorded yet.</p>
                <?php else: ?>
                    <ul class="lrms-timeline">
                        <?php foreach ($timeline as $event): ?>
                            <?php $meta = $event['event_meta']; ?>
                            <li class="lrms-tl-item">
                                <span class="lrms-tl-dot tone-<?= e($meta['tone']) ?>">
                                    <?= icon($meta['icon']) ?>
                                </span>

                                <div class="lrms-tl-head">
                                    <span class="lrms-tl-title"><?= e($event['title']) ?></span>
                                    <span class="lrms-tl-time">
                                        <?= e(fmt_datetime((string) $event['event_at'])) ?>
                                    </span>
                                </div>

                                <?php if (!empty($event['description'])): ?>
                                    <div class="lrms-tl-body"><?= e($event['description']) ?></div>
                                <?php endif; ?>

                                <div class="lrms-tl-meta">
                                    <?php if (!empty($event['actor_name'])): ?>
                                        <span><?= icon('user') ?> <?= e($event['actor_name']) ?></span>
                                    <?php endif; ?>

                                    <?php if ((int) ($event['photo_count'] ?? 0) > 0): ?>
                                        <span><?= icon('image') ?> <?= e((string) (int) $event['photo_count']) ?> photo(s)</span>
                                    <?php endif; ?>

                                    <?php if (!empty($event['promise_amount'])): ?>
                                        <span><?= icon('handshake') ?>
                                            <?= e(money($event['promise_amount'], false)) ?>
                                            by <?= e(fmt_date((string) $event['promise_date'])) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($event['visit_report_id']) && can('visits.view')): ?>
                                        <a href="<?= e(url('/visits/' . (int) $event['visit_report_id'])) ?>">
                                            View full report
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Visit history -->
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <div>
                    <h2><?= icon('clipboard') ?> Visit history</h2>
                    <p>Newest first</p>
                </div>
            </div>

            <?php if ($visits === []): ?>
                <div class="lrms-card-body">
                    <p class="text-muted mb-0" style="font-size:.875rem">No field visits submitted yet.</p>
                </div>
            <?php else: ?>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Agent</th>
                                <th>Contact</th>
                                <th>Recovery</th>
                                <th>Attachments</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visits as $visit): ?>
                                <tr>
                                    <td class="nowrap" style="font-size:.8125rem">
                                        <?= e(fmt_date((string) $visit['visit_date'], 'd M y')) ?>
                                        <div class="text-muted" style="font-size:.6875rem">
                                            <?= e(fmt_time((string) $visit['visit_time'])) ?>
                                        </div>
                                    </td>
                                    <td style="font-size:.8125rem"><?= e($visit['agent_name']) ?></td>
                                    <td>
                                        <?php if ((int) $visit['customer_met'] === 1): ?>
                                            <span class="lrms-badge badge-visited">Customer met</span>
                                        <?php elseif ((int) $visit['family_member_met'] === 1): ?>
                                            <span class="lrms-badge badge-promise">Family met</span>
                                        <?php elseif ((int) $visit['house_locked'] === 1): ?>
                                            <span class="lrms-badge badge-pending">House locked</span>
                                        <?php elseif ((int) $visit['phone_switched_off'] === 1): ?>
                                            <span class="lrms-badge badge-legal">Phone off</span>
                                        <?php elseif ((int) $visit['phone_contact'] === 1): ?>
                                            <span class="lrms-badge badge-followup">Phone contact</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((float) ($visit['promise_amount'] ?? 0) > 0): ?>
                                            <span class="lrms-badge badge-promise">
                                                <?= e(money($visit['promise_amount'], false)) ?>
                                            </span>
                                        <?php elseif ((int) $visit['ready_to_pay'] === 1): ?>
                                            <span class="lrms-badge badge-visited">Ready to pay</span>
                                        <?php elseif ((int) $visit['not_ready'] === 1): ?>
                                            <span class="lrms-badge badge-legal">Not ready</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="nowrap text-muted" style="font-size:.75rem">
                                        <?php if ((int) $visit['photo_count'] > 0): ?>
                                            <?= icon('image') ?> <?= e((string) (int) $visit['photo_count']) ?>
                                        <?php endif; ?>
                                        <?php if ((int) $visit['document_count'] > 0): ?>
                                            <?= icon('file') ?> <?= e((string) (int) $visit['document_count']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (can('visits.view')): ?>
                                            <a href="<?= e(url('/visits/' . (int) $visit['id'])) ?>"
                                               class="btn btn-ghost btn-sm btn-icon" title="Open report"
                                               data-bs-toggle="tooltip"><?= icon('eye') ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Photos -->
        <?php if ($photos !== []): ?>
            <div class="lrms-card mb-3">
                <div class="lrms-card-head">
                    <h2><?= icon('image') ?> Photo gallery</h2>
                    <span class="text-muted" style="font-size:.75rem"><?= e((string) count($photos)) ?> photo(s)</span>
                </div>
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <?php foreach ($photos as $photo): ?>
                            <?php $hasFix = Geo::has($photo); ?>
                            <div class="col-6 col-lg-4">
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
                                            <?= geo_source_badge((string) ($photo['capture_source'] ?? 'unknown')) ?>
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
                                                              title="Too coarse to place a particular house"
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

                                        <div class="text-muted" style="font-size:.6875rem;margin-top:2px">
                                            <?php if (($photo['captured_at'] ?? null) !== null): ?>
                                                Taken <?= e(fmt_datetime((string) $photo['captured_at'])) ?>
                                            <?php elseif (!empty($photo['visit_date'])): ?>
                                                Visit of <?= e(fmt_date((string) $photo['visit_date'], 'd M Y')) ?>
                                            <?php endif; ?>
                                        </div>
                                    </figcaption>
                                </figure>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Documents -->
        <?php if ($documents !== []): ?>
            <div class="lrms-card">
                <div class="lrms-card-head">
                    <h2><?= icon('file') ?> Documents</h2>
                </div>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead><tr><th>Document</th><th>Type</th><th>Uploaded</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($documents as $document): ?>
                                <tr>
                                    <td style="font-size:.8125rem"><?= e($document['title'] ?? $document['original_name']) ?></td>
                                    <td style="font-size:.8125rem"><?= e($document['doc_type']) ?></td>
                                    <td class="text-muted" style="font-size:.75rem">
                                        <?= e(fmt_date((string) $document['created_at'])) ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= e(Url::media((string) $document['file_path'])) ?>"
                                           target="_blank" rel="noopener"
                                           class="btn btn-ghost btn-sm btn-icon" title="Open"
                                           data-bs-toggle="tooltip"><?= icon('external') ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==================== Assign modal ==================== -->
<?php if ($agents !== [] && (string) $lead['current_status'] !== 'closed'): ?>
    <div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true" aria-labelledby="assignModalLabel">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= e(url('/customers/bulk')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="lead_ids[]" value="<?= e((string) $lead['id']) ?>">
                <input type="hidden" name="bulk_action"
                       value="<?= $lead['assigned_agent_id'] === null ? 'assign' : 'reassign' ?>">
                <input type="hidden" name="return_query" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="assignModalLabel">
                        <?= $lead['assigned_agent_id'] === null ? 'Assign to agent' : 'Reassign lead' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="text-muted" style="font-size:.8438rem">
                        Account <span class="font-mono"><?= e($lead['loan_account_number']) ?></span>
                        &mdash; <?= e($lead['customer_name']) ?>
                    </p>
                    <label class="form-label" for="assign-agent">BC agent <span class="req">*</span></label>
                    <select class="form-select" id="assign-agent" name="agent_id_action" required>
                        <option value="">Select agent…</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= e((string) $agent['id']) ?>"
                                <?= (int) ($lead['assigned_agent_id'] ?? 0) === (int) $agent['id'] ? 'disabled' : '' ?>>
                                <?= e($agent['name']) ?> (<?= e($agent['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">The agent will receive a notification in the app.</div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
