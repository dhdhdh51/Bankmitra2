<?php
/** @var array<string,list<string>> $errors */
?>
<h2>Reset password</h2>
<p class="sub">Enter the OTP sent to your mobile, then choose a new password.</p>

<form method="post" action="<?= e(url('/reset-password')) ?>" novalidate data-no-double-submit>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="otp">One-time password <span class="req">*</span></label>
        <input type="text"
               class="form-control<?= has_error($errors, 'otp') ?>"
               id="otp"
               name="otp"
               inputmode="numeric"
               autocomplete="one-time-code"
               maxlength="6"
               pattern="[0-9]{4,6}"
               placeholder="6-digit code"
               style="letter-spacing:.35em;font-size:1.1rem"
               required
               autofocus>
        <?= field_error($errors, 'otp') ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password">New password <span class="req">*</span></label>
        <input type="password" class="form-control<?= has_error($errors, 'password') ?>"
               id="password" name="password" autocomplete="new-password" required>
        <?= field_error($errors, 'password') ?>
    </div>

    <div class="mb-4">
        <label class="form-label" for="password_confirmation">Confirm new password <span class="req">*</span></label>
        <input type="password" class="form-control" id="password_confirmation"
               name="password_confirmation" autocomplete="new-password" required>
    </div>

    <button type="submit" class="btn btn-primary w-100">
        <?= icon('check') ?> Set new password
    </button>

    <a href="<?= e(url('/forgot-password')) ?>" class="btn btn-outline-secondary w-100 mt-2">
        Request a new OTP
    </a>
</form>
