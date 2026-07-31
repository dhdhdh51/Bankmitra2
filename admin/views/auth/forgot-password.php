<?php
/**
 * @var bool                       $smsAvailable
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */
?>
<h2>Forgot password</h2>
<p class="sub">We will send a one-time password to your registered mobile number.</p>

<?php if (!$smsAvailable): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <div>
            The SMS gateway is not configured yet, so OTP delivery is unavailable.
            Ask your Super Admin to reset your password from
            <strong>Managers &amp; Agents</strong>.
        </div>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate data-no-double-submit>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="employee_code">Employee code or registered mobile <span class="req">*</span></label>
        <input type="text"
               class="form-control<?= has_error($errors, 'employee_code') ?>"
               id="employee_code"
               name="employee_code"
               value="<?= old($old, 'employee_code') ?>"
               autocomplete="username"
               spellcheck="false"
               required
               autofocus>
        <?= field_error($errors, 'employee_code') ?>
        <div class="form-text">Enter the same code you use to sign in.</div>
    </div>

    <button type="submit" class="btn btn-primary w-100">
        <?= icon('send') ?> Send OTP
    </button>

    <a href="<?= e(url('/login')) ?>" class="btn btn-outline-secondary w-100 mt-2">
        <?= icon('chevron-left') ?> Back to sign in
    </a>
</form>
