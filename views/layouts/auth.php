<?php
/**
 * Unauthenticated layout: login, forgot password, reset.
 *
 * @var string $content
 * @var string $title
 * @var array<string,list<string>> $flash
 */

use App\Core\Settings;

$appName = Settings::get('app_name', 'D2 Recovery Solutions & Services') ?? 'D2 Recovery Solutions & Services';
$bankName = Settings::get('bank_name', '');
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Sign in') . ' · ' . $appName) ?></title>

    <script>
        (function () {
            try {
                var stored = localStorage.getItem('lrms-theme');
                if (stored) document.documentElement.setAttribute('data-theme', stored);
            } catch (e) { /* private browsing */ }
        })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body>

<div class="lrms-auth">
    <section class="lrms-auth-brand">
        <!--
            No logo here on purpose. A sign-in page is a door, not a billboard: the
            artwork was competing with the one thing the page exists for. What is
            left is the name set as a wordmark - letterspaced, with a gold rule
            under it - which reads as considered rather than decorated.
        -->
        <div class="lrms-auth-wordmark">
            <span><?= e($appName) ?></span>
        </div>

        <div>
            <h1>Loan Recovery<br>Management System</h1>
            <p>
                Field verification and recovery follow-up for BC agents working on behalf of
                <?= e($bankName !== '' && $bankName !== null ? $bankName : 'the bank') ?>.
            </p>

            <ul class="lrms-auth-points">
                <li><?= icon('check') ?> Digital BC field visit reports</li>
                <li><?= icon('check') ?> Append-only visit history and audit trail</li>
                <li><?= icon('check') ?> Branch, village and agent-wise reporting</li>
                <li><?= icon('check') ?> Encrypted borrower mobile and Aadhaar</li>
            </ul>
        </div>

        <div class="lrms-auth-foot">
            Authorised personnel only. All access is logged.
        </div>
    </section>

    <section class="lrms-auth-form">
        <div class="lrms-auth-box">
            <!-- The brand panel beside this one is hidden below 900px, so the
                 wordmark is repeated here. -->
            <div class="lrms-auth-mobile-wordmark">
                <span><?= e($appName) ?></span>
            </div>

            <?= \App\Core\View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
            <?= $content ?>

            <p class="text-muted mt-4 mb-0" style="font-size:.75rem">
                Agents: use the D2 Recovery Solutions & Services Android app. This panel is for administrators and branch managers.
            </p>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
