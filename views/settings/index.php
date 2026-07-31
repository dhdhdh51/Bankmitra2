<?php
/**
 * DB-driven settings, grouped into tabs.
 *
 * @var array<string,list<array<string,mixed>>>            $grouped
 * @var array<string,string>                               $groupLabels
 * @var list<array{key:string,label:string,group:string}>  $missing
 * @var bool                                               $canUpdate
 * @var array<string,bool>                                 $status
 */

$missingKeys = [];
foreach ($missing as $item) {
    $missingKeys[$item['key']] = true;
}

// Render tabs in the declared order, then anything unexpected at the end.
$orderedGroups = [];
foreach (array_keys($groupLabels) as $group) {
    if (isset($grouped[$group])) {
        $orderedGroups[$group] = $grouped[$group];
    }
}
foreach ($grouped as $group => $rows) {
    if (!isset($orderedGroups[$group])) {
        $orderedGroups[$group] = $rows;
    }
}

$activeGroup = array_key_first($orderedGroups);
?>

<div class="lrms-page-head">
    <div>
        <h1>Settings</h1>
        <p>Stored in the database &mdash; changes apply immediately, with no file edit or re-upload</p>
    </div>
</div>

<?php if ($missing !== []): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <div>
            <strong>Missing configuration.</strong>
            <?= e((string) count($missing)) ?> required setting<?= count($missing) === 1 ? '' : 's' ?>
            still blank. The affected fields are marked below.
        </div>
    </div>
<?php endif; ?>

