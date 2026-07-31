<?php
/**
 * Minimal chrome-less layout for pages shown to unauthenticated visitors
 * (currently the 404 page).
 *
 * @var string $content
 * @var string $title
 */

use App\Core\Settings;

$appName = Settings::get('app_name', 'LRMS') ?? 'LRMS';
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Not found') . ' · ' . $appName) ?></title>

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
    <div class="container" style="max-width:680px;padding:12vh 16px">
        <div class="d-flex align-items-center gap-2 mb-4">
            <span class="lrms-brand-mark">LR</span>
            <strong><?= e($appName) ?></strong>
        </div>
        <?= $content ?>
        <p class="text-center mt-4 mb-0">
            <a href="<?= e(url('/login')) ?>" style="font-size:.875rem">Sign in</a>
        </p>
    </div>
</body>
</html>
