<?php
/** @var \App\Core\Paginator $imports */
?>

<div class="lrms-page-head">
    <div>
        <h1>Import history</h1>
        <p>Every lead file uploaded, with its outcome and error log</p>
    </div>
    <a href="<?= e(url('/import')) ?>" class="btn btn-primary btn-sm">
        <?= icon('upload') ?> New import
    </a>
</div>

<div class="lrms-card">
    <?php if ($imports->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'     => 'No imports yet',
            'message'     => 'Upload an Excel or CSV lead file to populate the system.',
            'iconName'    => 'upload',
            'actionLabel' => can('import.upload') ? 'Import leads' : null,
            'actionUrl'   => can('import.upload') ? url('/import') : null,
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th>Uploaded</th>
                        <th>File</th>
                        <th>By</th>
                        <th>Branch</th>
                        <th class="text-end">Rows</th>
                        <th class="text-end">New</th>
                        <th class="text-end">Updated</th>
                        <th class="text-end">Skipped</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports->items as $import): ?>
                        <tr>
                            <td class="nowrap" style="font-size:.8125rem">
                                <?= e(fmt_date((string) $import['created_at'], 'd M y')) ?>
                                <div class="text-muted" style="font-size:.6875rem">
                                    <?= e(fmt_date((string) $import['created_at'], 'h:i A')) ?>
                                </div>
                            </td>
                            <td style="font-size:.8125rem"><?= e($import['original_name']) ?></td>
                            <td style="font-size:.8125rem"><?= e($import['uploaded_by_name']) ?></td>
                            <td style="font-size:.8125rem"><?= nullable($import['branch_name']) ?></td>
                            <td class="num"><?= e(number_format((int) $import['total_rows'])) ?></td>
                            <td class="num" style="color:var(--lrms-success);font-weight:620">
                                <?= e(number_format((int) $import['inserted_count'])) ?>
                            </td>
                            <td class="num"><?= e(number_format((int) $import['updated_count'])) ?></td>
                            <td class="num" style="color:var(--lrms-danger)">
                                <?= e(number_format((int) $import['skipped_count'])) ?>
                            </td>
                            <td>
                                <span class="lrms-badge <?= (string) $import['status'] === 'completed' ? 'badge-visited' : ((string) $import['status'] === 'failed' ? 'badge-legal' : 'badge-pending') ?>">
                                    <?= e(ucfirst((string) $import['status'])) ?>
                                </span>
                                <?php if (!empty($import['failure_message'])): ?>
                                    <div class="text-danger" style="font-size:.6875rem">
                                        <?= e(mb_substr((string) $import['failure_message'], 0, 90)) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted" style="font-size:.75rem">
                                <?php
                                if ($import['started_at'] !== null && $import['finished_at'] !== null) {
                                    $seconds = strtotime((string) $import['finished_at']) - strtotime((string) $import['started_at']);
                                    echo e($seconds < 1 ? '<1s' : $seconds . 's');
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td class="text-end">
                                <?php if ((int) $import['error_count'] > 0): ?>
                                    <a href="<?= e(url('/import/' . (int) $import['id'] . '/errors')) ?>"
                                       class="btn btn-outline-secondary btn-sm">
                                        <?= icon('download') ?>
                                        <?= e((string) (int) $import['error_count']) ?> error(s)
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="lrms-card-foot">
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $imports, 'label' => 'imports']) ?>
        </div>
    <?php endif; ?>
</div>
