<?php
/**
 * Main authenticated layout.
 *
 * @var string                    $content     Rendered page body
 * @var string                    $title       Page title
 * @var array<string,mixed>|null  $authUser
 * @var array<string,list<string>> $flash
 * @var string                    $currentPath
 */

use App\Core\Settings;
use App\Core\Url;

$appName = Settings::get('app_name', 'D2 Recovery') ?? 'D2 Recovery';
$bankName = Settings::get('bank_name', '');
$currentPath = $currentPath ?? '/';
$pageTitle = ($title ?? 'Dashboard') . ' · ' . $appName;
$unread = $unreadNotifications ?? 0;
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?></title>

    <script>
        // Applied before first paint so there is no flash of the wrong theme.
        (function () {
            try {
                var stored = localStorage.getItem('lrms-theme');
                var theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) { /* private browsing */ }
        })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!--
        Inter as a VARIABLE font (wght@400..700), not four static cuts.

        The stylesheet asks for intermediate weights - 560 on buttons, 620 on an
        active nav item, 650 on headings - and with static cuts the browser has to
        round or synthesise them, which is exactly the mushy look this palette is
        trying to avoid. One variable file also weighs less than four static ones.

        preconnect matters more than it looks: a webfont request blocks first
        paint, and agents open this panel over rural links. Warming both the CSS
        and the font-file origins removes two round trips of DNS and TLS from the
        critical path. Both are needed - the stylesheet and the font binaries come
        from different hosts - and gstatic must be crossorigin because fonts are
        fetched in CORS mode.
    -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body>

