<?php
/**
 * @var array<string,mixed>|null   $entry  null when creating
 * @var array<string,string>       $schemeFields
 * @var list<array<string,mixed>>  $agents
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

$isEdit = $entry !== null;
$action = $isEdit ? url('/bc/sss/' . (int) $entry['id'] . '/edit') : url('/bc/sss/create');

$value = static function (string $key, mixed $fallback = '') use ($old, $entry): string {
    if (array_key_exists($key, $old)) {
        return e($old[$key]);
    }
    if ($entry !== null && array_key_exists($key, $entry)) {
        return e($entry[$key]);
    }
    return e($fallback);
};
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/bc/sss')) ?>" class="text-muted">SSS enrolment</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted"><?= $isEdit ? 'Edit' : 'New' ?></span>
        </nav>
        <h1><?= $isEdit ? 'Edit SSS enrolment' : 'Record SSS enrolment' ?></h1>
        <p>
            <?php if ($isEdit): ?>
                <?= e((string) $entry['agent_name']) ?> &mdash; <?= fmt_date($entry['enrollment_date']) ?>
            <?php else: ?>
                One entry per agent per day &mdash; correct it rather than adding a second
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-6">
        <form method="post" action="<?= e($action) ?>" novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card">
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <?php if (!$isEdit): ?>
                            <div class="col-md-7">
                                <label class="form-label" for="agent_id">Agent <span class="req">*</span></label>
                                <?php $selectedAgent = $value('agent_id'); ?>
                                <select class="form-select<?= has_error($errors, 'agent_id') ?>"
                                        id="agent_id" name="agent_id" required autofocus>
                                    <option value="">Select an agent&hellip;</option>
                                    <?php foreach ($agents as $agent): ?>
                                        <option value="<?= (int) $agent['id'] ?>"
                                            <?= $selectedAgent === (string) $agent['id'] ? 'selected' : '' ?>>
                                            <?= e((string) $agent['name']) ?>
                                            (<?= e((string) $agent['employee_code']) ?><?= $agent['branch_name'] === null ? '' : ' · ' . e((string) $agent['branch_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error($errors, 'agent_id') ?>
                                <div class="form-text">The branch is taken from the agent record.</div>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label" for="enrollment_date">Date <span class="req">*</span></label>
                                <input type="date" class="form-control<?= has_error($errors, 'enrollment_date') ?>"
                                       id="enrollment_date" name="enrollment_date"
                                       value="<?= $value('enrollment_date', date('Y-m-d')) ?>"
                                       max="<?= e(date('Y-m-d')) ?>" required>
                                <?= field_error($errors, 'enrollment_date') ?>
                            </div>
                        <?php endif; ?>

                        <div class="col-12"><hr class="my-1"></div>

                        <?php foreach ($schemeFields as $field => $label): ?>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label>
                                <input type="number" class="form-control<?= has_error($errors, $field) ?>"
                                       id="<?= e($field) ?>" name="<?= e($field) ?>"
                                       value="<?= $value($field, '0') ?>" min="0" max="999" step="1" inputmode="numeric">
                                <?= field_error($errors, $field) ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="col-12">
                            <label class="form-label" for="remarks">Remarks</label>
                            <textarea class="form-control<?= has_error($errors, 'remarks') ?>"
                                      id="remarks" name="remarks" rows="2" maxlength="500"><?= $value('remarks') ?></textarea>
                            <?= field_error($errors, 'remarks') ?>
                        </div>
                    </div>
                </div>

                <div class="lrms-card-foot d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Save entry' ?>
                    </button>
                    <a href="<?= e(url('/bc/sss')) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
