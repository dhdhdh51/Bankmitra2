<?php
/**
 * @var list<array<string,mixed>> $fields
 * @var array<string,string>      $entities
 * @var array<string,string>      $types
 */
?>

<div class="lrms-page-head">
    <div>
        <h1>Custom fields</h1>
        <p>Extra fields on borrowers, loan accounts and visit reports &mdash; added without a code change</p>
    </div>
    <a href="<?= e(url('/custom-fields/create')) ?>" class="btn btn-primary btn-sm">
        <?= icon('plus') ?> Add a field
    </a>
</div>

<div class="alert alert-info mb-3">
    <?= icon('info') ?>
    <div>
        <strong>Not for loan figures.</strong>
        Anything the Excel import owns belongs in a real column the import knows about &mdash;
        a custom field holding an outstanding balance would be a second answer to a question
        this system already answers, and the import would not know to update it. Use these for
        details the bank tracks that the import does not carry, like a PAN or a guarantor name.
    </div>
</div>

<div class="lrms-card">
    <?php if ($fields === []): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'     => 'No custom fields yet',
            'message'     => 'Add one and it appears on the matching form immediately, with no release needed.',
            'iconName'    => 'settings',
            'actionLabel' => 'Add a field',
            'actionUrl'   => url('/custom-fields/create'),
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Applies to</th>
                        <th>Type</th>
                        <th>Key</th>
                        <th class="text-end">Answers</th>
                        <th>On report</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fields as $field): ?>
                        <tr>
                            <td style="font-weight:550">
                                <?= e((string) $field['label']) ?>
                                <?php if ((int) $field['is_required'] === 1): ?>
                                    <span class="req" title="Required">*</span>
                                <?php endif; ?>
                                <?php if (($field['hint'] ?? '') !== ''): ?>
                                    <div class="text-muted" style="font-size:.75rem"><?= e((string) $field['hint']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.8125rem">
                                <?= e($entities[(string) $field['entity']] ?? (string) $field['entity']) ?>
                            </td>
                            <td style="font-size:.8125rem">
                                <?= e($types[(string) $field['field_type']] ?? (string) $field['field_type']) ?>
                                <?php if ((string) $field['field_type'] === 'select' && ($field['options'] ?? '') !== ''): ?>
                                    <div class="text-muted" style="font-size:.75rem"><?= e((string) $field['options']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="font-mono" style="font-size:.75rem"><?= e((string) $field['field_key']) ?></td>
                            <td class="num"><?= e(number_format((int) $field['answer_count'])) ?></td>
                            <td><?= (int) $field['show_in_report'] === 1 ? 'Yes' : '<span class="text-muted">No</span>' ?></td>
                            <td>
                                <span class="lrms-badge <?= (string) $field['status'] === 'active' ? 'badge-visited' : 'badge-closed' ?>">
                                    <?= e(ucfirst((string) $field['status'])) ?>
                                </span>
                            </td>
                            <td class="text-end nowrap">
                                <a href="<?= e(url('/custom-fields/' . (int) $field['id'] . '/edit')) ?>"
                                   class="btn btn-ghost btn-sm btn-icon" title="Edit"
                                   data-bs-toggle="tooltip"><?= icon('edit') ?></a>

                                <?php
                                /*
                                 * The confirmation names the number of answers, because
                                 * deleting a definition destroys them. Retiring the field
                                 * instead keeps them readable, and that is nearly always
                                 * what somebody actually wants.
                                 */
                                $answers = (int) $field['answer_count'];
                                $warning = $answers === 0
                                    ? sprintf('Delete "%s"? Nothing has been recorded against it yet.', (string) $field['label'])
                                    : sprintf(
                                        'Delete "%s"? This also destroys %d recorded answer(s) and cannot be undone. To stop collecting it without losing what was recorded, set it to Inactive instead.',
                                        (string) $field['label'],
                                        $answers
                                    );
                                ?>
                                <form method="post" class="d-inline m-0"
                                      action="<?= e(url('/custom-fields/' . (int) $field['id'] . '/delete')) ?>"
                                      data-confirm="<?= e($warning) ?>">
                                    <?= csrf_field() ?>
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
