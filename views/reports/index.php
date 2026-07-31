<?php
/** @var array<string,array{label:string,description:string}> $types */

$icons = [
    'daily'     => 'calendar',
    'weekly'    => 'calendar',
    'monthly'   => 'chart',
    'branch'    => 'branch',
    'village'   => 'village',
    'loan-type' => 'money',
    'agent'     => 'users',
    'promise'   => 'handshake',
];
?>

<div class="lrms-page-head">
    <div>
        <h1>Reports</h1>
        <p>Eight report types, each filterable and exportable to Excel, PDF or print</p>
    </div>
</div>

<div class="lrms-report-grid">
    <?php foreach ($types as $type => $meta): ?>
        <a class="lrms-report-card" href="<?= e(url('/reports/' . $type)) ?>">
            <span class="icon"><?= icon($icons[$type] ?? 'reports') ?></span>
            <h3><?= e($meta['label']) ?></h3>
            <p><?= e($meta['description']) ?></p>
            <span class="mt-2 d-flex align-items-center gap-1" style="font-size:.75rem;color:var(--lrms-primary);font-weight:600">
                Open report <?= icon('chevron-right') ?>
            </span>
        </a>
    <?php endforeach; ?>
</div>

<div class="lrms-card mt-3">
    <div class="lrms-card-body" style="font-size:.8438rem;color:var(--lrms-slate)">
        <strong>Note:</strong> every report respects your access scope. Branch Managers see only their own
        branch; Super Admins can filter across all branches. Exports are recorded in the activity log.
    </div>
</div>
