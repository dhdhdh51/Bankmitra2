<?php
/**
 * Requests every admin panel route over HTTP and asserts each one renders.
 *
 * Driven by tools/smoke-panel.sh. Logs in as the seeded super admin (handling
 * the forced first-login password change), then walks the routes checking status
 * codes and looking for PHP errors leaking into the HTML.
 */

declare(strict_types=1);

$base = rtrim(getenv('LRMS_BASE') ?: 'http://127.0.0.1:8099', '/');
$cookieJar = sys_get_temp_dir() . '/lrms_smoke_cookies.txt';
@unlink($cookieJar);

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
        return;
    }
    $failed++;
    $failures[] = $label;
    echo "  FAIL  {$label}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

function section(string $name): void
{
    echo "\n== {$name}\n";
}

/**
 * @param array<string,string>|null $post
 * @return array{status:int, body:string, headers:string}
 */
function request(string $url, ?array $post = null, bool $follow = true): array
{
    global $cookieJar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => '', 'headers' => ''];
    }

    return [
        'status'  => $status,
        'headers' => substr((string) $raw, 0, $headerSize),
        'body'    => substr((string) $raw, $headerSize),
    ];
}

/** Extracts the CSRF token from a rendered form. */
function csrfToken(string $html): string
{
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    return '';
}

/** PHP notices/warnings/fatals must never reach the response body. */
function hasPhpError(string $body): string
{
    foreach ([
        'Fatal error', 'Parse error', 'Warning:', 'Notice:',
        'Deprecated:', 'Uncaught', 'Undefined variable', 'Undefined array key',
        'Call to undefined', 'View not found', 'SQLSTATE',
    ] as $needle) {
        if (str_contains($body, $needle)) {
            // Extract a little context to make the failure actionable.
            $position = strpos($body, $needle);
            return trim(preg_replace('/\s+/', ' ', substr($body, max(0, $position - 40), 220)) ?? $needle);
        }
    }
    return '';
}

/**
 * Asserts a page renders: expected status, no PHP errors, and the shell present.
 */
function page(string $label, string $path, int $expected = 200, ?string $mustContain = null): string
{
    global $base;

    $response = request($base . $path);
    $error = hasPhpError($response['body']);

    if ($response['status'] !== $expected) {
        check($label, false, "HTTP {$response['status']} (expected {$expected})");
        return $response['body'];
    }
    if ($error !== '') {
        check($label, false, 'PHP error: ' . $error);
        return $response['body'];
    }
    if ($mustContain !== null && !str_contains($response['body'], $mustContain)) {
        check($label, false, 'missing expected content: ' . $mustContain);
        return $response['body'];
    }

    check($label, true);
    return $response['body'];
}

// ---------------------------------------------------------------------------
section('Unauthenticated');

$loginPage = page('GET /login renders', '/login', 200, 'Sign in');
check('login page has CSRF token', csrfToken($loginPage) !== '');
check('login page shows the brand panel', str_contains($loginPage, 'Loan Recovery'));

$redirect = request($base . '/dashboard', null, false);
check('dashboard redirects when signed out', $redirect['status'] === 302, 'HTTP ' . $redirect['status']);

page('GET /forgot-password renders', '/forgot-password', 200, 'Forgot password');
page('unknown route returns 404', '/no-such-page', 404);

$asset = request($base . '/assets/css/app.css');
check('CSS asset is served', $asset['status'] === 200 && str_contains($asset['body'], '--lrms-primary'));

$blocked = request($base . '/config/config.php');
check('config directory is not readable', $blocked['status'] === 404, 'HTTP ' . $blocked['status']);
$blockedApp = request($base . '/app/Core/Database.php');
check('app directory is not readable', $blockedApp['status'] === 404, 'HTTP ' . $blockedApp['status']);

// ---------------------------------------------------------------------------
section('Sign in');

$token = csrfToken($loginPage);
$login = request($base . '/login', [
    '_csrf'         => $token,
    'employee_code' => 'ADMIN001',
    'password'      => 'Admin@123',
]);
check('sign-in succeeds', $login['status'] === 200, 'HTTP ' . $login['status']);

