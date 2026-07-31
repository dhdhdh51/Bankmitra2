<?php
/**
 * @var bool                       $forced
 * @var array<string,list<string>> $errors
 */

use App\Core\Settings;

$minLength = max(6, Settings::int('password_min_length', 8));
?>
<div class="lrms-page-head">
    <div>
        <h1>Change password</h1>
        <p>
            <?= $forced
                ? 'Set a new password before you continue using the system.'
                : 'Update the password you use to sign in.' ?>
        </p>
    </div>
</div>

<?php if ($forced): ?>
    <div class="alert alert-warning mb-3">
        <?= icon('alert') ?>
        <div>
            This is your first sign-in with the password you were given, so a new password is required.
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6 col-xl-5">
        <div class="lrms-card">
            <div class="lrms-card-body">
                <form method="post" action="<?= e(url('/change-password')) ?>" novalidate data-no-double-submit>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password <span class="req">*</span></label>
                        <input type="password" class="form-control<?= has_error($errors, 'current_password') ?>"
                               id="current_password" name="current_password"
                               autocomplete="current-password" required autofocus>
                        <?= field_error($errors, 'current_password') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">New password <span class="req">*</span></label>
                        <input type="password" class="form-control<?= has_error($errors, 'password') ?>"
                               id="password" name="password" autocomplete="new-password"
                               minlength="<?= e((string) $minLength) ?>" required>
                        <?= field_error($errors, 'password') ?>
                        <div class="form-text">At least <?= e((string) $minLength) ?> characters.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="password_confirmation">Confirm new password <span class="req">*</span></label>
                        <input type="password" class="form-control" id="password_confirmation"
                               name="password_confirmation" autocomplete="new-password" required>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <?= icon('check') ?> Update password
                        </button>
                        <?php if (!$forced): ?>
                            <a href="<?= e(url('/dashboard')) ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
