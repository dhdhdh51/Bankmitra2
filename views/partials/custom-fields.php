<?php
/**
 * Renders operator-defined fields as form inputs.
 *
 * One partial for every entity, driven entirely by the definition row, so adding a
 * field never means touching a view. The input name is the field key, which is what
 * CustomField::saveValues() reads back.
 *
 * @var list<array<string,mixed>>  $fields
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

use App\Models\CustomField;

if (($fields ?? []) === []) {
    return;
}

$fieldValue = static function (array $definition) use ($old): string {
    $key = (string) $definition['field_key'];

    // Old input wins after a failed validation, then the stored answer.
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }

    return (string) ($definition['value'] ?? '');
};
?>

<div class="row g-3">
    <?php foreach ($fields as $definition): ?>
        <?php
        $key = (string) $definition['field_key'];
        $type = (string) $definition['field_type'];
        $current = $fieldValue($definition);
        $wide = in_array($type, ['textarea'], true);
        ?>
        <div class="<?= $wide ? 'col-12' : 'col-md-6' ?>">
            <label class="form-label" for="cf_<?= e($key) ?>">
                <?= e((string) $definition['label']) ?>
                <?php if ((int) $definition['is_required'] === 1): ?><span class="req">*</span><?php endif; ?>
            </label>

            <?php if ($type === 'textarea'): ?>
                <textarea class="form-control<?= has_error($errors, $key) ?>" id="cf_<?= e($key) ?>"
                          name="<?= e($key) ?>" rows="3" maxlength="5000"
                          <?= (int) $definition['is_required'] === 1 ? 'required' : '' ?>><?= e($current) ?></textarea>

            <?php elseif ($type === 'select'): ?>
                <select class="form-select<?= has_error($errors, $key) ?>" id="cf_<?= e($key) ?>"
                        name="<?= e($key) ?>" <?= (int) $definition['is_required'] === 1 ? 'required' : '' ?>>
                    <option value="">&mdash;</option>
                    <?php foreach (CustomField::optionsOf($definition) as $option): ?>
                        <option value="<?= e($option) ?>" <?= $current === $option ? 'selected' : '' ?>>
                            <?= e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($type === 'toggle'): ?>
                <?php /* Hidden zero first so an unchecked box posts a real "no" rather
                          than nothing at all - absence would read as "not recorded". */ ?>
                <input type="hidden" name="<?= e($key) ?>" value="0">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1"
                           id="cf_<?= e($key) ?>" name="<?= e($key) ?>"
                           <?= $current === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cf_<?= e($key) ?>" style="font-size:.8125rem">
                        Yes
                    </label>
                </div>

            <?php else: ?>
                <input type="<?= $type === 'date' ? 'date' : ($type === 'number' || $type === 'money' ? 'number' : 'text') ?>"
                       class="form-control<?= has_error($errors, $key) ?>"
                       id="cf_<?= e($key) ?>" name="<?= e($key) ?>"
                       value="<?= e($current) ?>"
                       <?= $type === 'money' ? 'step="0.01" min="0" inputmode="decimal"' : '' ?>
                       <?= $type === 'number' ? 'step="1" inputmode="numeric"' : '' ?>
                       <?= $type === 'text' ? 'maxlength="255"' : '' ?>
                       <?= (int) $definition['is_required'] === 1 ? 'required' : '' ?>>
            <?php endif; ?>

            <?= field_error($errors, $key) ?>

            <?php if (($definition['hint'] ?? '') !== ''): ?>
                <div class="form-text"><?= e((string) $definition['hint']) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
