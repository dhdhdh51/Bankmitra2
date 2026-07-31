<?php
/**
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */
?>
<h2>Sign in</h2>
<p class="sub">Enter your employee code and password to continue.</p>

<form method="post" action="<?= e(url('/login')) ?>" novalidate data-no-double-submit>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="employee_code">Employee code or email <span class="req">*</span></label>
        <input type="text"
               class="form-control<?= has_error($errors, 'employee_code') ?>"
               id="employee_code"
               name="employee_code"
               value="<?= old($old, 'employee_code') ?>"
               placeholder="e.g. ADMIN001"
               autocomplete="username"
               autocapitalize="characters"
               spellcheck="false"
               required
               autofocus>
        <?= field_error($errors, 'employee_code') ?>
    </div>

    <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label mb-0" for="password">Password <span class="req">*</span></label>
            <a href="<?= e(url('/forgot-password')) ?>" style="font-size:.75rem">Forgot password?</a>
        </div>
        <input type="password"
               class="form-control<?= has_error($errors, 'password') ?>"
               id="password"
               name="password"
               autocomplete="current-password"
               required>
        <?= field_error($errors, 'password') ?>
    </div>

    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
        <label class="form-check-label" for="remember">Keep me signed in on this device</label>
    </div>

    <button type="submit" class="btn btn-primary w-100">
        <?= icon('lock') ?> Sign in
    </button>
</form>
