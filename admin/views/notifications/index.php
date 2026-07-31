<?php
/**
 * @var \App\Core\Paginator $notifications
 * @var bool                $unreadOnly
 * @var int                 $unreadTotal
 * @var bool                $canSend
 */

use App\Core\Url;

$typeIcons = [
    'new_lead_assigned' => 'customers',
    'followup_reminder' => 'clock',
    'promise_reminder'  => 'handshake',
    'broadcast'         => 'bell',
];
?>

<div class="lrms-page-head">
    <div>
        <h1>Notifications</h1>
        <p>
            <?= $unreadTotal > 0
                ? e((string) $unreadTotal) . ' unread'
                : 'You are all caught up' ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($unreadTotal > 0): ?>
            <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <?= icon('check') ?> Mark all read
                </button>
            </form>
        <?php endif; ?>
        <?php if ($canSend): ?>
            <a href="<?= e(url('/notifications/send')) ?>" class="btn btn-primary btn-sm">
                <?= icon('send') ?> Send broadcast
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="lrms-chip<?= $unreadOnly ? '' : ' active' ?>" href="<?= e(Url::withQuery(['unread' => null, 'page' => null])) ?>">
        All
    </a>
    <a class="lrms-chip<?= $unreadOnly ? ' active' : '' ?>" href="<?= e(Url::withQuery(['unread' => '1', 'page' => null])) ?>">
        Unread <?php if ($unreadTotal > 0): ?><span class="count"><?= e((string) $unreadTotal) ?></span><?php endif; ?>
    </a>
</div>

<div class="lrms-card">
    <?php if ($notifications->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'  => $unreadOnly ? 'Nothing unread' : 'No notifications',
            'message'  => 'Lead assignments, follow-up reminders, promise reminders and broadcasts appear here.',
            'iconName' => 'bell',
        ]) ?>
    <?php else: ?>
        <?php foreach ($notifications->items as $notification): ?>
            <div class="lrms-notif<?= (int) $notification['is_read'] === 0 ? ' unread' : '' ?>">
                <?php if ((int) $notification['is_read'] === 0): ?>
                    <span class="dot" aria-label="Unread"></span>
                <?php else: ?>
                    <span style="width:7px;flex:0 0 auto"></span>
                <?php endif; ?>

                <span style="line-height:0;color:var(--lrms-primary);margin-top:2px">
                    <?= icon($typeIcons[(string) $notification['type']] ?? 'bell') ?>
                </span>

                <div class="body">
                    <div class="title"><?= e($notification['title']) ?></div>
                    <?php if (!empty($notification['body'])): ?>
                        <div class="text"><?= e($notification['body']) ?></div>
                    <?php endif; ?>
                    <div class="time">
                        <?= e(time_ago((string) $notification['created_at'])) ?>
                        · <?= e(fmt_datetime((string) $notification['created_at'])) ?>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-1">
                    <?php if (!empty($notification['loan_account_id'])): ?>
                        <a href="<?= e(url('/customers/' . (int) $notification['loan_account_id'])) ?>"
                           class="btn btn-ghost btn-sm">
                            <?= e($notification['loan_account_number'] ?? 'Open') ?>
                        </a>
                    <?php endif; ?>

                    <?php if ((int) $notification['is_read'] === 0): ?>
                        <form method="post" class="m-0"
                              action="<?= e(url('/notifications/' . (int) $notification['id'] . '/read')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-ghost btn-sm btn-icon"
                                    title="Mark as read" data-bs-toggle="tooltip"><?= icon('check') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="lrms-card-foot">
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $notifications, 'label' => 'notifications']) ?>
        </div>
    <?php endif; ?>
</div>