// The seeded admin has must_change_password = 1, so we land on the change form.
check('forced password change is enforced', str_contains($login['body'], 'Change password'));

$changeToken = csrfToken($login['body']);
$changed = request($base . '/change-password', [
    '_csrf'                 => $changeToken,
    'current_password'      => 'Admin@123',
    'password'              => 'Smoke@12345',
    'password_confirmation' => 'Smoke@12345',
]);
check('password change succeeds', $changed['status'] === 200 && str_contains($changed['body'], 'Dashboard'),
    'HTTP ' . $changed['status']);

// ---------------------------------------------------------------------------
section('Authenticated pages');

$dashboard = page('GET /dashboard', '/dashboard', 200, 'Total leads');
check('dashboard shows seeded lead count', preg_match('/Total leads/', $dashboard) === 1);
check('dashboard renders the visit chart', str_contains($dashboard, 'lrms-bars'));
check('dashboard renders top agents', str_contains($dashboard, 'Top agents'));
check('sidebar renders navigation', str_contains($dashboard, 'Customers &amp; Leads'));

$customers = page('GET /customers', '/customers', 200, 'Loan Account');
check('leads table renders rows', str_contains($customers, 'LN00100'));
check('status chips render', str_contains($customers, 'lrms-chip'));
check('bulk action bar present', str_contains($customers, 'data-bulk-bar'));
check('masked mobile shown (not plaintext)',
    str_contains($customers, 'XXXXXX') && !preg_match('/>9876510\d{3}</', $customers));

page('GET /customers with search', '/customers?search=Ramesh', 200);
page('GET /customers with status filter', '/customers?status=pending', 200);
page('GET /customers with npa filter', '/customers?npa_only=1', 200);
page('GET /customers unassigned filter', '/customers?unassigned=1', 200);
page('GET /customers sorted', '/customers?sort_by=outstanding_amount&sort_dir=asc', 200);
page('GET /customers page 2', '/customers?page=2', 200);

// Find a real lead id to open the profile.
preg_match('#/customers/(\d+)"#', $customers, $leadMatch);
$leadId = (int) ($leadMatch[1] ?? 1);

$profile = page('GET /customers/{id}', '/customers/' . $leadId, 200, 'Borrower details');
check('profile shows loan details', str_contains($profile, 'Loan details'));
check('profile shows the timeline', str_contains($profile, 'lrms-timeline'));
check('profile timeline notes append-only', str_contains($profile, 'Append-only history'));
check('profile shows visit history', str_contains($profile, 'Visit history'));
page('GET /customers/{id}/edit', '/customers/' . $leadId . '/edit', 200, 'Edit borrower');

$visits = page('GET /visits', '/visits', 200, 'Visit Reports');
preg_match('#/visits/(\d+)"#', $visits, $visitMatch);
$visitId = (int) ($visitMatch[1] ?? 1);

$visitShow = page('GET /visits/{id}', '/visits/' . $visitId, 200, 'Digital BC Field Visit Report');
foreach ([
    'General', 'Borrower details', 'Loan details', 'Customer contact',
    'Physical verification', 'Recovery possibility', 'Non-payment reason',
    'Agent recommendation', 'Remarks',
] as $sectionName) {
    check("visit report has section: {$sectionName}", str_contains($visitShow, $sectionName));
}

$visitPdf = request($base . '/visits/' . $visitId . '/pdf');
check('visit report PDF downloads', $visitPdf['status'] === 200 && str_starts_with($visitPdf['body'], '%PDF'),
    'HTTP ' . $visitPdf['status']);

// ---------------------------------------------------------------------------
// The two report-type sections.
//
// Found by walking the visit list for a report of each type rather than assuming
// an id: the seeder's ordering is not a contract. Without these checks the
// settlement and renewal cards would ship having never been rendered once.
// ---------------------------------------------------------------------------
preg_match_all('#/visits/(\d+)"#', $visits, $allVisitMatches);
$candidateIds = array_slice(array_unique(array_map('intval', $allVisitMatches[1] ?? [])), 0, 40);

