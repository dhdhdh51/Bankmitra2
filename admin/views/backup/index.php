<?php
/**
 * @var list<array{file:string,size:int,created_at:int}> $backups
 * @var string                                           $database
 * @var int                                              $retention
 */

use App\Services\BackupService;
?>

<div class="lrms-page-head">
    <div>
        <h1>Database Backup</h1>
        <p>One-click SQL dump of <code><?= e($database) ?></code>, downloadable as a <code>.sql</code> file</p>
    </div>
    <form method="post" action="<?= e(url('/backup/run')) ?>" data-no-double-submit
          data-confirm="Create a new database backup now?">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary btn-sm">
            <?= icon('database') ?> Create backup now
        </button>
    </form>
</div>

<div class="alert alert-info">
    <?= icon('info') ?>
    <div>
        Backups are written outside the web root and are not directly downloadable by URL &mdash; they are
        streamed through this page after a permission check. Files older than
        <strong><?= e((string) $retention) ?> days</strong> are pruned automatically
        (configurable in Settings &rarr; Backup).
    </div>
</div>

<div class="lrms-card">
    <div class="lrms-card-head">
        <div>
            <h2>Available backups</h2>
            <p><?= e((string) count($backups)) ?> file(s) on disk</p>
        </div>
    </div>

    <?php if ($backups === []): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'  => 'No backups yet',
            'message'  => 'Create your first backup with the button above. For unattended backups, schedule the cron command from the deployment guide.',
            'iconName' => 'database',
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Created</th>
                        <th class="text-end">Size</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td class="font-mono" style="font-size:.75rem"><?= e($backup['file']) ?></td>
                            <td style="font-size:.8125rem">
                                <?= e(date('d M Y, h:i A', $backup['created_at'])) ?>
                                <div class="text-muted" style="font-size:.6875rem">
                                    <?= e(time_ago(date('Y-m-d H:i:s', $backup['created_at']))) ?>
                                </div>
                            </td>
                            <td class="num"><?= e(BackupService::humanBytes($backup['size'])) ?></td>
                            <td class="text-end nowrap">
                                <a class="btn btn-outline-secondary btn-sm"
                                   href="<?= e(url('/backup/download?file=' . urlencode($backup['file']))) ?>">
                                    <?= icon('download') ?> Download
                                </a>
                                <form method="post" class="d-inline m-0" action="<?= e(url('/backup/delete')) ?>"
                                      data-confirm="Delete this backup file permanently?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="file" value="<?= e($backup['file']) ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm btn-icon text-danger"
                                            title="Delete" data-bs-toggle="tooltip"><?= icon('trash') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="lrms-card mt-3">
    <div class="lrms-card-head"><h2>Scheduled backups (cron)</h2></div>
    <div class="lrms-card-body" style="font-size:.8438rem;color:var(--lrms-slate)">
        <p class="mb-2">
            Add this to cPanel &rarr; Cron Jobs to take a nightly backup at 2:00 AM:
        </p>
        <pre class="mb-2" style="background:var(--lrms-bg);border:1px solid var(--lrms-border);border-radius:8px;padding:12px;font-size:.75rem;overflow:auto">0 2 * * * /usr/local/bin/php <?= e(ROOT_PATH) ?>/cron/backup.php</pre>
        <p class="mb-0">
            The command uses <code>mysqldump</code> when the host allows <code>exec()</code>, and falls back to a
            pure-PHP dump otherwise &mdash; the resulting <code>.sql</code> file is importable either way.
        </p>
    </div>
</div>
