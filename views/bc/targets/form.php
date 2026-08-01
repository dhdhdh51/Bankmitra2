<?php
/**
 * @var array<string,mixed>|null   $target  null when creating
 * @var array<string,string>       $countFields
 * @var list<array<string,mixed>>  $agents
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

$isEdit = $target !== null;
$action = $isEdit ? url('/bc/targets/' . (int) $target['id'] . '/edit') : url('/bc/targets/create');

$value = static function (string $key, mixed $fallback = '') use ($old, $target): string {
    if (array_key_exists($key, $old)) {
        return e($old[$key]);
    }
    if ($target !== null && array_key_exists($key, $target)) {
        return e($target[$key]);
    }
    return e($fallback);
};
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/bc/targets')) ?>" class="text-muted">BC targets</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted"><?= $isEdit ? 'Edit' : 'New' ?></span>
        </nav>
        <h1><?= $isEdit ? 'Edit BC targets' : 'Set BC targets' ?></h1>
        <p>
            <?php if ($isEdit): ?>
                <?= e((string) $target['agent_name']) ?> &mdash;
                <?= e(date('F Y', (int) strtotime((string) $target['target_month']))) ?>
            <?php else: ?>
                One row per agent per month
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="row">
    <div class="col-lg-9 col-xl-8">
        <form method="post" action="<?= e($action) ?>" novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card">
                <div class="lrms-card-body">
                    <div class="alert alert-warning" role="alert" style="font-size:.8125rem">
                        <strong>These figures raise warnings.</strong>
                        The nightly check compares each agent's day against them and escalates a
                        sustained miss to the supervisor, then the service provider, then the
                        regional office. A target of <code>0</code> means "not assessed", not
                        "must do nothing" &mdash; leave a metric at zero if you are not measuring it.
                    </div>

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
                            </div>

                            <div class="col-md-5">
                                <label class="form-label" for="target_month">Month <span class="req">*</span></label>
                                <input type="month" class="form-control<?= has_error($errors, 'target_month') ?>"
                                       id="target_month" name="target_month"
                                       value="<?= $value('target_month', date('Y-m')) ?>" required>
                                <?= field_error($errors, 'target_month') ?>
                                <div class="form-text">Stored against the 1st of the month.</div>
                            </div>
                        <?php endif; ?>

                        <div class="col-12"><hr class="my-1"></div>

                        <?php foreach ($countFields as $field => $label): ?>
                            <div class="col-md-4">
                                <label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label>
                                <input type="number" class="form-control<?= has_error($errors, $field) ?>"
                                       id="<?= e($field) ?>" name="<?= e($field) ?>"
                                       value="<?= $value($field, '0') ?>" min="0" max="9999" step="1" inputmode="numeric">
                                <?= field_error($errors, $field) ?>
                                <?php if ($field === 'daily_visit_target'): ?>
                                    <div class="form-text">Per working day. Sundays are never assessed.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="col-md-4">
                            <label class="form-label" for="npa_recovery_target">NPA recovery target (&#8377;)</label>
                            <input type="number" class="form-control<?= has_error($errors, 'npa_recovery_target') ?>"
                                   id="npa_recovery_target" name="npa_recovery_target"
                                   value="<?= $value('npa_recovery_target', '0') ?>" min="0" step="0.01" inputmode="decimal">
                            <?= field_error($errors, 'npa_recovery_target') ?>
                            <div class="form-text">Monthly total, pro-rated across working days elapsed.</div>
                        </div>
                    </div>
                </div>

                <div class="lrms-card-foot d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Save targets' ?>
                    </button>
                    <a href="<?= e(url('/bc/targets')) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