<!-- Environment status -->
<div class="lrms-card mb-3">
    <div class="lrms-card-head">
        <div>
            <h2>Integration status</h2>
            <p>Detected from the current configuration and PHP environment</p>
        </div>
    </div>
    <div class="lrms-card-body">
        <div class="lrms-check-grid">
            <?php
            $statusItems = [
                'sms'  => ['SMS gateway', 'Needed for OTP password resets'],
                'smtp' => ['SMTP email', 'Optional - OTP works without it'],
                'push' => ['Firebase push', 'Optional - in-app notifications always work'],
                'zip'  => ['ZipArchive (PHP)', 'Needed for .xlsx; CSV is used as a fallback'],
                'gd'   => ['GD / Imagick', 'Needed to validate uploaded image dimensions'],
                'curl' => ['cURL', 'Used for SMS and push; streams are the fallback'],
                'exec' => ['exec()', 'Enables mysqldump; pure-PHP dump is the fallback'],
            ];
            foreach ($statusItems as $key => [$label, $hint]):
                $ok = (bool) ($status[$key] ?? false);
            ?>
                <div class="lrms-check-tile" style="cursor:default">
                    <span style="line-height:0;color:<?= $ok ? 'var(--lrms-success)' : 'var(--lrms-muted)' ?>">
                        <?= icon($ok ? 'check-circle' : 'x') ?>
                    </span>
                    <span>
                        <?= e($label) ?>
                        <span class="d-block text-muted" style="font-size:.6875rem"><?= e($hint) ?></span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<form method="post" action="<?= e(url('/settings')) ?>" data-no-double-submit>
    <?= csrf_field() ?>

    <div class="lrms-card">
        <div class="lrms-card-head" style="padding-bottom:0;border-bottom:0">
            <ul class="nav nav-tabs border-0" role="tablist" style="gap:4px">
                <?php foreach ($orderedGroups as $group => $rows): ?>
                    <?php
                    $groupHasMissing = false;
                    foreach ($rows as $row) {
                        if (isset($missingKeys[(string) $row['setting_key']])) {
                            $groupHasMissing = true;
                            break;
                        }
                    }
                    ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?= $group === $activeGroup ? ' active' : '' ?>"
                                data-bs-toggle="tab" data-bs-target="#tab-<?= e($group) ?>"
                                type="button" role="tab"
                                style="font-size:.8438rem;font-weight:600;border-radius:8px 8px 0 0">
                            <?= e($groupLabels[$group] ?? ucfirst($group)) ?>
                            <?php if ($groupHasMissing): ?>
                                <span class="badge rounded-pill bg-warning text-dark ms-1"
                                      style="font-size:.5625rem">!</span>
                            <?php endif; ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="lrms-card-body">
            <div class="tab-content">
                <?php foreach ($orderedGroups as $group => $rows): ?>
                    <div class="tab-pane fade<?= $group === $activeGroup ? ' show active' : '' ?>"
                         id="tab-<?= e($group) ?>" role="tabpanel">

                        <div class="row g-3">
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $key = (string) $row['setting_key'];
                                $inputType = (string) $row['input_type'];
                                $isSecret = (int) $row['is_secret'] === 1;
                                $isRequired = (int) $row['is_required'] === 1;
                                $isMissing = isset($missingKeys[$key]);
                                $value = $row['setting_value'] === null ? '' : (string) $row['setting_value'];
                                $wide = in_array($inputType, ['textarea'], true);
                                ?>
                                <div class="col-md-<?= $wide ? '12' : '6' ?>">
                                    <label class="form-label" for="set-<?= e($key) ?>">
                                        <?= e($row['label']) ?>
                                        <?php if ($isRequired): ?><span class="req">*</span><?php endif; ?>
                                        <?php if ($isSecret): ?>
                                            <span class="text-muted" style="font-size:.6875rem;font-weight:400">
                                                <?= icon('lock') ?> secret
                                            </span>
                                        <?php endif; ?>
                                    </label>

                                    <?php if ($inputType === 'textarea'): ?>
                                        <textarea class="form-control<?= $isMissing ? ' is-invalid' : '' ?>"
                                                  id="set-<?= e($key) ?>" name="<?= e($key) ?>" rows="3"
                                                  <?= $canUpdate ? '' : 'disabled' ?>><?= e($value) ?></textarea>

                                    <?php elseif ($inputType === 'select'): ?>
                                        <select class="form-select<?= $isMissing ? ' is-invalid' : '' ?>"
                                                id="set-<?= e($key) ?>" name="<?= e($key) ?>"
                                                <?= $canUpdate ? '' : 'disabled' ?>>
                                            <?php foreach (explode(',', (string) ($row['options'] ?? '')) as $option): ?>
                                                <?php $option = trim($option); ?>
                                                <?php if ($option === '') { continue; } ?>
                                                <option value="<?= e($option) ?>" <?= $value === $option ? 'selected' : '' ?>>
                                                    <?= e($option) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                    <?php elseif ($inputType === 'toggle'): ?>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="set-<?= e($key) ?>" name="<?= e($key) ?>" value="1"
                                                   <?= in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? 'checked' : '' ?>
                                                   <?= $canUpdate ? '' : 'disabled' ?>>
                                            <label class="form-check-label" for="set-<?= e($key) ?>">Enabled</label>
                                        </div>

                                    <?php elseif ($isSecret): ?>
                                        <input type="password"
                                               class="form-control<?= $isMissing ? ' is-invalid' : '' ?>"
                                               id="set-<?= e($key) ?>" name="<?= e($key) ?>"
                                               value=""
                                               placeholder="<?= $value === '' ? 'Not set' : '•••••••• (unchanged)' ?>"
                                               autocomplete="new-password"
                                               <?= $canUpdate ? '' : 'disabled' ?>>

                                    <?php else: ?>
                                        <input type="<?= $inputType === 'number' ? 'number' : 'text' ?>"
                                               class="form-control<?= $isMissing ? ' is-invalid' : '' ?>"
                                               id="set-<?= e($key) ?>" name="<?= e($key) ?>"
                                               value="<?= e($value) ?>"
                                               <?= $canUpdate ? '' : 'disabled' ?>>
                                    <?php endif; ?>

                                    <?php if (!empty($row['hint'])): ?>
                                        <div class="form-text"><?= e($row['hint']) ?></div>
                                    <?php endif; ?>

                                    <?php if ($isSecret && $value !== ''): ?>
                                        <div class="form-text">
                                            A value is stored. Leave blank to keep it unchanged.
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($isMissing): ?>
                                        <div class="invalid-feedback d-block">This required setting is still blank.</div>
                                    <?php endif; ?>

                                    <div class="form-text font-mono" style="font-size:.625rem;opacity:.6">
                                        <?= e($key) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($canUpdate): ?>
            <div class="lrms-card-foot d-flex gap-2 align-items-center flex-wrap">
                <button type="submit" class="btn btn-primary">
                    <?= icon('check') ?> Save settings
                </button>
                <span class="text-muted" style="font-size:.8125rem">
                    Secret fields are only overwritten when you type a new value.
                </span>
            </div>
        <?php else: ?>
            <div class="lrms-card-foot">
                <span class="text-muted" style="font-size:.8125rem">
                    You have read-only access to settings.
                </span>
            </div>
        <?php endif; ?>
    </div>
</form>
