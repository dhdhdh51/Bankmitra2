<?php
/**
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $agents
 * @var list<array<string,mixed>> $recent
 * @var array<string,mixed>|null  $preview
 * @var array<string,bool>        $columns
 * @var bool                      $canUpload
 */
?>

<div class="lrms-page-head">
    <div>
        <h1>Excel Import</h1>
        <p>Upload a lead file, validate it, then import &mdash; duplicates update the existing account</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(url('/import/template')) ?>" class="btn btn-outline-secondary btn-sm">
            <?= icon('download') ?> Download template
        </a>
        <a href="<?= e(url('/import/history')) ?>" class="btn btn-outline-secondary btn-sm">
            <?= icon('logs') ?> Import history
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <?php if ($canUpload): ?>
            <div class="lrms-card mb-3">
                <div class="lrms-card-head">
                    <div>
                        <h2><?= icon('upload') ?> Upload lead file</h2>
                        <p>Accepted formats: .xlsx and .csv</p>
                    </div>
                </div>

                <form method="post" enctype="multipart/form-data" action="<?= e(url('/import')) ?>"
                      data-no-double-submit>
                    <?= csrf_field() ?>

                    <div class="lrms-card-body">
                        <div class="mb-3">
                            <label class="form-label" for="lead_file">Lead file <span class="req">*</span></label>
                            <input type="file" class="form-control" id="lead_file" name="lead_file"
                                   accept=".xlsx,.csv,.xls,text/csv" required>
                            <div class="form-text">
                                A title row above the column headers is fine &mdash; it is detected and skipped.
                            </div>
                        </div>

                        <div class="row g-3">
                            <?php if (count($branches) > 1): ?>
                                <div class="col-md-6">
                                    <label class="form-label" for="default_branch_id">Fallback branch</label>
                                    <select class="form-select" id="default_branch_id" name="default_branch_id">
                                        <option value="">None &mdash; skip unmatched rows</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?= e((string) $branch['id']) ?>">
                                                <?= e($branch['name']) ?> (<?= e($branch['branch_code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        Used only when a row's Branch column does not match an existing branch code or name.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="col-md-6">
                                <label class="form-label" for="default_agent_id">Bulk assign to agent</label>
                                <select class="form-select" id="default_agent_id" name="default_agent_id">
                                    <option value="">Do not assign</option>
                                    <?php
                                    /*
                                     * The sensible default for a branch with more than
                                     * one BC. Picking a single agent for a whole file
                                     * gives one person every lead in it, and the fix
                                     * afterwards is a manual reassignment nobody does.
                                     */
                                    ?>
                                    <option value="distribute">Distribute equally among the branch's agents</option>
                                    <?php foreach ($agents as $agent): ?>
                                        <option value="<?= e((string) $agent['id']) ?>">
                                            <?= e($agent['name']) ?> (<?= e($agent['employee_code']) ?>)
                                            <?= !empty($agent['branch_name']) ? ' · ' . e($agent['branch_name']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Leads already being worked by another agent are never reassigned by an import.
                                    Distributing balances what each agent is already carrying, so a second
                                    import does not pile onto whoever was first in the list.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lrms-card-foot d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">
                            <?= icon('upload') ?> Validate &amp; import
                        </button>
                        <button type="submit" class="btn btn-outline-secondary"
                                formaction="<?= e(url('/import/preview')) ?>">
                            <?= icon('eye') ?> Validate only (dry run)
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <?= icon('info') ?>
                <div>You have read access to import history but cannot upload new lead files.</div>
            </div>
        <?php endif; ?>

        <!-- ==================== Dry-run result ==================== -->
        <?php if (is_array($preview)): ?>
            <?php $result = $preview['result']; ?>
            <div class="lrms-card mb-3">
                <div class="lrms-card-head">
                    <div>
                        <h2><?= icon('eye') ?> Validation result</h2>
                        <p><?= e((string) $preview['filename']) ?> &mdash; nothing has been written yet</p>
                    </div>
                </div>

                <div class="lrms-card-body">
                    <?php if ($result['missing_required'] !== []): ?>
                        <div class="alert alert-danger">
                            <?= icon('alert') ?>
                            <div>
                                <strong>Required column(s) not found:</strong>
                                <?= e(implode(', ', array_map(
                                    static fn (string $c): string => ucwords(str_replace('_', ' ', $c)),
                                    $result['missing_required']
                                ))) ?>.
                                <div class="mt-1" style="font-size:.8125rem">
                                    Pick the right column below and import again &mdash; the file does not
                                    need to be reformatted.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    // ==================== Column mapping ====================
                    // Detection proposes; the operator confirms. Money columns are
                    // never guessed from their contents, so this is the only place
                    // a wrong outstanding-amount column can be caught - before a
                    // single row is written.
                    $fields = \App\Core\ColumnDetector::fields();
                    $detected = $result['detection'] ?? [];
                    $columnSamples = $result['samples_by_column'] ?? [];
                    ?>
                    <form method="post" action="<?= e(url('/import')) ?>" data-no-double-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="use_previewed_file" value="1">
                        <?php if (($preview['result']['sheet'] ?? '') !== ''): ?>
                            <input type="hidden" name="__sheet" value="<?= e((string) $result['sheet']) ?>">
                        <?php endif; ?>

                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <h3 style="font-size:.9375rem;margin:0">Column mapping</h3>
                            <span class="text-muted" style="font-size:.75rem">
                                <?php if (($result['sheet'] ?? '') !== ''): ?>
                                    Sheet <code><?= e((string) $result['sheet']) ?></code> &middot;
                                <?php endif; ?>
                                header on row <?= e((string) (((int) ($result['header_row'] ?? 0)) + 1)) ?>
                            </span>
                        </div>

                        <div class="lrms-table-wrap mb-3">
                            <table class="lrms-table">
                                <thead>
                                    <tr>
                                        <th style="width:34%">Field</th>
                                        <th style="width:32%">Column in your file</th>
                                        <th>Example values</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($fields as $field => $meta): ?>
                                    <?php
                                    $hit = $detected[$field] ?? null;
                                    $chosenIndex = $hit === null ? null : (int) $hit['index'];
                                    $confidence = $hit === null ? 0 : (int) $hit['confidence'];
                                    $source = $hit === null ? '' : (string) $hit['source'];
                                    $isMissing = in_array($field, $result['missing_required'], true);
                                    ?>
                                    <tr<?= $isMissing ? ' class="table-danger"' : '' ?>>
                                        <td>
                                            <strong><?= e($meta['label']) ?></strong>
                                            <?php if ($meta['required']): ?>
                                                <span class="badge bg-danger-subtle text-danger-emphasis">Required</span>
                                            <?php endif; ?>
                                            <?php if ($source === 'values'): ?>
                                                <div class="text-muted" style="font-size:.6875rem">
                                                    matched on the shape of the data, not the heading
                                                    &mdash; please confirm
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm"
                                                    name="column_map[<?= e($field) ?>]">
                                                <option value="-1"<?= $chosenIndex === null ? ' selected' : '' ?>>
                                                    &mdash; not in this file &mdash;
                                                </option>
                                                <?php foreach ($result['headings'] as $index => $heading): ?>
                                                    <option value="<?= e((string) $index) ?>"
                                                        <?= $chosenIndex === $index ? ' selected' : '' ?>>
                                                        <?= e(trim($heading) === ''
                                                            ? 'Column ' . ($index + 1)
                                                            : $heading) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($chosenIndex !== null && $confidence < 80): ?>
                                                <div class="text-warning-emphasis" style="font-size:.6875rem">
                                                    <?= e((string) $confidence) ?>% confident
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted" style="font-size:.75rem">
                                            <?php if ($chosenIndex !== null && ($columnSamples[$chosenIndex] ?? []) !== []): ?>
                                                <?= e(implode(', ', array_map(
                                                    static fn (string $v): string => mb_substr($v, 0, 24),
                                                    $columnSamples[$chosenIndex]
                                                ))) ?>
                                            <?php else: ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (($result['branches_to_create'] ?? []) !== []): ?>
                            <div class="alert alert-info">
                                <?= icon('alert') ?>
                                <div>
                                    <strong>These branches will be created from the file:</strong>
                                    <code><?= e(implode(', ', array_slice($result['branches_to_create'], 0, 12))) ?></code>
                                    <div class="mt-1" style="font-size:.8125rem">
                                        Check the spelling &mdash; each distinct spelling becomes its own branch.
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($canUpload): ?>
                            <button type="submit" class="btn btn-primary">
                                <?= icon('check') ?> Import with this mapping
                            </button>
                        <?php endif; ?>
                    </form>

                    <?php if ($result['missing_required'] === []): ?>
                        <div class="lrms-stats" style="margin-bottom:12px">
                            <div class="lrms-stat lrms-stat-accent">
                                <div class="lrms-stat-label">Rows</div>
                                <div class="lrms-stat-value"><?= e(number_format((int) $result['total'])) ?></div>
                            </div>
                            <div class="lrms-stat lrms-stat-accent is-success">
                                <div class="lrms-stat-label">New</div>
                                <div class="lrms-stat-value"><?= e(number_format((int) $result['new_count'])) ?></div>
                            </div>
                            <div class="lrms-stat lrms-stat-accent is-warning">
                                <div class="lrms-stat-label">Updates</div>
                                <div class="lrms-stat-value"><?= e(number_format((int) $result['update_count'])) ?></div>
                            </div>
                            <div class="lrms-stat lrms-stat-accent is-danger">
                                <div class="lrms-stat-label">Issues</div>
                                <div class="lrms-stat-value"><?= e(number_format(count($result['issues']))) ?></div>
                            </div>
                        </div>

                        <?php if ($result['unmapped'] !== []): ?>
                            <p class="text-muted mb-2" style="font-size:.8125rem">
                                Ignored columns:
                                <code><?= e(implode(', ', array_slice($result['unmapped'], 0, 12))) ?></code>
                            </p>
                        <?php endif; ?>

                        <?php if ($result['sample'] !== []): ?>
                            <div class="lrms-table-wrap mt-2">
                                <table class="lrms-table">
                                    <thead>
                                        <tr>
                                            <th>Row</th><th>Account</th><th>Customer</th>
                                            <th>Village</th><th class="text-end">Outstanding</th><th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($result['sample'] as $row): ?>
                                            <tr>
                                                <td class="text-muted" style="font-size:.75rem"><?= e($row['row']) ?></td>
                                                <td class="font-mono" style="font-size:.75rem"><?= e($row['account']) ?></td>
                                                <td style="font-size:.8125rem"><?= e($row['name']) ?></td>
                                                <td style="font-size:.8125rem"><?= e($row['village']) ?></td>
                                                <td class="num"><?= e($row['outstanding']) ?></td>
                                                <td>
                                                    <span class="lrms-badge <?= $row['action'] === 'New' ? 'badge-visited' : 'badge-promise' ?>">
                                                        <?= e($row['action']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted mt-2 mb-0" style="font-size:.75rem">
                                Showing the first <?= e((string) count($result['sample'])) ?> row(s).
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($result['issues'] !== []): ?>
                        <div class="mt-3">
                            <h3 style="font-size:.875rem">Issues</h3>
                            <div class="lrms-scroll-y">
                                <table class="lrms-table">
                                    <thead><tr><th>Row</th><th>Account</th><th>Reason</th></tr></thead>
                                    <tbody>
                                        <?php foreach (array_slice($result['issues'], 0, 100) as $issue): ?>
                                            <tr>
                                                <td class="text-muted" style="font-size:.75rem"><?= e((string) $issue['row']) ?></td>
                                                <td class="font-mono" style="font-size:.75rem"><?= e($issue['account']) ?></td>
                                                <td style="font-size:.8125rem"><?= e($issue['message']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (count($result['issues']) > 100): ?>
                                <p class="text-muted mt-2 mb-0" style="font-size:.75rem">
                                    and <?= e((string) (count($result['issues']) - 100)) ?> more.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== Column guide ==================== -->
    <div class="col-xl-5">
        <div class="lrms-card mb-3">
            <div class="lrms-card-head">
                <div>
                    <h2><?= icon('info') ?> Expected columns</h2>
                    <p>Header names are matched loosely &mdash; common variations are recognised</p>
                </div>
            </div>
            <div class="lrms-table-wrap">
                <table class="lrms-table">
                    <thead><tr><th>Column</th><th>Required</th></tr></thead>
                    <tbody>
                        <?php foreach ($columns as $column => $required): ?>
                            <tr>
                                <td style="font-size:.8438rem"><?= e($column) ?></td>
                                <td>
                                    <?php if ($required): ?>
                                        <span class="lrms-badge badge-legal">Required</span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:.75rem">Optional</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="lrms-card-foot" style="font-size:.8125rem;color:var(--lrms-slate)">
                <strong>How duplicates are handled:</strong> rows are matched on
                <em>Loan Account Number</em>. An existing account is updated in place
                (amounts, NPA date, contact details); a new one is inserted. Nothing is duplicated.
            </div>
        </div>

        <div class="lrms-card">
            <div class="lrms-card-head"><h2>Notes</h2></div>
            <div class="lrms-card-body" style="font-size:.8438rem;color:var(--lrms-slate)">
                <ul class="mb-0 ps-3">
                    <li class="mb-2">
                        Dates are read day-first (<code>31/03/2024</code> = 31 March 2024) and
                        Excel serial dates are converted automatically.
                    </li>
                    <li class="mb-2">
                        Amounts accept Indian grouping and symbols: <code>1,25,000.50</code>,
                        <code>Rs. 1200</code>, <code>(500)</code> for negatives.
                    </li>
                    <li class="mb-2">
                        Mobile and Aadhaar are encrypted on write. Only a masked form is
                        shown in lists.
                    </li>
                    <li class="mb-2">
                        Branch is matched on branch <strong>code</strong> or <strong>name</strong>.
                    </li>
                    <li>
                        The whole file imports in one transaction &mdash; if it fails, nothing is written.
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ==================== Recent imports ==================== -->
<?php if ($recent !== []): ?>
    <div class="lrms-card mt-3">
        <div class="lrms-card-head">
            <h2>Recent imports</h2>
            <a href="<?= e(url('/import/history')) ?>" class="btn btn-outline-secondary btn-sm">View all</a>
        </div>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th>When</th><th>File</th><th>By</th>
                        <th class="text-end">Rows</th><th class="text-end">New</th>
                        <th class="text-end">Updated</th><th class="text-end">Skipped</th>
                        <th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $import): ?>
                        <tr>
                            <td class="text-muted nowrap" style="font-size:.75rem">
                                <?= e(time_ago((string) $import['created_at'])) ?>
                            </td>
                            <td style="font-size:.8125rem"><?= e($import['original_name']) ?></td>
                            <td style="font-size:.8125rem"><?= e($import['uploaded_by_name']) ?></td>
                            <td class="num"><?= e(number_format((int) $import['total_rows'])) ?></td>
                            <td class="num" style="color:var(--lrms-success)">
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
                            </td>
                            <td class="text-end">
                                <?php if ((int) $import['error_count'] > 0): ?>
                                    <a href="<?= e(url('/import/' . (int) $import['id'] . '/errors')) ?>"
                                       class="btn btn-ghost btn-sm btn-icon" title="Download error log"
                                       data-bs-toggle="tooltip"><?= icon('download') ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
