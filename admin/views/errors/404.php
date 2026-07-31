<?php /** @var string $currentPath */ ?>

<div class="lrms-card">
    <div class="lrms-empty" style="padding:64px 20px">
        <?= icon('search') ?>
        <h3 style="font-size:1.1rem">Page not found</h3>
        <p>
            The address <code><?= e($currentPath ?? '') ?></code> does not exist.
            It may have been moved, or the link may be out of date.
        </p>
        <div class="d-flex gap-2 justify-content-center mt-3">
            <a class="btn btn-primary btn-sm" href="<?= e(url('/dashboard')) ?>">
                <?= icon('dashboard') ?> Back to dashboard
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/customers')) ?>">
                <?= icon('customers') ?> Customers &amp; Leads
            </a>
        </div>
    </div>
</div>