<div class="lrms-shell">

    <aside class="lrms-sidebar">
        <div class="lrms-brand">
            <img class="lrms-brand-mark" src="<?= e(asset('img/d2-mark.webp')) ?>"
                 alt="" width="44" height="32">
            <span class="lrms-brand-text">
                <strong><?= e($appName) ?></strong>
                <span><?= e($bankName !== '' && $bankName !== null ? $bankName : 'Recovery Management') ?></span>
            </span>
        </div>

        <nav class="lrms-nav">
            <?php
            /*
             * The navigation mirrors the allowAgent flags on the controllers, which is
             * a narrower thing than the permissions an agent holds. An agent holds
             * visits.view so the Android app can list their visits; the panel's visit
             * screens are not scoped to one agent, so they stay shut here. A link to a
             * page that refuses you is worse than no link at all.
             */
            ?>
            <?php if (!is_agent()): ?>
                <a class="lrms-nav-item<?= active_nav('/dashboard', $currentPath) ?>" href="<?= e(url('/dashboard')) ?>">
                    <?= icon('dashboard') ?> Dashboard
                </a>
            <?php endif; ?>

            <div class="lrms-nav-label">Recovery</div>

            <?php if (can('customers.view')): ?>
                <a class="lrms-nav-item<?= active_nav('/customers', $currentPath) ?>" href="<?= e(url('/customers')) ?>">
                    <?= icon('customers') ?> <?= is_agent() ? 'My Borrowers' : 'Customers &amp; Leads' ?>
                </a>
            <?php endif; ?>

            <?php if (can('promises.view') && !is_agent()): ?>
                <a class="lrms-nav-item<?= active_nav('/promises', $currentPath) ?>" href="<?= e(url('/promises')) ?>">
                    <?= icon('handshake') ?> Promises
                </a>
            <?php endif; ?>

            <?php if (can('visits.view') && !is_agent()): ?>
                <a class="lrms-nav-item<?= active_nav('/visits', $currentPath) ?>" href="<?= e(url('/visits')) ?>">
                    <?= icon('clipboard') ?> Visit Reports
                </a>
            <?php endif; ?>

            <?php if (can('import.upload') || can('import.view')): ?>
                <a class="lrms-nav-item<?= active_nav('/import', $currentPath) ?>" href="<?= e(url('/import')) ?>">
                    <?= icon('upload') ?> Excel Import
                </a>
            <?php endif; ?>

            <?php if (can('reports.view')): ?>
                <a class="lrms-nav-item<?= active_nav('/reports', $currentPath) ?>" href="<?= e(url('/reports')) ?>">
                    <?= icon('reports') ?> Reports
                </a>
            <?php endif; ?>

            <?php if (can('scorecard.view') || can('bc_targets.view') || can('sss.view')): ?>
                <div class="lrms-nav-label">BC Performance</div>

                <?php if (can('scorecard.view')): ?>
                    <a class="lrms-nav-item<?= active_nav('/bc/scorecard', $currentPath) ?>" href="<?= e(url('/bc/scorecard')) ?>">
                        <?= icon('chart') ?> BC Summary Report
                    </a>
                <?php endif; ?>

                <?php if (can('bc_targets.view')): ?>
                    <a class="lrms-nav-item<?= active_nav('/bc/targets', $currentPath) ?>" href="<?= e(url('/bc/targets')) ?>">
                        <?= icon('clipboard') ?> BC Targets
                    </a>
                <?php endif; ?>

                <?php if (can('sss.view')): ?>
                    <a class="lrms-nav-item<?= active_nav('/bc/sss', $currentPath) ?>" href="<?= e(url('/bc/sss')) ?>">
                        <?= icon('handshake') ?> SSS Enrolment
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (can('branches.view') || can('users.view') || can('roles.view')): ?>
                <div class="lrms-nav-label">Administration</div>

                <?php if (can('branches.view')): ?>
                    <a class="lrms-nav-item<?= active_nav('/branches', $currentPath) ?>" href="<?= e(url('/branches')) ?>">
                        <?= icon('branch') ?> Branches
                    </a>
                <?php endif; ?>

                <?php if (can('users.view')): ?>
                    <a class="lrms-nav-item<?= active_nav('/users', $currentPath) ?>" href="<?= e(url('/users')) ?>">
                        <?= icon('users') ?> Managers &amp; Agents
                    </a>
                <?php endif; ?>

                <?php if (can('roles.view')): ?>
                    <a class="lrms-nav-item<?= active_nav('/roles', $currentPath) ?>" href="<?= e(url('/roles')) ?>">
                        <?= icon('shield') ?> Roles &amp; Permissions
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (can('logs.audit') || can('logs.activity') || can('backup.run') || can('settings.view') || can('custom_fields.manage')): ?>
                <div class="lrms-nav-label">System</div>

                <?php if (can('logs.audit')): ?>
                    <a class="lrms-nav-item<?= active_nav('/logs/audit', $currentPath) ?>" href="<?= e(url('/logs/audit')) ?>">
                        <?= icon('logs') ?> Audit Logs
                    </a>
                <?php endif; ?>

                <?php if (can('logs.activity')): ?>
                    <a class="lrms-nav-item<?= active_nav('/logs/activity', $currentPath) ?>" href="<?= e(url('/logs/activity')) ?>">
                        <?= icon('clock') ?> Activity Logs
                    </a>
                <?php endif; ?>

                <?php if (can('backup.run')): ?>
                    <a class="lrms-nav-item<?= active_nav('/backup', $currentPath) ?>" href="<?= e(url('/backup')) ?>">
                        <?= icon('database') ?> Database Backup
                    </a>
                <?php endif; ?>

                <?php if (can('custom_fields.manage')): ?>
                    <a class="lrms-nav-item<?= active_nav('/custom-fields', $currentPath) ?>" href="<?= e(url('/custom-fields')) ?>">
                        <?= icon('clipboard') ?> Custom Fields
                    </a>
                <?php endif; ?>

                <?php if (can('settings.view')): ?>
                    <a class="lrms-nav-item<?= active_nav('/settings', $currentPath) ?>" href="<?= e(url('/settings')) ?>">
                        <?= icon('settings') ?> Settings
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="lrms-sidebar-backdrop"></div>

    <div class="lrms-main">
        <header class="lrms-topbar">
            <button type="button" class="btn btn-ghost btn-icon d-lg-none" data-sidebar-toggle
                    aria-label="Toggle navigation">
                <?= icon('menu') ?>
            </button>

            <form action="<?= e(url('/customers')) ?>" method="get" class="d-none d-md-block flex-grow-1" style="max-width:420px">
                <div class="position-relative">
                    <span class="position-absolute top-50 translate-middle-y text-muted" style="left:10px;line-height:0">
                        <?= icon('search') ?>
                    </span>
                    <input type="search" name="search" class="form-control ps-5"
                           placeholder="Search account no, name, mobile, Aadhaar, village"
                           value="<?= e($_GET['search'] ?? '') ?>" aria-label="Search customers">
                </div>
            </form>

            <div class="ms-auto d-flex align-items-center gap-1">
                <button type="button" class="btn btn-ghost btn-icon" data-theme-toggle
                        title="Toggle light / dark mode" aria-label="Toggle light or dark mode">
                    <span data-theme-icon="light"><?= icon('moon') ?></span>
                    <span data-theme-icon="dark" class="d-none"><?= icon('sun') ?></span>
                </button>

                <a href="<?= e(url('/notifications')) ?>" class="btn btn-ghost btn-icon position-relative"
                   title="Notifications" aria-label="Notifications">
                    <?= icon('bell') ?>
                    <?php if ($unread > 0): ?>
                        <span class="position-absolute badge rounded-pill bg-danger"
                              style="top:2px;right:2px;font-size:.5625rem;padding:.15em .35em">
                            <?= e($unread > 99 ? '99+' : (string) $unread) ?>
                        </span>
                    <?php endif; ?>
                </a>

                <div class="dropdown">
                    <button class="btn btn-ghost d-flex align-items-center gap-2 px-2" data-bs-toggle="dropdown"
                            aria-expanded="false">
                        <span class="lrms-avatar">
                            <?= e(mb_substr((string) ($authUser['name'] ?? '?'), 0, 1)) ?>
                        </span>
                        <span class="d-none d-sm-block text-start" style="line-height:1.2">
                            <span class="d-block" style="font-size:.8125rem;font-weight:620">
                                <?= e($authUser['name'] ?? '') ?>
                            </span>
                            <span class="d-block text-muted" style="font-size:.6875rem">
                                <?= e($authUser['role_name'] ?? '') ?>
                            </span>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="px-2 py-1">
                            <div style="font-size:.8125rem;font-weight:620"><?= e($authUser['name'] ?? '') ?></div>
                            <div class="text-muted" style="font-size:.75rem">
                                <?= e($authUser['employee_code'] ?? '') ?>
                                <?php if (!empty($authUser['branch_name'])): ?>
                                    · <?= e($authUser['branch_name']) ?>
                                <?php endif; ?>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center gap-2" href="<?= e(url('/change-password')) ?>">
                                <?= icon('key') ?> Change password
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('/logout')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item d-flex align-items-center gap-2 text-danger">
                                    <?= icon('logout') ?> Sign out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="lrms-content">
            <?= \App\Core\View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
            <?= $content ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
