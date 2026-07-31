<?php
/**
 * Empty-state block for tables and lists.
 *
 * @var string      $heading
 * @var string      $message
 * @var string|null $iconName
 * @var string|null $actionLabel
 * @var string|null $actionUrl
 */
?>
<div class="lrms-empty">
    <?= icon($iconName ?? 'inbox') ?>
    <h3><?= e($heading ?? 'Nothing to show') ?></h3>
    <p><?= e($message ?? 'There are no records matching the current filters.') ?></p>

    <?php if (!empty($actionUrl) && !empty($actionLabel)): ?>
        <a class="btn btn-primary btn-sm mt-3" href="<?= e($actionUrl) ?>">
            <?= icon('plus') ?> <?= e($actionLabel) ?>
        </a>
    <?php endif; ?>
</div>
