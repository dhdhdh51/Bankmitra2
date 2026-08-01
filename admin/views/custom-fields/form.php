<?php
/**
 * @var array<string,mixed>|null   $field
 * @var array<string,string>       $entities
 * @var array<string,string>       $types
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

$isEdit = $field !== null;
$action = $isEdit ? url('/custom-fields/' . (int) $field['id'] . '/edit') : url('/custom-fields/create');

$value = static function (string $key, mixed $fallback = '') use ($old, $field): string {
    if (array_key_exists($key, $old)) {
        return e($old[$key]);
    }
    if ($field !== null && array_key_exists($key, $field)) {
        return e($field[$key]);
    }
    return e($fallback);
};

$checked = static function (string $key, bool $fallback = false) use ($old, $field): bool {
    if ($old !== []) {
        return array_key_exists($key, $old) && in_array((string) $old[$key], ['1', 'on'], true);
    }
    if ($field !== null) {
        return (int) ($field[$key] ?? 0) === 1;
    }
    return $fallback;
};
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/custom-fields')) ?>" class="text-muted">Custom fields</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted"><?= $isEdit ? 'Edit' : 'New' ?></span>
        </nav>
        <h1><?= $isEdit ? 'Edit custom field' : 'Add a custom field' ?></h1>
        <p><?= $isEdit ? e((string) $field['label']) : 'It appears on the matching form as soon as you save' ?></p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-6">
        <form method="post" action="<?= e($action) ?>" novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card">
                <div class="lrms-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="entity">Applies to <span class="req">*</span></label>
                            <?php if ($isEdit): ?>
                                <?php
                                /*
                                 * Not editable. Moving a field to another entity would
                                 * leave its recorded answers attached to records of the
                                 * wrong type, which is unrecoverable without knowing
                                 * what the field used to mean.
                                 */
                                ?>
                                <input type="text" class="form-control" disabled
                                       value="<?= e($entities[(string) $field['entity']] ?? (string) $field['entity']) ?>">
                                <div class="form-text">Cannot be changed &mdash; existing answers point at it.</div>
                            <?php else: ?>
                                <?php $selectedEntity = $value('entity', 'customer'); ?>
                                <select class="form-select<?= has_error($errors, 'entity') ?>"
                                        id="entity" name="entity" required>
                                    <?php foreach ($entities as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $selectedEntity === $key ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error($errors, 'entity') ?>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="label">Label <span class="req">*</span></label>
                            <input type="text" class="form-control<?= has_error($errors, 'label') ?>"
                                   id="label" name="label" value="<?= $value('label') ?>"
                                   maxlength="120" required autofocus placeholder="e.g. PAN number">
                            <?= field_error($errors, 'label') ?>
                            <?php if ($isEdit): ?>
                                <div class="form-text">
                                    Stored key stays <code><?= e((string) $field['field_key']) ?></code>.
                                    Renaming the label is safe.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="field_type">Type <span class="req">*</span></label>
                            <?php $selectedType = $value('field_type', 'text'); ?>
                            <select class="form-select<?= has_error($errors, 'field_type') ?>"
                                    id="field_type" name="field_type" required>
                                <?php foreach ($types as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $selectedType === $key ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'field_type') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="sort_order">Display order</label>
                            <input type="number" class="form-control<?= has_error($errors, 'sort_order') ?>"
                                   id="sort_order" name="sort_order" value="<?= $value('sort_order', '0') ?>" step="1">
                            <?= field_error($errors, 'sort_order') ?>
                            <div class="form-text">Lower numbers appear first.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="options">Choices</label>
                            <input type="text" class="form-control" id="options" name="options"
                                   value="<?= $value('options') ?>" maxlength="500"
                                   placeholder="Owned, Rented, Ancestral">
                            <div class="form-text">
                                Comma separated. Only used when the type is
                                &ldquo;Choose from a list&rdquo; &mdash; it is discarded otherwise, so a
                                list left behind cannot quietly become a rule nothing enforces.
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="hint">Hint</label>
                            <input type="text" class="form-control" id="hint" name="hint"
                                   value="<?= $value('hint') ?>" maxlength="255">
                            <div class="form-text">Shown under the input on the form.</div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="is_required" name="is_required" <?= $checked('is_required') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_required">Required</label>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="show_in_report" name="show_in_report"
                                       <?= $checked('show_in_report') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="show_in_report">Print on the visit report</label>
                            </div>
                            <div class="form-text">
                                Off by default &mdash; an internal note should not start appearing
                                on a document handed to a borrower.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="status">Status <span class="req">*</span></label>
                            <?php $status = $value('status', 'active'); ?>
                            <select class="form-select" id="status" name="status" required>
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <div class="form-text">Inactive hides it from forms but keeps recorded answers.</div>
                        </div>
                    </div>
                </div>

                <div class="lrms-card-foot d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Add field' ?>
                    </button>
                    <a href="<?= e(url('/custom-fields')) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
