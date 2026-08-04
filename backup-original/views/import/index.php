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
                        <?php
                        // The branch and agent the operator chose on the upload form -
                        // without these the confirm POST has no context and every row
                        // is skipped because no branch resolves.
                        $previewedBranch = $preview['default_branch_id'] ?? null;
                        $previewedAgent  = $preview['default_agent_id'] ?? null;
                        ?>
                        <?php if ($previewedBranch !== null && $previewedBranch !== ''): ?>
                            <input type="hidden" name="default_branch_id" value="<?= e((string) $previewedBranch) ?>">
                        <?php endif; ?>
                        <?php if ($previewedAgent !== null && $previewedAgent !== ''): ?>
                            <input type="hidden" name="default_agent_id" value="<?= e((string) $previewedAgent) ?>">
                        <?php endif; ?>
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

                        <p class="text-muted mb-2" style="font-size:.8125rem">
                            Each column in your file is shown below. Choose which field it should go into.
                            Auto-detected mappings are pre-selected &mdash; change any that are wrong.
                        </p>

                        <?php
                        /*
                         * Column-first mapping: one row per file column, with a
                         * dropdown of fields to assign it to. This is the reverse of
                         * the original field-first view, and is more intuitive for
                         * operators who think "this COLUMN means X" rather than "field
                         * X lives in column Y".
                         *
                         * The form still submits column_map[field] = column_index
                         * exactly as before, so columnOverrides() and everything
                         * downstream does not change at all.
                         */
                        $fields = \App\Core\ColumnDetector::fields();
                        $detected = $result['detection'] ?? [];
                        $columnSamples = $result['samples_by_column'] ?? [];

                        // Build reverse map: column index -> field (from detection)
                        $reverseMap = [];
                        foreach ($detected as $field => $hit) {
                            $reverseMap[(int) $hit['index']] = [
                                'field'      => $field,
                                'confidence' => (int) $hit['confidence'],
                                'source'     => (string) $hit['source'],
                            ];
                        }
                        ?>

                        <div class="lrms-table-wrap mb-3">
                            <table class="lrms-table">
                                <thead>
                                    <tr>
                                        <th style="width:20%">Column in file</th>
                                        <th style="width:32%">Assign to field</th>
                                        <th>Example values</th>
                                        <th style="width:10%">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($result['headings'] as $colIndex => $heading): ?>
                                    <?php
                                    if (trim($heading) === '') continue;
                                    $assignedField = $reverseMap[$colIndex]['field'] ?? null;
                                    $confidence = $reverseMap[$colIndex]['confidence'] ?? 0;
                                    $source = $reverseMap[$colIndex]['source'] ?? '';
                                    $samples = $columnSamples[$colIndex] ?? [];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong class="font-mono" style="font-size:.8125rem"><?= e($heading) ?></strong>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm lrms-column-assign"
                                                    data-col-index="<?= e((string) $colIndex) ?>">
                                                <option value="">— skip this column —</option>
                                                <?php foreach ($fields as $fieldKey => $meta): ?>
                                                    <option value="<?= e($fieldKey) ?>"
                                                        <?= $assignedField === $fieldKey ? ' selected' : '' ?>>
                                                        <?= e($meta['label']) ?>
                                                        <?= $meta['required'] ? ' *' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <option value="__custom" <?= ($assignedField === null && trim($heading) !== '') ? '' : '' ?>>
                                                    ➕ Save as custom field
                                                </option>
                                            </select>
                                        </td>
                                        <td class="text-muted" style="font-size:.75rem">
                                            <?php if ($samples !== []): ?>
                                                <?= e(implode(', ', array_map(
                                                    static fn (string $v): string => mb_substr($v, 0, 24),
                                                    array_slice($samples, 0, 4)
                                                ))) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($source === 'learned'): ?>
                                                <span class="badge bg-success-subtle text-success-emphasis" style="font-size:.6875rem">Remembered</span>
                                            <?php elseif ($source === 'values'): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis" style="font-size:.6875rem">Guessed</span>
                                            <?php elseif ($assignedField !== null): ?>
                                                <span class="badge bg-primary-subtle text-primary-emphasis" style="font-size:.6875rem">Auto</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis" style="font-size:.6875rem">Unmapped</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($result['missing_required'] !== []): ?>
                            <div class="alert alert-danger mb-3">
                                <?= icon('alert') ?>
                                <strong>Required field(s) not assigned:</strong>
                                <?= e(implode(', ', array_map(
                                    static fn (string $c): string => ucwords(str_replace('_', ' ', $c)),
                                    $result['missing_required']
                                ))) ?>.
                                Pick the right column above.
                            </div>
                        <?php endif; ?>

                        <!--
                            Hidden inputs that carry the actual column_map[field]=index
                            values. Built by JS from the dropdowns above on submit, so
                            the server receives the same shape it always has.
                        -->
                        <div id="columnMapHidden"></div>

                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var form = document.querySelector('form[data-no-double-submit]');
                            if (!form) return;
                            // On form submit, build hidden inputs from the column-first dropdowns
                            form.addEventListener('submit', function() {
                                var container = document.getElementById('columnMapHidden');
                                container.innerHTML = '';
                                var selects = form.querySelectorAll('.lrms-column-assign');
                                // field -> column index (last one wins if duplicate)
                                var map = {};
                                selects.forEach(function(sel) {
                                    var colIndex = sel.getAttribute('data-col-index');
                                    var field = sel.value;
                                    if (field && field !== '__custom' && field !== '') {
                                        map[field] = colIndex;
                                    }
                                });
                                // Create hidden inputs as column_map[field] = index
                                for (var field in map) {
                                    var input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = 'column_map[' + field + ']';
                                    input.value = map[field];
                                    container.appendChild(input);
                                }
                            });
                        });
                        </script>

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
                                <?php
                                /*
                                 * Nothing here is actually dropped any more. A column that
                                 * does not match the fixed vocabulary becomes its own custom
                                 * field on the loan account the moment the real import runs -
                                 * this dry run only shows what those columns are, since it
                                 * writes nothing itself.
                                 */
                                ?>
                                Columns not in the fixed list &mdash; each becomes its own field on
                                the loan account when you import (see <a href="<?= e(url('/custom-fields')) ?>">Custom Fields</a>):
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

        <?php if ($taughtAliases !== []): ?>
            <div class="lrms-card mb-3">
                <div class="lrms-card-head">
                    <div>
                        <h2><?= icon('check') ?> Taught column mappings</h2>
                        <p>
                            Headings the confirm screen has already resolved &mdash; the next file
                            using any of these maps automatically
                        </p>
                    </div>
                </div>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead><tr><th>Column heading</th><th>Maps to</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($taughtAliases as $alias): ?>
                                <tr>
                                    <td class="font-mono" style="font-size:.75rem">
                                        <?= e((string) $alias['original_heading']) ?>
                                    </td>
                                    <td style="font-size:.8125rem">
                                        <?= e($fields[$alias['field']]['label'] ?? (string) $alias['field']) ?>
                                    </td>
                                    <td>
                                        <form method="post"
                                              action="<?= e(url('/import/aliases/' . $alias['id'] . '/delete')) ?>"
                                              onsubmit="return confirm('Forget this mapping? A future file with this heading will go back to being detected or asked about.');">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                                    title="Forget this mapping">
                                                <?= icon('trash') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

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
