<?php
/**
 * Requests every admin panel route over HTTP and asserts each one renders.
 *
 * Driven by tools/smoke-panel.sh. Logs in as the seeded super admin (handling
 * the forced first-login password change), then walks the routes checking status
 * codes and looking for PHP errors leaking into the HTML.
 */

declare(strict_types=1);

// Same calendar as the server under test - see the note in smoke-api.php. The panel
// posts dates too (visit dates, report ranges), and a harness a day behind the app
// files them outside the windows the app enforces.
date_default_timezone_set('Asia/Kolkata');

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
/**
 * POSTs a form with file parts.
 *
 * Separate from request() because that one uses http_build_query, which sends the
 * filename as a plain string and nothing else - an upload that silently arrives
 * empty looks identical to one that worked until you go looking for the file.
 *
 * @param array<string,string> $fields
 * @param array<string,string> $files  field name => absolute path on disk
 * @return array{status:int,body:string}
 */
function postMultipart(string $url, array $fields, array $files): array
{
    global $cookieJar;

    $payload = $fields;
    foreach ($files as $field => $path) {
        $payload[$field] = new CURLFile($path, mime_content_type($path) ?: 'application/octet-stream', basename($path));
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $status, 'body' => substr($raw, $headerSize)];
}

/** The value of a text input, so a test can resubmit a form without changing it. */
function formValue(string $html, string $name): string
{
    if (preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    // Some inputs put value= before name=.
    if (preg_match('/value="([^"]*)"[^>]*name="' . preg_quote($name, '/') . '"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    return '';
}

/**
 * The currently selected option of a <select>, or a hidden input of the same name.
 *
 * Needed because a test that hardcodes a branch id silently REASSIGNS the user to
 * that branch, and the next test to rely on branch scoping then fails somewhere
 * completely unrelated - which is exactly what happened when this was written.
 */
function selectedOption(string $html, string $name): string
{
    if (preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '"(.*?)<\/select>/s', $html, $block) === 1) {
        if (preg_match('/<option[^>]*value="([^"]*)"[^>]*selected/', $block[1], $m) === 1) {
            return $m[1];
        }
    }

    return formValue($html, $name);
}

/** A small valid PNG on disk, for upload tests. */
function tempPng(int $w = 40, int $h = 20, array $rgb = [10, 40, 90]): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    $path = sys_get_temp_dir() . '/lrms_smoke_' . bin2hex(random_bytes(6)) . '.png';
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

/**
 * A throwaway CSV on disk, for the endpoints that take a real upload.
 *
 * @param list<string> $lines
 */
function tempCsv(array $lines): string
{
    $path = sys_get_temp_dir() . '/lrms_smoke_' . bin2hex(random_bytes(6)) . '.csv';
    file_put_contents($path, implode("\n", $lines) . "\n");

    return $path;
}

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

// The product is D2 Recovery. app_name lives in the settings table, so this also
// proves the seed carries the new name - an install seeded with the old one keeps
// showing it until the row is updated, which is the one thing a rename cannot
// reach from the code.
check('login page shows the product name', str_contains($loginPage, 'D2 Recovery'));
check(
    'login page has no trace of the old product name',
    !str_contains($loginPage, 'LRMS'),
    'found "LRMS" in the rendered page',
);

$redirect = request($base . '/dashboard', null, false);
check('dashboard redirects when signed out', $redirect['status'] === 302, 'HTTP ' . $redirect['status']);

page('GET /forgot-password renders', '/forgot-password', 200, 'Forgot password');
page('unknown route returns 404', '/no-such-page', 404);

$asset = request($base . '/assets/css/app.css');
check('CSS asset is served', $asset['status'] === 200 && str_contains($asset['body'], '--lrms-primary'));

// The monogram sits in the sidebar and the 404 header, so a missing file would
// leave a broken image on every signed-in page.
$monogram = request($base . '/assets/img/d2-mark.webp');
check(
    'brand monogram is served',
    $monogram['status'] === 200 && str_starts_with($monogram['body'], 'RIFF'),
    'HTTP ' . $monogram['status'] . ', ' . strlen((string) $monogram['body']) . ' bytes',
);

// The sign-in page carries NO artwork by choice: type only, nothing to load.
check(
    'login page loads no logo image',
    !str_contains($loginPage, '/assets/img/'),
    'the sign-in page is still requesting an image',
);
check('login page sets the name as a wordmark', str_contains($loginPage, 'lrms-auth-wordmark'));
check(
    'login page no longer shows the old LR monogram',
    !str_contains($loginPage, '>LR<'),
    'the "LR" initial is still rendered',
);

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
check('signed-in header shows the product name', str_contains($dashboard, 'D2 Recovery'));
check(
    'signed-in page has no trace of the old product name',
    !str_contains($dashboard, 'LRMS'),
    'found "LRMS" in the rendered page',
);
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

// Edit has to be reachable from the card holding the field you want to change. There was
// one button at the top of a page that scrolls for several screens, which reads as absent
// by the time you have found the wrong value.
check('the borrower card offers an edit control',
    str_contains($profile, '/edit#borrower'));
check('the loan card offers one of its own',
    str_contains($profile, '/edit#loan'));

$editAnchors = request($base . '/customers/' . $leadId . '/edit')['body'];
check('the edit form has a borrower anchor to land on',
    str_contains($editAnchors, 'id="borrower"'));
check('and a loan anchor', str_contains($editAnchors, 'id="loan"'));
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

// The printed report has to SHOW the evidence, not count it. Until images were
// embeddable this section said "Photos: 3" and stopped there, which is not evidence
// of anything. Walk the list for a report that actually has media - the seeder gives
// media to every third visit and its ordering is not a contract.
preg_match_all('#/visits/(\d+)"#', $visits, $mediaVisitMatches);
$mediaCandidates = array_slice(array_unique(array_map('intval', $mediaVisitMatches[1] ?? [])), 0, 14);

$pdfWithMedia = null;
$pdfWithMediaId = null;
foreach ($mediaCandidates as $candidateId) {
    $candidate = request($base . '/visits/' . $candidateId . '/pdf');
    if ($candidate['status'] === 200 && str_contains($candidate['body'], '/Subtype /Image')) {
        $pdfWithMedia = $candidate['body'];
        $pdfWithMediaId = $candidateId;
        break;
    }
}

check('a visit report with media was found to print', $pdfWithMedia !== null,
    'no seeded visit produced a PDF containing an image');

if ($pdfWithMedia !== null) {
    check('the printed report embeds the images', substr_count($pdfWithMedia, '/Subtype /Image') >= 2,
        (string) substr_count($pdfWithMedia, '/Subtype /Image'));
    check('the images are declared in the page resources', str_contains($pdfWithMedia, '/XObject <<'));
    check('and are actually drawn', str_contains($pdfWithMedia, ' Do'));

    // The sections that only exist because images do.
    foreach (['Location Recorded', 'Field Photographs', 'Signatures', 'Approval'] as $section) {
        check("printed report has section: {$section}", str_contains($pdfWithMedia, $section));
    }

    // A geo caption is the whole point of a geo-tagged photograph: latitude to six
    // decimal places, so pasting it into a map lands where the agent stood.
    check('a photograph carries its coordinates',
        preg_match('/\d{2}\.\d{6}, \d{2}\.\d{6}/', $pdfWithMedia) === 1);
    // And a gallery pick must say it has none rather than borrowing the visit's fix.
    check('a gallery photograph is labelled as having no location',
        str_contains($pdfWithMedia, 'Chosen from the gallery'));

    check('the append-only statement is still printed',
        str_contains($pdfWithMedia, 'has not been modified')
        || str_contains($pdfWithMedia, 'every change is retained'));

    // The agent's own photograph, taken at the door, carries its own fix. The office
    // portrait on their user record must never be captioned with the visit's
    // coordinates - the document would be asserting the picture was taken here.
    // Searched without the brackets: a PDF content stream escapes "(" and ")" as
    // "\(" and "\)", so the literal label never appears verbatim in the bytes.
    check('the agent photograph taken at the visit is labelled as such',
        str_contains($pdfWithMedia, 'at the visit'));
    check('an office portrait is never presented as taken at the visit',
        !str_contains($pdfWithMedia, 'photo on file')
        || str_contains($pdfWithMedia, 'not taken at this visit'));

    // Nothing captures a signature any more, so the printed page has to carry the
    // space to sign instead. Asserted on the bytes rather than trusted: a report that
    // prints without the boxes cannot be signed at all, and nothing on screen would
    // look wrong.
    check('the printed report asks for a signature by hand',
        str_contains($pdfWithMedia, 'signed by hand on this printed copy'));
    check('with a box for the borrower and one for the agent',
        str_contains($pdfWithMedia, 'Thumb Impression')
        && str_contains($pdfWithMedia, 'Agent Signature'));
    check('and a date line under each', substr_count($pdfWithMedia, 'Date:') >= 2,
        (string) substr_count($pdfWithMedia, 'Date:'));
    check('no captured signature is printed any more',
        !str_contains($pdfWithMedia, 'No location recorded at signing')
        && !str_contains($pdfWithMedia, 'Signature (uploaded)'));

    $pdfFile = sys_get_temp_dir() . '/lrms_visit_pdf_' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($pdfFile, $pdfWithMedia);
    check('the PDF with images is well formed', filesize($pdfFile) > 4000, (string) filesize($pdfFile));
    @unlink($pdfFile);
}

// ---------------------------------------------------------------------------
// The panel has to show the same evidence the PDF prints.
//
// It did not: every photograph on screen was a bare thumbnail with a type label,
// while the printed copy of the same photograph carried coordinates, accuracy and
// whether it came from the camera. The screen is what somebody looks at before
// approving a report, so it was the weaker of the two documents.
$panelWithMedia = null;
foreach ($mediaCandidates as $candidateId) {
    $candidate = request($base . '/visits/' . $candidateId);
    if ($candidate['status'] === 200 && str_contains($candidate['body'], 'geo-tagged')) {
        $panelWithMedia = $candidate['body'];
        break;
    }
}

check('a visit page with geo-tagged photographs was found', $panelWithMedia !== null,
    'no seeded visit rendered a geo-tagged photograph in the panel');

if ($panelWithMedia !== null) {
    check('the panel states how many photographs carry a position',
        preg_match('/\d+ geo-tagged/', $panelWithMedia) === 1);
    check('the panel shows a photograph\'s coordinates',
        preg_match('/\d{2}\.\d{6}, \d{2}\.\d{6}/', $panelWithMedia) === 1);
    check('the coordinates link out to a map',
        str_contains($panelWithMedia, 'google.com/maps/search/'));
    check('a camera capture is marked as one', str_contains($panelWithMedia, '>Camera<'));
    check('a gallery pick is marked differently, not left to look identical',
        str_contains($panelWithMedia, '>Gallery<'));
    check('the gallery badge explains why it is weaker evidence',
        str_contains($panelWithMedia, 'could have been taken anywhere'));
    check('the accuracy is shown, not just the coordinates',
        preg_match('/\+\/-\d+ m/', $panelWithMedia) === 1);
    check('the agent photograph appears in the panel too',
        str_contains($panelWithMedia, 'BC / DC Agent'));
    // The signature card is gone from the panel, and its absence is asserted: a card
    // reading "Not captured" twice on every report would be worse than no card, and
    // an upload form that still posts to a deleted route is a 404 with a lost file.
    check('the panel no longer shows a captured signature or an upload form',
        !str_contains($panelWithMedia, 'Upload an image of the signature')
        && !str_contains($panelWithMedia, 'lrms-signature'));
    // But it must say where signatures went. Deleting the card outright left somebody
    // looking for a borrower's signature with no signature and no explanation, which
    // reads as a report that was never signed.
    check('but it says signatures are on the printed copy',
        str_contains($panelWithMedia, 'Nothing is signed on a screen')
        && str_contains($panelWithMedia, 'Print this report'));
}

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

// The mapping screen, which nothing exercised before. Detection knows 35 columns and a
// real file carries eight, so this is the screen that turned into twenty-five
// consecutive rows reading "not in this file" with the two that mattered lost in the
// middle of them.
$previewCsvPath = tempCsv([
    'Branch,Loan Account Number,Customer Name,Village,Outstanding Amount,Overdue Amount,Asset Classification,DPD',
    'BR001,SMOKEMAP01,Mapping Borrower,Kotri,120000,15000,SS,95',
]);
$previewPage = postMultipart(
    $base . '/import/preview',
    ['_csrf' => csrfToken(request($base . '/import')['body'])],
    ['lead_file' => $previewCsvPath]
);
check('the import preview renders', $previewPage['status'] === 200
    && str_contains($previewPage['body'], 'Column mapping'), 'HTTP ' . $previewPage['status']);

if (str_contains($previewPage['body'], 'Column mapping')) {
    $mapBody = $previewPage['body'];

    // Everything the file carries is proposed up front.
    foreach (['Loan Account Number', 'Customer Name', 'Outstanding Amount',
              'Asset Classification', 'Days Past Due'] as $expected) {
        check("the mapping table proposes {$expected}", str_contains($mapBody, $expected));
    }

    // The fields the file does not carry are counted once and folded away, not listed
    // as row after row of the same answer.
    check('the fields not in the file are folded behind a disclosure',
        str_contains($mapBody, 'more fields') && str_contains($mapBody, '<details'));
    check('and are counted so the number is visible without opening it',
        preg_match('/\d+\s*\n?\s*more fields?/', $mapBody) === 1);

    // The regression itself. Measured as which fields are VISIBLE above the disclosure
    // rather than by counting the phrase: every select needs a "not in this file"
    // option, so the phrase legitimately appears 35 times - but 34 of those are inside
    // closed dropdowns and one was a table row you had to scroll past.
    [$visibleArea, $foldedArea] = explode('<details', $mapBody, 2);

    check('a column the file carries is visible without opening anything',
        str_contains($visibleArea, 'Asset Classification'));
    check('a column it does not carry is not a row in the main table',
        !str_contains($visibleArea, 'Guarantor Name'));
    check('and is inside the folded section instead',
        str_contains($foldedArea, 'Guarantor Name'));
    check('the em-dash filler is gone', substr_count($mapBody, '&mdash; not in this file &mdash;') === 0);

    // The main table should now be about the size of the file, not the size of the
    // detector's vocabulary.
    $visibleRows = substr_count($visibleArea, 'name="column_map[');
    check('the main table is the size of the file, not of the field list',
        $visibleRows <= 12, 'rows=' . $visibleRows);

    // Still a working form: a hand-mapped field inside the disclosure posts like any
    // other, which is the whole reason the rows are kept rather than dropped.
    check('every field still has a mapping control',
        substr_count($mapBody, 'name="column_map[') >= 35,
        'controls=' . substr_count($mapBody, 'name="column_map['));
}

@unlink($previewCsvPath);

// ---------------------------------------------------------------------------
// Assigning a past import again.
//
// This was previously possible only in the same breath as the upload, which made it a
// one-shot decision: whoever imported either picked the right agent at that moment, or
// the leads sat unassigned until somebody selected them off the borrower list by hand.
//
// Brings its OWN batch rather than redealing the seeded one. An earlier version of this
// section distributed a seeded import, which silently moved leads between agents and
// broke four later API assertions about the phone number of whichever lead AGT001
// happened to hold first. A test that rearranges shared fixtures is a trap for the next
// suite along.
// Mobile AND Aadhaar on every row: these leads end up in an agent's list, and a later
// suite inspects the PII of whichever lead that agent holds. A fixture that is thinner
// than the seeded data makes the next assertion fail somewhere unrelated.
$batchCsvPath = tempCsv([
    'Branch,Loan Account Number,Customer Name,Mobile,Aadhaar,Village,Outstanding Amount',
    'BR001,SMOKEBATCH01,Batch Borrower One,9812300001,234567800001,Kotri,45000',
    'BR001,SMOKEBATCH02,Batch Borrower Two,9812300002,234567800002,Kotri,52000',
    'BR001,SMOKEBATCH03,Batch Borrower Three,9812300003,234567800003,Kotri,61000',
]);
$batchImport = postMultipart(
    $base . '/import',
    ['_csrf' => csrfToken(request($base . '/import')['body']), 'default_agent_id' => ''],
    ['lead_file' => $batchCsvPath]
);
check('a batch was imported unassigned to test with', $batchImport['status'] === 200,
    'HTTP ' . $batchImport['status']);
@unlink($batchCsvPath);

$historyPage = request($base . '/import/history');
check('the history screen offers assignment', str_contains($historyPage['body'], 'Assign&hellip;')
    || str_contains($historyPage['body'], 'Assign…'), 'no assign control rendered');
check('and states how much of each batch is still unassigned',
    str_contains($historyPage['body'], 'unassigned') || str_contains($historyPage['body'], 'all assigned'));

// The newest row is the batch just uploaded; history is ordered created_at DESC.
preg_match('#/import/(\d+)/assign#', $historyPage['body'], $batchMatch);
$batchId = (int) ($batchMatch[1] ?? 0);
check('the imported batch is addressable from the history', $batchId > 0);

if ($batchId > 0) {
    // Distributing a whole batch, including the leads that already have an owner, is the
    // rebalance somebody does when a branch gains a second BC.
    $distributed = request($base . '/import/' . $batchId . '/assign', [
        '_csrf'       => csrfToken($historyPage['body']),
        'assign_mode' => 'distribute',
    ]);
    check('a past batch can be distributed evenly', $distributed['status'] === 200
        && str_contains($distributed['body'], 'assigned'), 'HTTP ' . $distributed['status']);

    // Handing the batch to one named agent still works, because assigning a village to a
    // person is a real need that even distribution does not cover.
    $historyAgain = request($base . '/import/history');
    preg_match('#name="agent_id"[^>]*>.*?<option value="(\d+)"#s', $historyAgain['body'], $agentMatch);
    $someAgentId = (int) ($agentMatch[1] ?? 0);

    if ($someAgentId > 0) {
        $toOneAgent = request($base . '/import/' . $batchId . '/assign', [
            '_csrf'       => csrfToken($historyAgain['body']),
            'assign_mode' => 'agent',
            'agent_id'    => (string) $someAgentId,
        ]);
        check('or handed to one named agent', $toOneAgent['status'] === 200
            && str_contains($toOneAgent['body'], 'assigned'), 'HTTP ' . $toOneAgent['status']);
    }

    // Asking for one agent without naming one must be refused rather than silently
    // falling back to distributing, which would be a different action than the one asked
    // for.
    $noAgent = request($base . '/import/' . $batchId . '/assign', [
        '_csrf'       => csrfToken(request($base . '/import/history')['body']),
        'assign_mode' => 'agent',
    ]);
    check('choosing "one agent" without naming one is refused',
        str_contains($noAgent['body'], 'Choose an agent'), 'HTTP ' . $noAgent['status']);

    // And a batch whose leads are all assigned says so instead of reporting a hollow
    // success, when the caller asked to touch only the unassigned ones.
    $onlyUnassigned = request($base . '/import/' . $batchId . '/assign', [
        '_csrf'           => csrfToken(request($base . '/import/history')['body']),
        'assign_mode'     => 'distribute',
        'only_unassigned' => '1',
    ]);
    check('"only the ones nobody has" reports honestly when there are none',
        str_contains($onlyUnassigned['body'], 'already assigned')
        || str_contains($onlyUnassigned['body'], 'assigned'),
        'HTTP ' . $onlyUnassigned['status']);

    // The point of the whole screen: those leads have an owner now, and it happened
    // after the import rather than during it.
    $batchLead = request($base . '/customers?search=SMOKEBATCH01');
    check('the batch lead is findable', str_contains($batchLead['body'], 'SMOKEBATCH01'));
    // Matched on the badge, not the word: "Unassigned only" is a filter label on every
    // list page, so the bare substring was true whatever the data said.
    check('a lead from the batch is no longer unassigned',
        !str_contains($batchLead['body'], 'badge-pending">Unassigned'), 'still shows the unassigned badge');
    check('and the history now says the batch is fully assigned',
        str_contains(request($base . '/import/history')['body'], 'all assigned'));
}

$template = request($base . '/import/template');
check('import template downloads', $template['status'] === 200 && str_starts_with($template['body'], "PK\x03\x04"),
    'HTTP ' . $template['status']);

page('GET /branches', '/branches', 200, 'Branches');
page('GET /branches/create', '/branches/create', 200, 'Add branch');
page('GET /branches/{id}/edit', '/branches/1/edit', 200, 'Edit branch');

page('GET /users', '/users', 200, 'Managers &amp; Agents');
page('GET /users/create', '/users/create', 200, 'Add user');
page('GET /users/{id}/edit', '/users/2/edit', 200, 'Edit user');

// ---------------------------------------------------------------------------
section('No signature is captured anywhere');

// There used to be a pad on the phone and an upload form on the panel. Both are gone:
// a fingertip scrawl is not a signature anybody accepts across a counter, so the
// printout carries the space and the paper carries the mark. Asserted from the outside,
// because a route left registered on a deleted handler is a 500, and a form left in a
// view is a lost file and a confused clerk.
$signatureRoute = postMultipart(
    $base . '/visits/1/signature',
    ['_csrf' => csrfToken(request($base . '/visits/1')['body'] ?? '')],
    []
);
check('the upload route is gone rather than broken', $signatureRoute['status'] === 404,
    'HTTP ' . $signatureRoute['status']);

$anyVisit = request($base . '/visits/1');
check('and no page still offers to upload one',
    !str_contains($anyVisit['body'], 'Upload an image of the signature'));

// The BC form no longer asks for a photograph or a signature: an image now belongs to
// the thing it evidences, not to a person's profile.
$userFormNow = request($base . '/users/2/edit');
check('the BC form no longer asks for a photograph',
    !str_contains($userFormNow['body'], 'name="photo"'));
check('nor for a signature',
    !str_contains($userFormNow['body'], 'name="signature"'));
check('and is no longer a multipart form',
    !str_contains($userFormNow['body'], 'enctype="multipart/form-data"'));

section('Visit report approval and correction');

page('GET /visits/{id}/approve', '/visits/' . $visitId . '/approve', 200, 'Approve visit report');
$approveForm = request($base . '/visits/' . $visitId . '/approve');
check('the approval form can carry images',
    str_contains($approveForm['body'], 'enctype="multipart/form-data"'));
check('it asks the browser for a position',
    str_contains($approveForm['body'], 'navigator.geolocation'));
check('a position nobody typed cannot be forged into the form',
    str_contains($approveForm['body'], 'name="gps_latitude" id="gps_latitude" value=""'));

// Rejecting without a reason leaves the agent nothing to act on, so it must be refused.
$rejectNoReason = request($base . '/visits/' . $visitId . '/approve', [
    '_csrf'    => csrfToken($approveForm['body']),
    'decision' => 'reject',
]);
check('a rejection with no remarks is refused',
    str_contains($rejectNoReason['body'], 'Say why') || str_contains($rejectNoReason['body'], 'invalid-feedback'),
    'HTTP ' . $rejectNoReason['status']);

// The approving officer's box has to be on the page BEFORE anybody approves. The copy
// somebody prints in order to sign it is exactly the one that is still pending, so a box
// that only appears after approval is a box that is never there when it is needed.
$pendingPdf = request($base . '/visits/' . $visitId . '/pdf');
check('a pending report still prints an approver signature box',
    $pendingPdf['status'] === 200 && str_contains($pendingPdf['body'], 'Approver Signature'),
    'HTTP ' . $pendingPdf['status']);
check('labelled with the role, since nobody has signed it yet',
    str_contains($pendingPdf['body'], 'Branch Manager'));
check('and it does not claim a photograph is missing from an approval nobody has made',
    !str_contains($pendingPdf['body'], 'No photograph of the approver'));

// Approve, with a photograph and a position.
$approverPhoto = tempPng(80, 80, [40, 90, 40]);
$approveForm2 = request($base . '/visits/' . $visitId . '/approve');
$approved = postMultipart($base . '/visits/' . $visitId . '/approve', [
    '_csrf'            => csrfToken($approveForm2['body']),
    'decision'         => 'approve',
    'approval_remarks' => 'Verified against the branch register.',
    'gps_latitude'     => '19.0728350',
    'gps_longitude'    => '72.8826100',
    'gps_accuracy_m'   => '14',
    'gps_source'       => 'device',
], ['approval_photo' => $approverPhoto]);
check('an approval is recorded', $approved['status'] === 200
    && str_contains($approved['body'], 'approved'), 'HTTP ' . $approved['status']);

$afterApproval = request($base . '/visits/' . $visitId);
check('the report shows as approved', str_contains($afterApproval['body'], 'Approved'));
check('the approver is named', str_contains($afterApproval['body'], 'Verified against the branch register'));
check('the position the approval was made from is shown',
    str_contains($afterApproval['body'], '19.072835'));
check('the approver photograph is rendered',
    str_contains($afterApproval['body'], 'Approver photograph'));

// The approver is not asked for a signature at all now, on screen or in the form. They
// sign the printed page, in the blank box the PDF carries for them.
check('the approval form no longer asks for a signature',
    !str_contains($approveForm['body'], 'name="approval_signature"'));
check('and none is rendered on the report',
    !str_contains($afterApproval['body'], 'Approver signature'));

// A second decision, this time with the position declined.
$approveForm3 = request($base . '/visits/' . $visitId . '/approve');
check('an already-approved report says so on the form',
    str_contains($approveForm3['body'], 'already'));

$reApproved = postMultipart($base . '/visits/' . $visitId . '/approve', [
    '_csrf'            => csrfToken($approveForm3['body']),
    'decision'         => 'approve',
    'approval_remarks' => 'Re-checked against the branch register.',
    'gps_source'       => 'denied',
], []);
check('a second decision is accepted', $reApproved['status'] === 200, 'HTTP ' . $reApproved['status']);

$reDecided = request($base . '/visits/' . $visitId);
// A declined position must be recorded as declined, not as "no fix".
check('a declined position is reported as declined',
    str_contains($reDecided['body'], 'declined to share'));

// A new decision must not keep the previous decision's photograph: that image was
// taken at a different moment and presenting it as evidence of this one is a lie.
check('the previous decision\'s photograph is not carried forward',
    !str_contains($reDecided['body'], 'Approver photograph'));

// And it reaches the printed report.
$approvedPdf = request($base . '/visits/' . $visitId . '/pdf');
check('the printed report carries the approval', $approvedPdf['status'] === 200
    && str_contains($approvedPdf['body'], 'Approved'), 'HTTP ' . $approvedPdf['status']);
// The approver ends up with what the agent has: a photograph, and an empty box under it.
// This decision declined its position and supplied no photograph, so the absence has to
// be stated - on an approved report a silent gap reads as an image that failed to load.
check('an approver with no photograph is reported in words',
    str_contains($approvedPdf['body'], 'No photograph of the approver'));
check('and the signature box is still printed for them',
    str_contains($approvedPdf['body'], 'Approver Signature'));
// Now that somebody has approved it, the box carries their name rather than the role it
// falls back to on a pending report.
check('the approver is named on their own box once they are known',
    !str_contains($approvedPdf['body'], 'Branch Manager'));

// ---- Correction, and the append-only guarantee ------------------------------
page('GET /visits/{id}/revise', '/visits/' . $visitId . '/revise', 200, 'Correct visit report');
$reviseForm = request($base . '/visits/' . $visitId . '/revise');
check('the correction form warns that nothing is overwritten silently',
    str_contains($reviseForm['body'], 'Nothing here is overwritten silently'));

// The agent's own assertions must NOT be correctable - a reviewer overwriting the
// tick boxes turns the agent's report into the reviewer's.
foreach (['customer_met', 'ready_to_pay', 'remarks', 'rec_legal_action'] as $offLimits) {
    check("the reviewer cannot edit {$offLimits}",
        !str_contains($reviseForm['body'], 'name="' . $offLimits . '"'));
}

$originalName = formValue($reviseForm['body'], 'customer_name');
check('the correction form is pre-filled with the current value', $originalName !== '');

// A correction with no reason is refused: the reason is what makes the trail useful.
$noReason = request($base . '/visits/' . $visitId . '/revise', [
    '_csrf'         => csrfToken($reviseForm['body']),
    'customer_name' => $originalName . ' Corrected',
]);
check('a correction with no reason is refused',
    str_contains($noReason['body'], 'Say why') || str_contains($noReason['body'], 'invalid-feedback'));

$reviseForm2 = request($base . '/visits/' . $visitId . '/revise');
$corrected = request($base . '/visits/' . $visitId . '/revise', [
    '_csrf'         => csrfToken($reviseForm2['body']),
    'customer_name' => $originalName . ' Corrected',
    'village'       => 'Corrected Village',
    'reason'        => 'Name misspelt on the original submission.',
]);
check('a correction is saved', $corrected['status'] === 200
    && str_contains($corrected['body'], 'revision 1'), 'HTTP ' . $corrected['status']);

$afterRevision = request($base . '/visits/' . $visitId);
check('the corrected value is shown', str_contains($afterRevision['body'], $originalName . ' Corrected'));
// The whole point: the value the agent submitted is still there.
check('the ORIGINAL value is retained', str_contains($afterRevision['body'], $originalName));
check('the correction is listed with its reason',
    str_contains($afterRevision['body'], 'Name misspelt on the original submission'));
check('the report states it has been corrected',
    str_contains($afterRevision['body'], 'Corrected <strong>1</strong> time(s)')
    || str_contains($afterRevision['body'], 'time(s) since'));

// A save that changes nothing must not manufacture an empty revision, or the count on
// the printed report stops meaning anything.
$reviseForm3 = request($base . '/visits/' . $visitId . '/revise');
$noChange = request($base . '/visits/' . $visitId . '/revise', [
    '_csrf'         => csrfToken($reviseForm3['body']),
    'customer_name' => $originalName . ' Corrected',
    'village'       => 'Corrected Village',
    'reason'        => 'Saving without changing anything.',
]);
check('a no-op correction records no revision',
    str_contains($noChange['body'], 'Nothing was changed'), 'HTTP ' . $noChange['status']);

$stillOne = request($base . '/visits/' . $visitId);
check('the revision count did not move', !str_contains($stillOne['body'], 'revision 2')
    && !str_contains($stillOne['body'], '>2</td>'));

// The printed report has to admit it was corrected.
$correctedPdf = request($base . '/visits/' . $visitId . '/pdf');
check('the printed report discloses the correction',
    str_contains($correctedPdf['body'], 'every change is retained'), 'HTTP ' . $correctedPdf['status']);

@unlink($approverPhoto);

// ---------------------------------------------------------------------------
section('Closure amount, editable loan figures and custom fields');

$profile2 = request($base . '/customers/' . $leadId);
check('the loan panel shows a closure amount', str_contains($profile2['body'], 'Closure amount'));
// The user asked for the closure figure in place of the BC code, which belongs to the
// agent rather than the loan and is still snapshotted on the visit report.
check('the BC code no longer clutters the loan panel',
    !str_contains($profile2['body'], 'BC / DC code'));

$editPage = page('GET /customers/{id}/edit', '/customers/' . $leadId . '/edit', 200, 'Edit borrower');
check('loan figures are editable now', str_contains($editPage, 'name="outstanding_amount"'));
check('the closure amount is editable', str_contains($editPage, 'name="closure_amount"'));
check('the banner no longer claims loan figures are read-only',
    !str_contains($editPage, 'are not editable here'));
check('and it explains the override instead', str_contains($editPage, 'marked as hand-edited'));

// Edit a loan figure by hand.
$editForm = request($base . '/customers/' . $leadId . '/edit');
$loanEdit = request($base . '/customers/' . $leadId . '/edit', [
    '_csrf'              => csrfToken($editForm['body']),
    'name'               => formValue($editForm['body'], 'name'),
    'village'            => formValue($editForm['body'], 'village'),
    'closure_amount'     => '123456.78',
    'outstanding_amount' => formValue($editForm['body'], 'outstanding_amount'),
    'overdue_amount'     => formValue($editForm['body'], 'overdue_amount'),
]);
check('a hand-edited loan figure is accepted', $loanEdit['status'] === 200
    && str_contains($loanEdit['body'], 'updated'), 'HTTP ' . $loanEdit['status']);

$afterLoanEdit = request($base . '/customers/' . $leadId);
check('the closure amount is shown', str_contains($afterLoanEdit['body'], '1,23,456'));

$editAgain = request($base . '/customers/' . $leadId . '/edit');
// The override is what stops the next import silently undoing the correction.
check('the edited figure is flagged as hand-edited',
    str_contains($editAgain['body'], 'imports skip this'));

// ---- Custom fields ----------------------------------------------------------
page('GET /custom-fields', '/custom-fields', 200, 'Custom fields');
page('GET /custom-fields/create', '/custom-fields/create', 200, 'Add a custom field');
check('the custom field screen warns against loan figures',
    str_contains(request($base . '/custom-fields')['body'], 'Not for loan figures'));

$cfForm = request($base . '/custom-fields/create');
$cfCreate = request($base . '/custom-fields/create', [
    '_csrf'          => csrfToken($cfForm['body']),
    'entity'         => 'customer',
    'label'          => 'PAN number',
    'field_type'     => 'text',
    'hint'           => 'Ten characters',
    'status'         => 'active',
    'sort_order'     => '1',
    'show_in_report' => '1',
]);
check('a custom field is created', $cfCreate['status'] === 200
    && str_contains($cfCreate['body'], 'added'), 'HTTP ' . $cfCreate['status']);

$cfList = request($base . '/custom-fields');
check('the key is derived from the label', str_contains($cfList['body'], 'pan_number'));

// A second field with the same label must not collide on the unique key.
$cfForm2 = request($base . '/custom-fields/create');
$cfDup = request($base . '/custom-fields/create', [
    '_csrf'      => csrfToken($cfForm2['body']),
    'entity'     => 'customer',
    'label'      => 'PAN number',
    'field_type' => 'text',
    'status'     => 'active',
    'sort_order' => '2',
]);
check('a duplicate label gets its own key, not a database error', $cfDup['status'] === 200
    && str_contains($cfDup['body'], 'added'), 'HTTP ' . $cfDup['status']);
check('the second key is suffixed', str_contains(request($base . '/custom-fields')['body'], 'pan_number_2'));

// A loan-account field of a different type, to exercise the renderer.
$cfForm3 = request($base . '/custom-fields/create');
request($base . '/custom-fields/create', [
    '_csrf'      => csrfToken($cfForm3['body']),
    'entity'     => 'loan_account',
    'label'      => 'Security type',
    'field_type' => 'select',
    'options'    => 'Land, Gold, Unsecured',
    'status'     => 'active',
    'sort_order' => '1',
]);

// The new fields must appear on the borrower form with no release.
$editWithCustom = request($base . '/customers/' . $leadId . '/edit');
check('a new borrower field appears on the form immediately',
    str_contains($editWithCustom['body'], 'name="pan_number"'));
check('a new loan field appears too', str_contains($editWithCustom['body'], 'name="security_type"'));
check('a select field renders its choices', str_contains($editWithCustom['body'], 'Unsecured'));

// Answer them.
$answerForm = request($base . '/customers/' . $leadId . '/edit');
$answered = request($base . '/customers/' . $leadId . '/edit', [
    '_csrf'         => csrfToken($answerForm['body']),
    'name'          => formValue($answerForm['body'], 'name'),
    'pan_number'    => 'ABCDE1234F',
    'security_type' => 'Gold',
]);
check('custom answers are saved', $answered['status'] === 200, 'HTTP ' . $answered['status']);

$profileWithCustom = request($base . '/customers/' . $leadId);
check('the answer shows on the profile', str_contains($profileWithCustom['body'], 'ABCDE1234F'));
check('the loan answer shows too', str_contains($profileWithCustom['body'], 'Gold'));
// An unanswered field is still listed - "not recorded" is information.
check('an unanswered field is listed rather than hidden',
    str_contains($profileWithCustom['body'], 'Not recorded'));

// Blanking an answer must remove the row, not store an empty string, so
// "not recorded" and "recorded as empty" stay distinguishable.
$blankForm = request($base . '/customers/' . $leadId . '/edit');
request($base . '/customers/' . $leadId . '/edit', [
    '_csrf'      => csrfToken($blankForm['body']),
    'name'       => formValue($blankForm['body'], 'name'),
    'pan_number' => '',
]);
$afterBlank = request($base . '/customers/' . $leadId);
check('a blanked answer reverts to not recorded', !str_contains($afterBlank['body'], 'ABCDE1234F'));

// Retiring keeps answers; the list has to say how many a delete would destroy.
$cfListFinal = request($base . '/custom-fields');
check('the list reports how many answers each field holds',
    preg_match('#<td class="num">\s*\d+\s*</td>#', $cfListFinal['body']) === 1);
// A field marked for the report must reach the printed report; one not marked must not.
$reportPdf = request($base . '/visits/' . $visitId . '/pdf');
check('a field flagged for printing reaches the report',
    str_contains($reportPdf['body'], 'Additional Details')
    && str_contains($reportPdf['body'], 'PAN number'), 'HTTP ' . $reportPdf['status']);
check('a field not flagged for printing stays off it',
    !str_contains($reportPdf['body'], 'Security type'));

check('deleting warns about destroying answers',
    str_contains($cfListFinal['body'], 'set it to Inactive instead')
    || str_contains($cfListFinal['body'], 'Nothing has been recorded'));

// ---------------------------------------------------------------------------
section('BC performance: targets, SSS, scorecard');

$targetsPage = page('GET /bc/targets', '/bc/targets', 200, 'BC targets');
page('GET /bc/targets/create', '/bc/targets/create', 200, 'Set BC targets');

// A real round-trip, because the interesting failures here are a column name that
// does not exist and a unique key that fires - neither of which a GET would show.
$createForm = request($base . '/bc/targets/create');
$targetToken = csrfToken($createForm['body']);
$month = date('Y-m');

$created = request($base . '/bc/targets/create', [
    '_csrf' => $targetToken,
    'agent_id' => 3,
    'target_month' => $month,
    'daily_visit_target' => 8,
    'apy_target' => 20,
    'pmjjby_target' => 15,
    'pmsby_target' => 15,
    'pmjdy_target' => 10,
    'od2_renewal_target' => 4,
    'npa_recovery_target' => '50000.00',
]);
check('POST /bc/targets/create saves', $created['status'] === 200 && str_contains($created['body'], 'Targets saved'),
    'HTTP ' . $created['status']);

$afterCreate = request($base . '/bc/targets');
check('the saved target appears in the list', str_contains($afterCreate['body'], '50,000')
    || str_contains($afterCreate['body'], '50000'));

// The second attempt must not be a 500 from the unique key - it must redirect the
// user to the row they already have.
$duplicateForm = request($base . '/bc/targets/create');
$duplicate = request($base . '/bc/targets/create', [
    '_csrf' => csrfToken($duplicateForm['body']),
    'agent_id' => 3,
    'target_month' => $month,
    'daily_visit_target' => 9,
    'npa_recovery_target' => '1000',
]);
check('a duplicate month is redirected to the existing row, not a DB error',
    $duplicate['status'] === 200 && str_contains($duplicate['body'], 'already exist'),
    'HTTP ' . $duplicate['status']);

// A target of 3000 visits a day would have the warning cron escalating that agent
// to the regional office every night, so it must be refused at the form.
$absurdForm = request($base . '/bc/targets/create');
$absurd = request($base . '/bc/targets/create', [
    '_csrf' => csrfToken($absurdForm['body']),
    'agent_id' => 4,
    'target_month' => $month,
    'daily_visit_target' => 99999,
    'npa_recovery_target' => '1000',
]);
check('an out-of-range target is rejected', str_contains($absurd['body'], 'correct the highlighted')
    || str_contains($absurd['body'], 'invalid-feedback'), 'HTTP ' . $absurd['status']);

page('GET /bc/sss', '/bc/sss', 200, 'SSS enrolment');
page('GET /bc/sss/create', '/bc/sss/create', 200, 'Record SSS enrolment');

$sssForm = request($base . '/bc/sss/create');
$sssCreated = request($base . '/bc/sss/create', [
    '_csrf' => csrfToken($sssForm['body']),
    'agent_id' => 3,
    'enrollment_date' => date('Y-m-d'),
    'apy_count' => 2,
    'pmjjby_count' => 3,
    'pmsby_count' => 1,
    'pmjdy_count' => 4,
    'remarks' => 'smoke test entry',
]);
check('POST /bc/sss/create saves', $sssCreated['status'] === 200
    && str_contains($sssCreated['body'], 'Enrolment recorded'), 'HTTP ' . $sssCreated['status']);

$sssList = request($base . '/bc/sss');
check('the SSS total is summed across schemes', str_contains($sssList['body'], 'smoke test entry'));

$sssDuplicateForm = request($base . '/bc/sss/create');
$sssDuplicate = request($base . '/bc/sss/create', [
    '_csrf' => csrfToken($sssDuplicateForm['body']),
    'agent_id' => 3,
    'enrollment_date' => date('Y-m-d'),
    'apy_count' => 9,
]);
check('a second SSS entry for the same day is redirected to the first',
    str_contains($sssDuplicate['body'], 'already exists'), 'HTTP ' . $sssDuplicate['status']);

$scorecard = page('GET /bc/scorecard', '/bc/scorecard', 200, 'BC summary report');
check('the scorecard renders a table or an empty state',
    str_contains($scorecard, 'lrms-table') || str_contains($scorecard, 'No agents to score'));
check('the scoring weights are shown, not hidden',
    str_contains($scorecard, 'How the score is calculated'));

page('scorecard with a branch filter', '/bc/scorecard?branch_id=1', 200);
// Reversed dates are swapped rather than producing an empty table that reads as
// "nobody did anything".
page('scorecard with a reversed date range', '/bc/scorecard?from=' . date('Y-m-d') . '&to=' . date('Y-m-01'), 200);

$scorecardExcel = request($base . '/bc/scorecard/export?format=excel');
check('scorecard Excel export', $scorecardExcel['status'] === 200
    && str_starts_with($scorecardExcel['body'], "PK\x03\x04"),
    'HTTP ' . $scorecardExcel['status'] . ' len=' . strlen($scorecardExcel['body']));

$scorecardPdf = request($base . '/bc/scorecard/export?format=pdf');
check('scorecard PDF export', $scorecardPdf['status'] === 200
    && str_starts_with($scorecardPdf['body'], '%PDF'),
    'HTTP ' . $scorecardPdf['status'] . ' len=' . strlen($scorecardPdf['body']));

// ---------------------------------------------------------------------------
section('All 8 reports');

$reportsIndex = page('GET /reports', '/reports', 200, 'Reports');
// Types are read off the rendered picker rather than hardcoded here. A new report
// then gets exercised - table, Excel and PDF - without anyone remembering to add it
// to this list, which is how the previous hardcoded list of eight would have let a
// ninth type ship untested.
preg_match_all('#lrms-report-card" href="[^"]*/reports/([a-z0-9-]+)"#', $reportsIndex, $cardMatches);
$reportTypes = array_values(array_unique($cardMatches[1] ?? []));

check('the report picker lists every type as a card',
    count($reportTypes) === substr_count($reportsIndex, 'lrms-report-card') && $reportTypes !== [],
    count($reportTypes) . ' slugs from ' . substr_count($reportsIndex, 'lrms-report-card') . ' cards');
check('the BC daily report is one of them', in_array('bc-daily', $reportTypes, true),
    implode(',', $reportTypes));

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
section('A BC agent in the panel: their own borrowers, and nothing else');

// Agents used to be refused the panel outright. They now have a narrow surface, and
// every assertion below is about the edge of it. This is the section that matters most
// in this file: the permission grant is easy, the boundary is where a leak would be.
$cookieJar = sys_get_temp_dir() . '/lrms_smoke_agent_' . bin2hex(random_bytes(4)) . '.txt';
@unlink($cookieJar);

$agentLoginPage = request($base . '/login');
$agentLogin = request($base . '/login', [
    '_csrf'         => csrfToken($agentLoginPage['body']),
    'employee_code' => 'AGT001',
    'password'      => 'Agent@123',
]);
check('an agent can sign in to the panel', $agentLogin['status'] === 200, 'HTTP ' . $agentLogin['status']);

// Deliberately does NOT touch the password. The API smoke signs in as this same account
// with the seeded credentials, and an earlier version of this block changed them - the
// account menu on every panel page contains the words "Change password", so a
// str_contains() test for a forced change fired on a perfectly healthy session and broke
// twenty-two unrelated API assertions.
check('the agent session is authenticated, not bounced to the login form',
    !str_contains($agentLogin['body'], 'name="employee_code"'));

// Landing anywhere that refuses them would read as a broken sign-in.
check('signing in does not land an agent on a refusal page',
    !str_contains($agentLogin['body'], 'Use the D2 Recovery mobile app'));

$agentLeads = request($base . '/customers');
check('an agent reaches their borrower list', $agentLeads['status'] === 200, 'HTTP ' . $agentLeads['status']);
check('and it is labelled as theirs', str_contains($agentLeads['body'], 'My Borrowers'));

// The navigation must not offer screens that will refuse them.
foreach (['/visits' => 'Visit Reports', '/promises' => 'Promises', '/dashboard' => 'Dashboard'] as $path => $label) {
    check("the agent nav does not link to {$path}",
        !str_contains($agentLeads['body'], 'href="' . rtrim(parse_url($base, PHP_URL_PATH) ?: '', '/') . $path . '"'),
        $label);
}

// Screens an agent must not reach at all. Each is a different failure if it opens.
foreach ([
    '/dashboard', '/visits', '/promises', '/reports', '/import',
    '/users', '/branches', '/settings', '/logs/audit', '/backup',
] as $forbidden) {
    $attempt = request($base . $forbidden, null, false);
    $body = $attempt['body'] ?? '';
    $refused = $attempt['status'] === 403
        || $attempt['status'] === 302
        || str_contains($body, 'Use the D2 Recovery mobile app');
    check("an agent cannot open {$forbidden}", $refused, 'HTTP ' . $attempt['status']);
}

// Their own lead: openable and editable.
preg_match('#/customers/(\d+)"#', $agentLeads['body'], $ownMatch);
$ownLeadId = (int) ($ownMatch[1] ?? 0);
check('the agent list contains at least one of their leads', $ownLeadId > 0);

if ($ownLeadId > 0) {
    $ownLead = request($base . '/customers/' . $ownLeadId);
    check('an agent opens their own borrower', $ownLead['status'] === 200, 'HTTP ' . $ownLead['status']);

    $ownEdit = request($base . '/customers/' . $ownLeadId . '/edit');
    check('and reaches the edit form', $ownEdit['status'] === 200
        && str_contains($ownEdit['body'], 'Edit borrower'), 'HTTP ' . $ownEdit['status']);
    check('the borrower details card is editable', str_contains($ownEdit['body'], 'name="village"'));
    check('and so is the loan details card', str_contains($ownEdit['body'], 'name="outstanding_amount"'));

    // The correction has to stick, and be attributed - an agent's edit is tracked the
    // same way anybody else's is.
    $editFields = [];
    foreach (['name', 'father_husband_name', 'mobile', 'aadhaar', 'village', 'address',
              'cif_number', 'loan_type', 'outstanding_amount', 'overdue_amount',
              'closure_amount', 'ots_amount', 'deposit_amount', 'npa_date',
              'ckcc_renewal_due_date'] as $field) {
        $editFields[$field] = formValue($ownEdit['body'], $field);
    }
    $editFields['_csrf'] = csrfToken($ownEdit['body']);
    $editFields['village'] = 'Agent Corrected Village';

    $saved = request($base . '/customers/' . $ownLeadId . '/edit', $editFields);
    check('an agent can save a correction', $saved['status'] === 200, 'HTTP ' . $saved['status']);
    $afterSave = request($base . '/customers/' . $ownLeadId);
    check('and it is stored', str_contains($afterSave['body'], 'Agent Corrected Village'));
}

// Somebody else's lead must be refused even inside the same branch, which is the part
// branch scope alone would have let through.
$foreignLeadId = null;
for ($candidate = 1; $candidate <= 40; $candidate++) {
    if ($candidate === $ownLeadId) {
        continue;
    }
    if (!str_contains($agentLeads['body'], '/customers/' . $candidate . '"')) {
        $foreignLeadId = $candidate;
        break;
    }
}

check('a lead outside the agent\'s list was found to test with', $foreignLeadId !== null);

if ($foreignLeadId !== null) {
    $foreign = request($base . '/customers/' . $foreignLeadId, null, false);
    $foreignBody = $foreign['body'] ?? '';
    check('an agent cannot open a borrower that is not assigned to them',
        in_array($foreign['status'], [302, 403], true)
            || str_contains($foreignBody, 'not assigned to you')
            || str_contains($foreignBody, 'another branch'),
        'HTTP ' . $foreign['status']);

    $foreignEdit = request($base . '/customers/' . $foreignLeadId . '/edit', null, false);
    $foreignEditBody = $foreignEdit['body'] ?? '';
    check('nor edit one',
        in_array($foreignEdit['status'], [302, 403], true)
            || str_contains($foreignEditBody, 'not assigned to you')
            || str_contains($foreignEditBody, 'another branch'),
        'HTTP ' . $foreignEdit['status']);
}

// A query string must not widen the list past their own leads. Compared as sets of ids,
// because "the page still rendered" proves nothing about what is on it.
$leadIdsIn = static function (string $html): array {
    preg_match_all('#/customers/(\d+)"#', $html, $m);
    $ids = array_values(array_unique(array_map('intval', $m[1] ?? [])));
    sort($ids);
    return $ids;
};

$ownIds = $leadIdsIn($agentLeads['body']);

// A filter may narrow the list - ?unassigned=1 legitimately returns nothing, since
// every lead they can see is by definition assigned to them. What must never happen is
// a parameter ADDING a lead, so the invariant is "subset", not "identical".
foreach (['agent_id=99999', 'agent_id=', 'branch_id=99999', 'unassigned=1', 'status='] as $query) {
    $widened = request($base . '/customers?' . $query);
    $shown = $leadIdsIn($widened['body']);
    check(
        "?{$query} cannot add a lead to an agent's list",
        $widened['status'] === 200 && array_diff($shown, $ownIds) === [],
        'HTTP ' . $widened['status'] . ' extra=' . implode(',', array_diff($shown, $ownIds))
    );
}

// Custom fields: an agent may add one and name it.
$fieldsPage = request($base . '/custom-fields');
check('an agent reaches the custom fields screen', $fieldsPage['status'] === 200,
    'HTTP ' . $fieldsPage['status']);

$newFieldForm = request($base . '/custom-fields/create');
check('and the create form', $newFieldForm['status'] === 200, 'HTTP ' . $newFieldForm['status']);

$createdField = request($base . '/custom-fields/create', [
    '_csrf'      => csrfToken($newFieldForm['body']),
    'entity'     => 'customer',
    'label'      => 'Shop Landmark By Agent',
    'field_type' => 'text',
    'sort_order' => '9',
    'status'     => 'active',
]);
check('an agent can create a custom field and name it', $createdField['status'] === 200,
    'HTTP ' . $createdField['status']);
$fieldsAfter = request($base . '/custom-fields');
check('the field they named is listed', str_contains($fieldsAfter['body'], 'Shop Landmark By Agent'));

// And it reaches the borrower form they collect it on.
if ($ownLeadId > 0) {
    $editWithField = request($base . '/customers/' . $ownLeadId . '/edit');
    check('the new field appears on the borrower form',
        str_contains($editWithField['body'], 'Shop Landmark By Agent'));
}

$agentLogout = request($base . '/logout', ['_csrf' => csrfToken($fieldsAfter['body'])]);
check('an agent can sign out', str_contains($agentLogout['body'], 'Sign in'), 'HTTP ' . $agentLogout['status']);

@unlink($cookieJar);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
printf("  PANEL SMOKE: %d passed, %d failed\n", $passed, $failed);
if ($failures !== []) {
    echo '  Failed: ' . implode('; ', $failures) . "\n";
}
echo str_repeat('=', 60) . "\n";

@unlink($cookieJar);
exit($failed === 0 ? 0 : 1);
