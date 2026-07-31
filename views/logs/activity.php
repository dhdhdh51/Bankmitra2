<?php
/**
 * @var \App\Core\Paginator       $logs
 * @var list<string>              $activities
 * @var list<array<string,mixed>> $users
 * @var array<string,mixed>       $filters
 */
?>

<div class="lrms-page-head">
    <div>
        <h1>Activity Logs</h1>
        <p>Sign-ins, sign-outs, exports and page-level actions</p>
    </div>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/logs/activity')) ?>">
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="ac-search">Search</label>
                    <input type="search" class="form-control" id="ac-search" name="search"
                           value="<?= e($filters['search']) ?>" placeholder="User, description, URL, IP">
                </div>

                <div>
                    <label class="form-label" for="ac-activity">Activity</label>
                    <select class="form-select" id="ac-activity" name="activity" data-auto-submit>
                        <option value="">All activities</option>
                        <?php foreach ($activities as $activity): ?>
                            <option value="<?= e($activity) ?>" <?= $filters['activity'] === $activity ? 'selected' : '' ?>>
                                <?= e(ucfirst(str_replace('_', ' ', $activity))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="ac-user">User</label>
                    <select class="form-select" id="ac-user" name="user_id" data-auto-submit>
                        <option value="">All users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= e((string) $user['id']) ?>"
                                <?= ($filters['user_id'] ?? null) === (int) $user['id'] ? 'selected' : '' ?>>
                                <?= e($user['name'] ?? ('User #' . $user['id'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="ac-from">From</label>
                    <input type="date" class="form-control" id="ac-from" name="date_from" value="<?= e($filters['date_from']) ?>">
                </div>
                <div>
                    <label class="form-label" for="ac-to">To</label>
                    <input type="date" class="form-control" id="ac-to" name="date_to" value="<?= e($filters['date_to']) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Filter</button>
                    <a href="<?= e(url('/logs/activity')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lrms-card">
    <?php if ($logs->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'  => 'No activity recorded',
            'message'  => 'Sign-ins, exports and page views appear here.',
            'iconName' => 'clock',
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Activity</th>
                        <th>Module</th>
                        <th>Description</th>
                        <th>Method</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs->items as $log): ?>
                        <tr>
                            <td class="nowrap" style="font-size:.75rem">
                                <?= e(fmt_date((string) $log['created_at'], 'd M y')) ?>
                                <div class="text-muted"><?= e(fmt_date((string) $log['created_at'], 'H:i:s')) ?></div>
                            </td>
                            <td style="font-size:.8125rem"><?= nullable($log['user_name']) ?></td>
                            <td>
                                <?php
                                $activityClass = match ((string) $log['activity']) {
                                    'login'                        => 'badge-visited',
                                    'logout'                       => 'badge-closed',
                                    'failed_login', 'login_blocked' => 'badge-legal',
                                    'export'                       => 'badge-promise',
                                    default                        => 'badge-followup',
                                };
                                ?>
                                <span class="lrms-badge <?= $activityClass ?>">
                                    <?= e(ucfirst(str_replace('_', ' ', (string) $log['activity']))) ?>
                                </span>
                            </td>
                            <td style="font-size:.8125rem"><?= nullable($log['module']) ?></td>
                            <td style="font-size:.8125rem;max-width:360px"><?= nullable($log['description']) ?></td>
                            <td class="text-muted font-mono" style="font-size:.6875rem"><?= nullable($log['method']) ?></td>
                            <td class="text-muted font-mono" style="font-size:.6875rem"><?= nullable($log['ip']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="lrms-card-foot">
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $logs, 'label' => 'activity entries']) ?>
        </div>
    <?php endif; ?>
</div>
