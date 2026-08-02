<?php
/**
 * @var \App\Core\Paginator $imports
 * @var array<int,array{total:int,unassigned:int}> $leadState  How much of each batch is
 *      still unassigned, so "assign this again" sits next to the number it would change.
 * @var list<array<string,mixed>> $agents
 * @var bool $canAssign
 */
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
                        <th>Leads</th>
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
                            <?php
                            $importId = (int) $import['id'];
                            $state = $leadState[$importId] ?? ['total' => 0, 'unassigned' => 0];
                            ?>
                            <td style="font-size:.8125rem">
                                <?php if ($state['total'] === 0): ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php elseif ($state['unassigned'] === 0): ?>
                                    <span style="color:var(--lrms-success)">all assigned</span>
                                <?php else: ?>
                                    <span style="color:var(--lrms-danger);font-weight:620">
                                        <?= e(number_format($state['unassigned'])) ?> unassigned
                                    </span>
                                    <div class="text-muted" style="font-size:.6875rem">
                                        of <?= e(number_format($state['total'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end nowrap">
                                <?php if ((int) $import['error_count'] > 0): ?>
                                    <a href="<?= e(url('/import/' . $importId . '/errors')) ?>"
                                       class="btn btn-outline-secondary btn-sm">
                                        <?= icon('download') ?>
                                        <?= e((string) (int) $import['error_count']) ?> error(s)
                                    </a>
                                <?php endif; ?>

                                <?php
                                /*
                                 * Assigning a batch again, later.
                                 *
                                 * It used to be possible only in the same breath as the
                                 * upload, which made it a one-shot decision: whoever
                                 * imported either picked the right agent at that moment or
                                 * the leads sat unassigned. A branch gets a second BC, or
                                 * somebody goes on leave, and the batch needs redealing.
                                 */
                                ?>
                                <?php if ($canAssign && $state['total'] > 0): ?>
                                    <details style="display:inline-block;text-align:left">
                                        <summary style="cursor:pointer;font-size:.75rem;color:var(--lrms-primary);display:inline-block">
                                            Assign&hellip;
                                        </summary>
                                        <form method="post"
                                              action="<?= e(url('/import/' . $importId . '/assign')) ?>"
                                              class="mt-2" data-no-double-submit
                                              style="min-width:230px">
                                            <?= csrf_field() ?>
                                            <select class="form-select form-select-sm mb-2" name="assign_mode"
                                                    aria-label="How to assign">
                                                <option value="distribute">Distribute equally among agents</option>
                                                <option value="agent">Give all to one agent</option>
                                            </select>
                                            <select class="form-select form-select-sm mb-2" name="agent_id"
                                                    aria-label="Agent">
                                                <option value="">Choose an agent&hellip;</option>
                                                <?php foreach ($agents as $agent): ?>
                                                    <option value="<?= e((string) $agent['id']) ?>">
                                                        <?= e($agent['name']) ?>
                                                        (<?= e((string) $agent['employee_code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" value="1"
                                                       name="only_unassigned"
                                                       id="only-unassigned-<?= e((string) $importId) ?>"
                                                       <?= $state['unassigned'] > 0 ? 'checked' : '' ?>>
                                                <label class="form-check-label" style="font-size:.75rem"
                                                       for="only-unassigned-<?= e((string) $importId) ?>">
                                                    Only the ones nobody has yet
                                                </label>
                                            </div>
                                            <p class="text-muted mb-2" style="font-size:.6875rem">
                                                Distributing balances what each agent already carries.
                                                Leaving the box ticked will not disturb a lead
                                                somebody is already working.
                                            </p>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                Assign
                                            </button>
                                        </form>
                                    </details>
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
