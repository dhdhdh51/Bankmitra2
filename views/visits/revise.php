<?php
/**
 * Correct a submitted field visit report.
 *
 * Only the fields a reviewer can be confident about from the report itself are
 * editable here - a misheard name, a transposed digit, a village spelled wrong. The
 * tick boxes, the recommendation and the remarks are deliberately absent: those are
 * the agent's assertions about what they saw, and a reviewer overwriting them turns
 * the agent's report into the reviewer's.
 *
 * @var array<string,mixed>        $report
 * @var list<array<string,mixed>>  $revisions
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

use App\Models\VisitReport;

$id = (int) $report['id'];
$action = url('/visits/' . $id . '/revise');

$value = static function (string $key) use ($old, $report): string {
    if (array_key_exists($key, $old)) {
        return e($old[$key]);
    }
    return e($report[$key] ?? '');
};
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/visits')) ?>" class="text-muted">Visit Reports</a>
            <span class="text-muted mx-1">/</span>
            <a href="<?= e(url('/visits/' . $id)) ?>" class="text-muted">#<?= e((string) $id) ?></a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted">Correct</span>
        </nav>
        <h1>Correct visit report</h1>
        <p>
            <?= e((string) $report['loan_account_number']) ?>
            &middot; filed by <?= e((string) $report['agent_name']) ?>
            on <?= e(fmt_date((string) $report['visit_date'])) ?>
        </p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-7">
        <div class="alert alert-warning mb-3" style="font-size:.8125rem">
            <?= icon('alert') ?>
            <div>
                <strong>Nothing here is overwritten silently.</strong>
                The previous value of every field you change is kept, together with your
                name and the reason you give. The printed report states how many times it
                has been corrected, so a clean-looking document cannot hide that it
                differs from what the agent filed.
            </div>
        </div>

        <form method="post" action="<?= e($action) ?>" novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card mb-3">
                <div class="lrms-card-head"><h2>Correctable fields</h2></div>
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <?php foreach (VisitReport::CORRECTABLE as $column => $label): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="<?= e($column) ?>"><?= e($label) ?></label>
                                <input type="text" class="form-control<?= has_error($errors, $column) ?>"
                                       id="<?= e($column) ?>" name="<?= e($column) ?>"
                                       value="<?= $value($column) ?>" maxlength="500">
                                <?= field_error($errors, $column) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="lrms-card mb-3">
                <div class="lrms-card-body">
                    <label class="form-label" for="reason">Reason for the correction <span class="req">*</span></label>
                    <textarea class="form-control<?= has_error($errors, 'reason') ?>"
                              id="reason" name="reason" rows="2" maxlength="500"
                              required><?= e((string) ($old['reason'] ?? '')) ?></textarea>
                    <?= field_error($errors, 'reason') ?>
                    <div class="form-text">Stored with the change. "Name misspelt on the original" is enough.</div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= icon('check') ?> Save correction</button>
                <a href="<?= e(url('/visits/' . $id)) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>

        <?php if ($revisions !== []): ?>
            <div class="lrms-card mt-3">
                <div class="lrms-card-head"><h2>Previous corrections</h2></div>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>When</th>
                                <th>By</th>
                                <th>Changed</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($revisions as $revision): ?>
                                <tr>
                                    <td class="num"><?= (int) $revision['revision_no'] ?></td>
                                    <td style="font-size:.8125rem"><?= fmt_datetime($revision['changed_at']) ?></td>
                                    <td style="font-size:.8125rem"><?= nullable($revision['changed_by_name']) ?></td>
                                    <td style="font-size:.75rem">
                                        <?php foreach ($revision['changes_decoded'] as $field => $change): ?>
                                            <div>
                                                <strong><?= e(VisitReport::CORRECTABLE[$field] ?? $field) ?>:</strong>
                                                <span class="text-muted"><?= e((string) ($change['from'] ?? '')) ?></span>
                                                &rarr;
                                                <?= e((string) ($change['to'] ?? '')) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td style="font-size:.75rem"><?= nullable($revision['reason']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