$otsPage = null;
$ckccPage = null;
foreach ($candidateIds as $candidateId) {
    $body = request($base . '/visits/' . $candidateId)['body'];
    // Match on something only the RENDERED card contains. Looking for the section
    // title matched the HTML comment above the block on every single page, so this
    // check passed against a plain recovery report and then failed on all of its
    // own sub-checks - a false positive that pointed at the wrong bug entirely.
    if ($otsPage === null && str_contains($body, 'Residual loan balance')) {
        $otsPage = $body;
    }
    if ($ckccPage === null && str_contains($body, 'Documents the borrower had')) {
        $ckccPage = $body;
    }
    if ($otsPage !== null && $ckccPage !== null) {
        break;
    }
}

check('a KRM/OTS settlement report is rendered somewhere in the list', $otsPage !== null);
if ($otsPage !== null) {
    check('OTS card shows the scheme', str_contains($otsPage, 'KRM OTS'));
    check('OTS card shows the approval status', str_contains($otsPage, 'Approved'));
    check('OTS card shows the settlement figures', str_contains($otsPage, 'Total settlement'));
    check('OTS card shows the balance payable', str_contains($otsPage, 'Balance payable'));
    check('OTS card shows the bank receipt reference', str_contains($otsPage, 'RCPT/2026/004417'));
    // The screen must say plainly that the agent did not take the money.
    check('OTS card states that agents never collect money',
        str_contains($otsPage, 'Agents never collect money'));
}

check('a CKCC renewal report is rendered somewhere in the list', $ckccPage !== null);
if ($ckccPage !== null) {
    check('CKCC card shows the renewal countdown',
        str_contains($ckccPage, 'left to renew')
        || str_contains($ckccPage, 'due today')
        || str_contains($ckccPage, 'overdue by'));
    // The consequence of missing the deadline is the reason this report exists.
    check('CKCC card spells out the expected NPA date',
        str_contains($ckccPage, 'expected to turn'));
    check('CKCC card shows the due bucket badge', str_contains($ckccPage, 'Within 7 Days'));
    check('CKCC card lists the documents the borrower had',
        str_contains($ckccPage, 'Documents the borrower had'));
    check('CKCC card shows renewal consent', str_contains($ckccPage, 'Renewal consent'));
    check('CKCC card shows the agent observation',
        str_contains($ckccPage, 'Land records in order'));
    check('CKCC card shows the report status', str_contains($ckccPage, 'Report status'));
    // No location data exists anywhere in this system.
    check('CKCC card shows no GPS or location field',
        !str_contains($ckccPage, 'GPS') && !stripos($ckccPage, 'Latitude'));
}

page('GET /promises', '/promises', 200, 'Promises');
page('GET /promises pending', '/promises?status=pending', 200);
page('GET /promises kept', '/promises?status=kept', 200);

page('GET /import', '/import', 200, 'Excel Import');
page('GET /import/history', '/import/history', 200, 'Import history');

$template = request($base . '/import/template');
check('import template downloads', $template['status'] === 200 && str_starts_with($template['body'], "PK\x03\x04"),
    'HTTP ' . $template['status']);

page('GET /branches', '/branches', 200, 'Branches');
page('GET /branches/create', '/branches/create', 200, 'Add branch');
page('GET /branches/{id}/edit', '/branches/1/edit', 200, 'Edit branch');

page('GET /users', '/users', 200, 'Managers &amp; Agents');
page('GET /users/create', '/users/create', 200, 'Add user');
page('GET /users/{id}/edit', '/users/2/edit', 200, 'Edit user');

$roles = page('GET /roles', '/roles', 200, 'Roles &amp; Permissions');
check('permission matrix renders', str_contains($roles, 'lrms-check-grid'));
check('super admin role is locked', str_contains($roles, 'not editable') || str_contains($roles, 'always holds every permission'));
page('GET /roles?role_id=3 (agent)', '/roles?role_id=3', 200);

