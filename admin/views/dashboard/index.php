<?php
/**
 * Branch dashboard.
 *
 * @var array<string,mixed>                      $data
 * @var int|null                                 $branchId
 * @var list<array<string,mixed>>                $branches
 * @var list<array{key:string,label:string,group:string}> $missingSettings
 * @var array<string,mixed>|null                 $authUser
 */

use App\Core\Url;

$cards = $data['cards'];
$trend = $data['visit_trend'];
$maxTrend = 1;
foreach ($trend as $point) {
    $maxTrend = max($maxTrend, (int) $point['total']);
}

$statusTones = [
    'pending'  => 'var(--lrms-warning)',
    'visited'  => 'var(--lrms-success)',
    'promise'  => 'var(--lrms-primary)',
    'followup' => 'var(--lrms-slate)',
    'legal'    => 'var(--lrms-danger)',
    'closed'   => 'var(--lrms-muted)',
];
$totalLeads = max(1, (int) $cards['total_leads']);
?>

<div class="lrms-page-head">
    <div>
        <h1>Dashboard</h1>
        <p>
            <?php if (!empty($authUser['branch_name'])): ?>
                <?= e($authUser['branch_name']) ?> · recovery overview
            <?php else: ?>
                Recovery overview across all branches
            <?php endif; ?>
        </p>
    </div>

    <?php if ($branches !== []): ?>
        <form method="get" action="<?= e(url('/dashboard')) ?>" class="d-flex gap-2">
            <select name="branch_id" class="form-select form-select-sm" data-auto-submit
                    style="min-width:200px" aria-label="Filter by branch">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= e((string) $branch['id']) ?>"
                        <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>>
                        <?= e($branch['name']) ?> (<?= e($branch['branch_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if ($missingSettings !== []): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <div>
            <strong>Missing configuration.</strong>
            <?= e(count($missingSettings)) ?> required setting<?= count($missingSettings) === 1 ? '' : 's' ?>
            <?= count($missingSettings) === 1 ? 'is' : 'are' ?> still blank:
            <?php
            $names = array_map(static fn (array $s): string => $s['label'], array_slice($missingSettings, 0, 6));
            echo e(implode(', ', $names));
            echo count($missingSettings) > 6 ? ' and ' . (count($missingSettings) - 6) . ' more' : '';
            ?>.
            <a href="<?= e(url('/settings')) ?>" class="fw-semibold">Open Settings</a>
        </div>
    </div>
<?php endif; ?>

<!-- ==================== Primary counters ==================== -->
<div class="lrms-stats">
    <a class="lrms-stat lrms-stat-accent" href="<?= e(url('/customers')) ?>">
        <div class="lrms-stat-label"><?= icon('customers') ?> Total leads</div>
        <div class="lrms-stat-value"><?= e(number_format((int) $cards['total_leads'])) ?></div>
        <div class="lrms-stat-sub"><?= e(number_format((int) $cards['unassigned'])) ?> unassigned</div>
    </a>

    <a class="lrms-stat lrms-stat-accent is-warning" href="<?= e(url('/customers?status=pending')) ?>">
        <div class="lrms-stat-label"><?= icon('clock') ?> Pending</div>
        <div class="lrms-stat-value"><?= e(number_format((int) $cards['pending'])) ?></div>
        <div class="lrms-stat-sub">Not yet visited</div>
    </a>

    <a class="lrms-stat lrms-stat-accent is-success" href="<?= e(url('/customers?status=visited')) ?>">
        <div class="lrms-stat-label"><?= icon('check-circle') ?> Visited</div>
        <div class="lrms-stat-value"><?= e(number_format((int) $cards['visited'])) ?></div>
        <div class="lrms-stat-sub"><?= e(number_format((int) $cards['visits_today'])) ?> visits today</div>
    </a>

    <a class="lrms-stat lrms-stat-accent" href="<?= e(url('/promises?status=pending')) ?>">
        <div class="lrms-stat-label"><?= icon('handshake') ?> Promise cases</div>
        <div class="lrms-stat-value"><?= e(number_format((int) $cards['promise_cases'])) ?></div>
        <div class="lrms-stat-sub">
            <?= e(number_format((int) $data['promise_counts']['overdue'])) ?> past due
        </div>
    </a>

    <a class="lrms-stat lrms-stat-accent is-danger" href="<?= e(url('/customers?npa_only=1')) ?>">
        <div class="lrms-stat-label"><?= icon('alert') ?> NPA cases</div>
        <div class="lrms-stat-value"><?= e(number_format((int) $cards['npa_cases'])) ?></div>
        <div class="lrms-stat-sub">Classified non-performing</div>
    </a>

    <a class="lrms-stat lrms-stat-accent is-slate" href="<?= e(url('/customers?status=closed')) ?>">
        <div class="lrms-stat-label"><?= icon('lock') ?> Closed</div>
        <div class="lrms-stat-value"><?= e(number_format((int) $cards['closed'])) ?></div>
        <div class="lrms-stat-sub">Recovery complete</div>
    </a>
</div>

<!-- ==================== Money + activity ==================== -->
<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3">
        <div class="lrms-stat h-100">
            <div class="lrms-stat-label"><?= icon('money') ?> Total outstanding</div>
            <div class="lrms-stat-value sm"><?= e(rupees($cards['outstanding'], false)) ?></div>
            <div class="lrms-stat-sub">Across <?= e(number_format((int) $cards['total_leads'])) ?> accounts</div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="lrms-stat h-100">
            <div class="lrms-stat-label"><?= icon('alert') ?> Total overdue</div>
            <div class="lrms-stat-value sm" style="color:var(--lrms-danger)">
                <?= e(rupees($cards['overdue'], false)) ?>
            </div>
            <div class="lrms-stat-sub">
                <?php
                $ratio = (float) $cards['outstanding'] > 0
                    ? round(100 * (float) $cards['overdue'] / (float) $cards['outstanding'], 1)
                    : 0.0;
                ?>
                <?= e((string) $ratio) ?>% of outstanding
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="lrms-stat h-100">
            <div class="lrms-stat-label"><?= icon('clipboard') ?> Visits this month</div>
            <div class="lrms-stat-value sm"><?= e(number_format((int) $cards['visits_month'])) ?></div>
            <div class="lrms-stat-sub"><?= e(number_format((int) $cards['visits_week'])) ?> in the last 7 days</div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="lrms-stat h-100">
            <div class="lrms-stat-label"><?= icon('users') ?> Active agents</div>
            <div class="lrms-stat-value sm"><?= e(number_format((int) $cards['active_agents'])) ?></div>
            <div class="lrms-stat-sub">BC field agents</div>
        </div>
    </div>
</div>

<div class="row g-3">

    <!-- ==================== Visit trend ==================== -->
    <div class="col-xl-8">
        <div class="lrms-card h-100">
            <div class="lrms-card-head">
                <div>
                    <h2>Visit activity</h2>
                    <p>Field visits recorded over the last 14 days</p>
                </div>
                <span class="lrms-badge badge-visited">
                    <?= e(number_format(array_sum(array_column($trend, 'total')))) ?> total
                </span>
            </div>
            <div class="lrms-card-body">
                <?php if (array_sum(array_column($trend, 'total')) === 0): ?>
                    <p class="text-muted mb-0" style="font-size:.875rem">
                        No visits recorded in this period yet.
                    </p>
                <?php else: ?>
                    <div class="lrms-bars">
                        <?php foreach ($trend as $point): ?>
                            <div class="lrms-bar-col">
                                <div class="lrms-bar"
                                     style="height:<?= e((string) max(2, (int) round(100 * (int) $point['total'] / $maxTrend))) ?>%"
                                     title="<?= e($point['label'] . ': ' . $point['total'] . ' visit(s)') ?>"
                                     data-bs-toggle="tooltip"></div>
                                <div class="lrms-bar-label"><?= e($point['label']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==================== Status breakdown ==================== -->
    <div class="col-xl-4">
        <div class="lrms-card h-100">
            <div class="lrms-card-head">
                <div>
                    <h2>Lead status</h2>
                    <p>Distribution of the current portfolio</p>
                </div>
            </div>
            <div class="lrms-card-body">
                <div class="lrms-split mb-3">
                    <?php foreach ($data['status_breakdown'] as $row): ?>
                        <?php if ((int) $row['total'] > 0): ?>
                            <span style="width:<?= e((string) (100 * (int) $row['total'] / $totalLeads)) ?>%;
                                         background:<?= e($statusTones[$row['status']] ?? 'var(--lrms-slate)') ?>"
                                  title="<?= e($row['label'] . ': ' . $row['total']) ?>"
                                  data-bs-toggle="tooltip"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($data['status_breakdown'] as $row): ?>
                    <div class="d-flex align-items-center gap-2 py-1">
                        <span style="width:9px;height:9px;border-radius:50%;flex:0 0 auto;
                                     background:<?= e($statusTones[$row['status']] ?? 'var(--lrms-slate)') ?>"></span>
                        <a href="<?= e(url('/customers?status=' . $row['status'])) ?>"
                           class="flex-grow-1 text-decoration-none"
                           style="font-size:.8438rem;color:var(--lrms-ink)"><?= e($row['label']) ?></a>
                        <span class="num" style="font-size:.8438rem;font-weight:620">
                            <?= e(number_format((int) $row['total'])) ?>
                        </span>
                        <span class="text-muted" style="font-size:.75rem;width:44px;text-align:right">
                            <?= e((string) round(100 * (int) $row['total'] / $totalLeads, 1)) ?>%
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ==================== Top agents ==================== -->
    <div class="col-xl-6">
        <div class="lrms-card h-100">
            <div class="lrms-card-head">
                <div>
                    <h2>Top agents this month</h2>
                    <p>Ranked by visits recorded</p>
                </div>
                <?php if (can('reports.view')): ?>
                    <a href="<?= e(url('/reports/agent')) ?>" class="btn btn-outline-secondary btn-sm">
                        Full report
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($data['top_agents'] === []): ?>
                <?= \App\Core\View::partial('partials/empty', [
                    'heading'  => 'No agents yet',
                    'message'  => 'Create BC agent accounts to start assigning leads.',
                    'iconName' => 'users',
                ]) ?>
            <?php else: ?>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th class="text-end">Assigned</th>
                                <th class="text-end">Visits</th>
                                <th class="text-end">Today</th>
                                <th class="text-end">Kept</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['top_agents'] as $agent): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="lrms-avatar" style="width:26px;height:26px;font-size:.6875rem">
                                                <?= e(mb_substr((string) $agent['name'], 0, 1)) ?>
                                            </span>
                                            <span>
                                                <span class="d-block" style="font-weight:600">
                                                    <?= e($agent['name']) ?>
                                                </span>
                                                <span class="d-block text-muted" style="font-size:.6875rem">
                                                    <?= e($agent['employee_code']) ?>
                                                    <?= !empty($agent['branch_name']) ? ' · ' . e($agent['branch_name']) : '' ?>
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="num"><?= e(number_format((int) $agent['assigned_leads'])) ?></td>
                                    <td class="num" style="font-weight:650">
                                        <?= e(number_format((int) $agent['visits_month'])) ?>
                                    </td>
                                    <td class="num"><?= e(number_format((int) $agent['visits_today'])) ?></td>
                                    <td class="num"><?= e(number_format((int) $agent['promises_kept'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== Overdue promises ==================== -->
    <div class="col-xl-6">
        <div class="lrms-card h-100">
            <div class="lrms-card-head">
                <div>
                    <h2>Promises past due</h2>
                    <p>Pending promises whose date has passed</p>
                </div>
                <?php if (can('promises.view')): ?>
                    <a href="<?= e(url('/promises?status=pending')) ?>" class="btn btn-outline-secondary btn-sm">
                        View all
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($data['overdue_promises'] === []): ?>
                <?= \App\Core\View::partial('partials/empty', [
                    'heading'  => 'Nothing overdue',
                    'message'  => 'Every recorded promise is either upcoming or already settled.',
                    'iconName' => 'check-circle',
                ]) ?>
            <?php else: ?>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Customer</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Overdue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['overdue_promises'] as $promise): ?>
                                <tr>
                                    <td class="nowrap">
                                        <a href="<?= e(url('/customers/' . (int) $promise['loan_account_id'])) ?>"
                                           class="font-mono" style="font-size:.75rem">
                                            <?= e($promise['loan_account_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="d-block" style="font-weight:550"><?= e($promise['customer_name']) ?></span>
                                        <span class="d-block text-muted" style="font-size:.6875rem">
                                            <?= e($promise['agent_name']) ?>
                                        </span>
                                    </td>
                                    <td class="num"><?= e(money($promise['promise_amount'], false)) ?></td>
                                    <td class="num">
                                        <span class="lrms-badge badge-legal">
                                            <?= e((string) (int) $promise['days_overdue']) ?>d
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== Branch table (super admin) ==================== -->
    <?php if ($data['branch_rows'] !== []): ?>
        <div class="col-12">
            <div class="lrms-card">
                <div class="lrms-card-head">
                    <div>
                        <h2>Branch performance</h2>
                        <p>Leads, recovery status and outstanding by branch</p>
                    </div>
                    <?php if (can('reports.view')): ?>
                        <a href="<?= e(url('/reports/branch')) ?>" class="btn btn-outline-secondary btn-sm">
                            <?= icon('reports') ?> Branch report
                        </a>
                    <?php endif; ?>
                </div>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Branch</th>
                                <th class="text-end">Leads</th>
                                <th class="text-end">Pending</th>
                                <th class="text-end">Promise</th>
                                <th class="text-end">NPA</th>
                                <th class="text-end">Visits (MTD)</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-end">Overdue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['branch_rows'] as $row): ?>
                                <tr>
                                    <td class="font-mono" style="font-size:.75rem"><?= e($row['branch_code']) ?></td>
                                    <td style="font-weight:550">
                                        <a href="<?= e(url('/dashboard?branch_id=' . (int) $row['id'])) ?>">
                                            <?= e($row['name']) ?>
                                        </a>
                                    </td>
                                    <td class="num"><?= e(number_format((int) $row['total_leads'])) ?></td>
                                    <td class="num"><?= e(number_format((int) $row['pending'])) ?></td>
                                    <td class="num"><?= e(number_format((int) $row['promise_cases'])) ?></td>
                                    <td class="num"><?= e(number_format((int) $row['npa_cases'])) ?></td>
                                    <td class="num"><?= e(number_format((int) $row['visits_month'])) ?></td>
                                    <td class="num"><?= e(money($row['outstanding'], false)) ?></td>
                                    <td class="num" style="color:var(--lrms-danger)">
                                        <?= e(money($row['overdue'], false)) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ==================== Recent visits ==================== -->
    <div class="col-xl-7">
        <div class="lrms-card h-100">
            <div class="lrms-card-head">
                <div>
                    <h2>Latest field visits</h2>
                    <p>Most recent submissions from the app</p>
                </div>
                <?php if (can('visits.view')): ?>
                    <a href="<?= e(url('/visits')) ?>" class="btn btn-outline-secondary btn-sm">View all</a>
                <?php endif; ?>
            </div>

            <?php if ($data['recent_visits'] === []): ?>
                <?= \App\Core\View::partial('partials/empty', [
                    'heading'  => 'No visits yet',
                    'message'  => 'Field visit reports submitted from the Android app will appear here.',
                    'iconName' => 'clipboard',
                ]) ?>
            <?php else: ?>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Account</th>
                                <th>Customer</th>
                                <th>Agent</th>
                                <th>Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['recent_visits'] as $visit): ?>
                                <tr>
                                    <td class="nowrap text-muted" style="font-size:.75rem">
                                        <?= e(time_ago($visit['created_at'])) ?>
                                    </td>
                                    <td class="nowrap">
                                        <a href="<?= e(url('/customers/' . (int) $visit['loan_account_id'])) ?>"
                                           class="font-mono" style="font-size:.75rem">
                                            <?= e($visit['loan_account_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="d-block" style="font-weight:550"><?= e($visit['customer_name']) ?></span>
                                        <span class="d-block text-muted" style="font-size:.6875rem">
                                            <?= e($visit['village'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.8125rem"><?= e($visit['agent_name']) ?></td>
                                    <td>
                                        <?php if ((int) $visit['customer_met'] === 1): ?>
                                            <span class="lrms-badge badge-visited">Customer met</span>
                                        <?php elseif ((int) $visit['house_locked'] === 1): ?>
                                            <span class="lrms-badge badge-pending">House locked</span>
                                        <?php else: ?>
                                            <span class="lrms-badge badge-followup">Recorded</span>
                                        <?php endif; ?>

                                        <?php if ((float) ($visit['promise_amount'] ?? 0) > 0): ?>
                                            <span class="lrms-badge badge-promise">
                                                <?= e(money($visit['promise_amount'], false)) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== Loan type split ==================== -->
    <div class="col-xl-5">
        <div class="lrms-card h-100">
            <div class="lrms-card-head">
                <div>
                    <h2>Portfolio by loan type</h2>
                    <p>Outstanding split across products</p>
                </div>
            </div>
            <div class="lrms-card-body">
                <?php if ($data['loan_type_split'] === []): ?>
                    <p class="text-muted mb-0" style="font-size:.875rem">No loan accounts yet.</p>
                <?php else: ?>
                    <?php
                    $maxOutstanding = 1.0;
                    foreach ($data['loan_type_split'] as $row) {
                        $maxOutstanding = max($maxOutstanding, (float) $row['outstanding']);
                    }
                    ?>
                    <?php foreach ($data['loan_type_split'] as $row): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline mb-1">
                                <span style="font-size:.8438rem;font-weight:550"><?= e($row['label']) ?></span>
                                <span class="num text-muted" style="font-size:.75rem">
                                    <?= e(number_format((int) $row['total'])) ?> a/c ·
                                    <?= e(rupees($row['outstanding'], false)) ?>
                                </span>
                            </div>
                            <div class="lrms-meter">
                                <span style="width:<?= e((string) round(100 * (float) $row['outstanding'] / $maxOutstanding, 1)) ?>%"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