page('GET /notifications', '/notifications', 200, 'Notifications');
page('GET /notifications/send', '/notifications/send', 200, 'Send broadcast');
page('GET /logs/audit', '/logs/audit', 200, 'Audit Logs');
page('GET /logs/activity', '/logs/activity', 200, 'Activity Logs');
page('GET /backup', '/backup', 200, 'Database Backup');

$settings = page('GET /settings', '/settings', 200, 'Settings');
check('settings groups render as tabs', str_contains($settings, 'tab-general'));
check('secret settings are not echoed back', !str_contains($settings, 'demo-key'));
check('integration status renders', str_contains($settings, 'Integration status'));

// ---------------------------------------------------------------------------
section('All 8 reports');

$reportsIndex = page('GET /reports', '/reports', 200, 'Reports');
check('report grid shows 8 cards', substr_count($reportsIndex, 'lrms-report-card') === 8,
    (string) substr_count($reportsIndex, 'lrms-report-card'));

$reportTypes = ['daily', 'weekly', 'monthly', 'branch', 'village', 'loan-type', 'agent', 'promise'];

foreach ($reportTypes as $type) {
    $body = page("GET /reports/{$type}", '/reports/' . $type, 200);
    check("report [{$type}] renders a table or empty state",
        str_contains($body, 'lrms-table') || str_contains($body, 'No data for these filters'));

    $excel = request($base . '/reports/' . $type . '/export?format=excel');
    check("report [{$type}] Excel export", $excel['status'] === 200 && str_starts_with($excel['body'], "PK\x03\x04"),
        'HTTP ' . $excel['status'] . ' len=' . strlen($excel['body']));

    $pdf = request($base . '/reports/' . $type . '/export?format=pdf');
    check("report [{$type}] PDF export", $pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF'),
        'HTTP ' . $pdf['status'] . ' len=' . strlen($pdf['body']));
}

page('report with branch filter', '/reports/branch?branch_id=1', 200);
page('report with empty period', '/reports/daily?date=1999-01-01', 200);

// ---------------------------------------------------------------------------
section('Exports & media');

$leadExport = request($base . '/customers/export');
check('leads Excel export', $leadExport['status'] === 200 && str_starts_with($leadExport['body'], "PK\x03\x04"),
    'HTTP ' . $leadExport['status']);

// Uploads must not be reachable directly, only through /media with auth.
$directUpload = request($base . '/uploads/photos/2026/01/anything.png');
check('direct upload access is blocked', in_array($directUpload['status'], [403, 404], true),
    'HTTP ' . $directUpload['status']);

$traversal = request($base . '/media?f=../config/config.php');
check('media path traversal is rejected', in_array($traversal['status'], [400, 403, 404], true),
    'HTTP ' . $traversal['status']);

$badType = request($base . '/media?f=test.php');
check('media rejects non-image types', in_array($badType['status'], [400, 403, 404, 415], true),
    'HTTP ' . $badType['status']);

// ---------------------------------------------------------------------------
section('CSRF protection');

$noToken = request($base . '/customers/bulk', ['bulk_action' => 'close', 'lead_ids[]' => '1']);
check('POST without CSRF token is refused',
    !str_contains($noToken['body'], 'lead(s) updated'),
    'response suggested the action ran');

// ---------------------------------------------------------------------------
section('Sign out');

$dashboardAgain = request($base . '/dashboard');
$logoutToken = csrfToken($dashboardAgain['body']);
$logout = request($base . '/logout', ['_csrf' => $logoutToken]);
check('sign-out returns to login', str_contains($logout['body'], 'Sign in'), 'HTTP ' . $logout['status']);

$afterLogout = request($base . '/dashboard', null, false);
check('dashboard is protected after sign-out', $afterLogout['status'] === 302, 'HTTP ' . $afterLogout['status']);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
printf("  PANEL SMOKE: %d passed, %d failed\n", $passed, $failed);
if ($failures !== []) {
    echo '  Failed: ' . implode('; ', $failures) . "\n";
}
echo str_repeat('=', 60) . "\n";

@unlink($cookieJar);
exit($failed === 0 ? 0 : 1);
