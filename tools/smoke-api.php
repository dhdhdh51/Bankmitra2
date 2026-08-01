<?php
/**
 * Exercises the REST API over HTTP exactly as the Android app will.
 *
 * Driven by tools/smoke-panel.sh after the panel checks. Verifies the response
 * envelope, JWT auth, agent scoping, append-only + idempotent visit submission,
 * pagination, encrypted-field search and the report exports.
 */

declare(strict_types=1);

$base = rtrim(getenv('LRMS_BASE') ?: 'http://127.0.0.1:8099', '/') . '/api/v1';

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
 * @param array<string,mixed>|null $payload
 * @return array{status:int, json:array<string,mixed>|null, raw:string}
 */
function api(string $method, string $path, ?array $payload = null, ?string $token = null, bool $asJson = true): array
{
    global $base;

    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($payload !== null) {
        if ($asJson) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($payload));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $raw];
}

/**
 * Fetches a binary download, keeping the response headers.
 *
 * The JSON helper above throws the headers away, but for a file download the
 * Content-Disposition is part of the contract: without it a browser or the app
 * renders the bytes instead of saving them.
 *
 * @return array{status:int, headers:string, body:string}
 */
function apiDownload(string $path, ?string $token = null): array
{
    global $base;

    $ch = curl_init($base . $path);
    $headers = ['Accept: application/pdf'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body'    => substr($raw, $headerSize),
    ];
}

/** Every JSON response must carry the documented envelope. */
function hasEnvelope(?array $json): bool
{
    return $json !== null
        && array_key_exists('success', $json)
        && array_key_exists('data', $json)
        && array_key_exists('message', $json);
}

// ---------------------------------------------------------------------------
section('Public endpoints');

$ping = api('GET', '/ping');
check('GET /ping returns 200', $ping['status'] === 200, 'HTTP ' . $ping['status']);
check('ping uses the response envelope', hasEnvelope($ping['json']), $ping['raw']);
check('ping reports the app version', !empty($ping['json']['data']['app_version']));
check('ping reports api version v1', ($ping['json']['data']['api_version'] ?? '') === 'v1');

$unauth = api('GET', '/leads');
check('protected endpoint returns 401 without a token', $unauth['status'] === 401, 'HTTP ' . $unauth['status']);
check('401 uses the envelope', hasEnvelope($unauth['json']));
check('401 sets success=false', ($unauth['json']['success'] ?? true) === false);

$badToken = api('GET', '/leads', null, 'not-a-real-token');
check('garbage bearer token is rejected', $badToken['status'] === 401, 'HTTP ' . $badToken['status']);

// ---------------------------------------------------------------------------
section('Authentication');

$badLogin = api('POST', '/auth/login', ['employee_code' => 'AGT001', 'password' => 'wrong']);
check('wrong password returns 401', $badLogin['status'] === 401, 'HTTP ' . $badLogin['status']);
check('wrong password does not leak a token', empty($badLogin['json']['data']['access_token']));

$missing = api('POST', '/auth/login', ['employee_code' => 'AGT001']);
check('missing password returns 422', $missing['status'] === 422, 'HTTP ' . $missing['status']);
check('422 includes field errors', isset($missing['json']['data']['errors']['password']));

$login = api('POST', '/auth/login', [
    'employee_code' => 'AGT001',
    'password'      => 'Agent@123',
    'device_token'  => 'smoke-device-token-001',
    'app_version'   => '1.0.0',
]);
check('agent login returns 200', $login['status'] === 200, 'HTTP ' . $login['status'] . ' ' . $login['raw']);
check('login returns an access token', !empty($login['json']['data']['access_token']));
check('login returns a refresh token', !empty($login['json']['data']['refresh_token']));
check('login reports token_type Bearer', ($login['json']['data']['token_type'] ?? '') === 'Bearer');
check('login returns the user', ($login['json']['data']['user']['employee_code'] ?? '') === 'AGT001');
check('login reports the agent role', ($login['json']['data']['user']['role'] ?? '') === 'agent');
check('login returns permissions', is_array($login['json']['data']['user']['permissions'] ?? null));
check('agent may create visits', in_array('visits.create', (array) ($login['json']['data']['user']['permissions'] ?? []), true));
check('login never returns a password hash', !str_contains($login['raw'], 'password_hash'));

$agentToken = (string) ($login['json']['data']['access_token'] ?? '');
$refreshToken = (string) ($login['json']['data']['refresh_token'] ?? '');

$me = api('GET', '/auth/me', null, $agentToken);
check('GET /auth/me works with the token', $me['status'] === 200, 'HTTP ' . $me['status']);
check('me returns the same user', ($me['json']['data']['user']['employee_code'] ?? '') === 'AGT001');

$refresh = api('POST', '/auth/refresh', ['refresh_token' => $refreshToken]);
check('refresh returns a new token pair', $refresh['status'] === 200 && !empty($refresh['json']['data']['access_token']),
    'HTTP ' . $refresh['status']);

$newRefresh = (string) ($refresh['json']['data']['refresh_token'] ?? '');
check('refresh token is rotated', $newRefresh !== '' && $newRefresh !== $refreshToken);

$reuse = api('POST', '/auth/refresh', ['refresh_token' => $refreshToken]);
check('the old refresh token is single-use', $reuse['status'] === 401, 'HTTP ' . $reuse['status']);

$forgot = api('POST', '/auth/forgot-password', ['employee_code' => 'NOBODY999']);
check('forgot-password does not reveal unknown accounts',
    $forgot['status'] === 200 && ($forgot['json']['data']['otp_sent'] ?? true) === false,
    'HTTP ' . $forgot['status']);

// ---------------------------------------------------------------------------
section('Metadata & dashboard');

$meta = api('GET', '/meta', null, $agentToken);
check('GET /meta returns 200', $meta['status'] === 200, 'HTTP ' . $meta['status']);
check('meta returns villages', is_array($meta['json']['data']['villages'] ?? null));
check('meta returns loan types', is_array($meta['json']['data']['loan_types'] ?? null));
check('meta returns statuses', in_array('pending', (array) ($meta['json']['data']['statuses'] ?? []), true));
check('agents do not receive the branch list', ($meta['json']['data']['branches'] ?? null) === []);

$dashboard = api('GET', '/dashboard', null, $agentToken);
check('GET /dashboard returns 200', $dashboard['status'] === 200, 'HTTP ' . $dashboard['status']);
check('agent dashboard has lead counters', isset($dashboard['json']['data']['leads']['total']));
check('agent dashboard has visit counters', isset($dashboard['json']['data']['visits']['total']));
check('agent dashboard has promise counters', isset($dashboard['json']['data']['promises']['pending']));

$options = api('GET', '/visits/form-options', null, $agentToken);
check('GET /visits/form-options returns 200', $options['status'] === 200, 'HTTP ' . $options['status']);
check('form options list occupations', count((array) ($options['json']['data']['occupations'] ?? [])) === 6);
check('form options list contact flags', count((array) ($options['json']['data']['contact_flags'] ?? [])) === 5);
check('form options list reason flags', count((array) ($options['json']['data']['reason_flags'] ?? [])) === 8);
check('form options list recommendations', count((array) ($options['json']['data']['recommendation_flags'] ?? [])) === 6);

// ---------------------------------------------------------------------------
section('Assigned leads (agent scope)');

$leads = api('GET', '/leads?per_page=5', null, $agentToken);
check('GET /leads returns 200', $leads['status'] === 200, 'HTTP ' . $leads['status']);
check('leads payload is a list', is_array($leads['json']['data'] ?? null));
check('leads include pagination meta', isset($leads['json']['meta']['current_page'], $leads['json']['meta']['total']));
check('per_page is honoured', count((array) $leads['json']['data']) <= 5);
check('leads include status counts', isset($leads['json']['status_counts']));

$leadList = (array) $leads['json']['data'];
check('agent has assigned leads', $leadList !== []);

$firstLead = $leadList[0] ?? [];
check('lead exposes the account number', !empty($firstLead['loan_account_number']));
check('lead exposes outstanding as a number', is_float($firstLead['outstanding_amount'] ?? null) || is_int($firstLead['outstanding_amount'] ?? null));
check('lead exposes is_npa as a boolean', is_bool($firstLead['is_npa'] ?? null));
check('lead exposes masked aadhaar', array_key_exists('aadhaar_masked', $firstLead));

// Every lead returned to an agent must be assigned to that agent.
$agentId = (int) ($login['json']['data']['user']['id'] ?? 0);
$allMine = true;
foreach ($leadList as $lead) {
    if ((int) ($lead['assigned_agent_id'] ?? 0) !== $agentId) {
        $allMine = false;
        break;
    }
}
check('agent only receives their own assigned leads', $allMine);

$filtered = api('GET', '/leads?status=pending', null, $agentToken);
check('status filter works', $filtered['status'] === 200);
$statusesOk = true;
foreach ((array) $filtered['json']['data'] as $lead) {
    if (($lead['current_status'] ?? '') !== 'pending') {
        $statusesOk = false;
        break;
    }
}
check('status filter returns only that status', $statusesOk);

check('npa filter works', api('GET', '/leads?npa_only=1', null, $agentToken)['status'] === 200);
check('sorting works', api('GET', '/leads?sort_by=outstanding_amount&sort_dir=asc', null, $agentToken)['status'] === 200);
check('sort injection is ignored safely',
    api('GET', '/leads?sort_by=id;DROP+TABLE+users', null, $agentToken)['status'] === 200);

// ---------------------------------------------------------------------------
section('Search');

$shortTerm = api('GET', '/leads/search?q=a', null, $agentToken);
check('search rejects a 1-character term', $shortTerm['status'] === 422, 'HTTP ' . $shortTerm['status']);

$accountNumber = (string) ($firstLead['loan_account_number'] ?? '');
$byAccount = api('GET', '/leads/search?q=' . urlencode($accountNumber), null, $agentToken);
check('search by loan account number', $byAccount['status'] === 200 && count((array) $byAccount['json']['data']) >= 1,
    'HTTP ' . $byAccount['status']);

$byName = api('GET', '/leads/search?q=' . urlencode(substr((string) ($firstLead['customer_name'] ?? 'Ram'), 0, 4)), null, $agentToken);
check('search by customer name', $byName['status'] === 200);

$byVillage = api('GET', '/leads/search?q=Kotri&scope=branch', null, $agentToken);
check('branch-scoped search by village', $byVillage['status'] === 200);

// The mobile column is encrypted; search must still find it via the HMAC.
$mobileMasked = (string) ($firstLead['mobile_masked'] ?? '');
$lastFour = substr($mobileMasked, -4);
$byMobile = api('GET', '/leads/search?q=98765' . $lastFour . '&scope=branch', null, $agentToken);
check('search accepts a full mobile number', $byMobile['status'] === 200, 'HTTP ' . $byMobile['status']);

// ---------------------------------------------------------------------------
section('Customer profile');

$leadId = (int) ($firstLead['id'] ?? 0);
$profile = api('GET', '/customers/' . $leadId, null, $agentToken);
check('GET /customers/{id} returns 200', $profile['status'] === 200, 'HTTP ' . $profile['status']);
foreach (['lead', 'promises', 'visits', 'timeline', 'photos', 'documents', 'signatures', 'other_accounts'] as $key) {
    check("profile includes {$key}", array_key_exists($key, (array) $profile['json']['data']));
}
check('agent receives the real mobile number for calling',
    !empty($profile['json']['data']['lead']['mobile']), 'mobile was null');

// The lead LIST needs a dialable number too, not just the profile. The app
// enables its Call button straight from the list row, and when this was null the
// button silently never appeared - an agent had to open every lead one at a time
// just to phone the borrower. Asserted here on the server as well as in the app's
// contract test, because that test reads committed fixtures and would keep
// passing against a regressed server.
$listLead = (array) $firstLead;
check('the lead list carries a dialable mobile for an agent',
    !empty($listLead['mobile']), 'mobile was ' . var_export($listLead['mobile'] ?? null, true));
check('the lead list still carries the masked mobile for display',
    !empty($listLead['mobile_masked']));
// Aadhaar is deliberately kept out of list responses: no list needs it, and
// shipping it per row widens the damage from any one leaked response.
check('the lead list does NOT bulk-expose Aadhaar',
    empty($listLead['aadhaar']), 'aadhaar leaked into the list');
check('the lead list still carries the masked Aadhaar',
    !empty($listLead['aadhaar_masked']));

$history = api('GET', '/customers/' . $leadId . '/history', null, $agentToken);
check('GET /customers/{id}/history returns 200', $history['status'] === 200, 'HTTP ' . $history['status']);
check('history includes the timeline', is_array($history['json']['data']['timeline'] ?? null));
check('history includes visits', is_array($history['json']['data']['visits'] ?? null));

$missingLead = api('GET', '/customers/99999999', null, $agentToken);
check('unknown customer returns 404', $missingLead['status'] === 404, 'HTTP ' . $missingLead['status']);

// ---- Location notice, consent and trail ----------------------------------
// The whole point of this ordering: location is refused with 412 until the notice
// has been acknowledged, so there is no path that collects first and asks later.
$db2 = null;
$notice = api('GET', '/tracking/notice', null, $agentToken);
check('GET /tracking/notice returns 200', $notice['status'] === 200, 'HTTP ' . $notice['status']);
check('the notice is versioned', !empty($notice['json']['data']['version'] ?? null));
check('the notice has English text', str_contains((string) ($notice['json']['data']['english'] ?? ''), 'records your location'));
check('the notice has Hindi text', str_contains((string) ($notice['json']['data']['hindi'] ?? ''), 'लोकेशन'));
check('the notice states the retention period', (int) ($notice['json']['data']['retention_days'] ?? 0) > 0);
$noticeVersion = (string) ($notice['json']['data']['version'] ?? '');

// Before consent, posting a point must be refused with 412 and a clear flag.
$before = api('POST', '/tracking/location', ['points' => [['latitude' => 26.9124, 'longitude' => 75.7873]]], $agentToken);
check('location is refused before consent', $before['status'] === 412, 'HTTP ' . $before['status']);
check('the refusal asks for consent explicitly', ($before['json']['data']['consent_required'] ?? false) === true);

// A stale notice version must not be accepted as consent.
$stale = api('POST', '/tracking/consent', ['notice_version' => '1999-01-01'], $agentToken);
check('a stale notice version is rejected', $stale['status'] === 409, 'HTTP ' . $stale['status']);

$consent = api('POST', '/tracking/consent', ['notice_version' => $noticeVersion, 'device_info' => 'smoke test'], $agentToken);
check('POST /tracking/consent returns 200', $consent['status'] === 200, 'HTTP ' . $consent['status']);
check('consent is reported as recorded', ($consent['json']['data']['acknowledged'] ?? false) === true);
check('the notice now reports acknowledged',
    (api('GET', '/tracking/notice', null, $agentToken)['json']['data']['acknowledged'] ?? false) === true);

// Now points are accepted.
$post = api('POST', '/tracking/location', ['points' => [
    ['latitude' => 26.9124, 'longitude' => 75.7873, 'accuracy_m' => 15, 'on_duty' => true],
]], $agentToken);
check('POST /tracking/location returns 200 after consent', $post['status'] === 200, 'HTTP ' . $post['status']);
check('the point was stored', (int) ($post['json']['data']['stored'] ?? 0) === 1, json_encode($post['json']['data'] ?? null));

// A malformed point is rejected on its own without failing the batch.
$mixed = api('POST', '/tracking/location', ['points' => [
    ['latitude' => 0, 'longitude' => 0],
    ['longitude' => 75.0],
]], $agentToken);
check('a bad point does not fail the whole batch', $mixed['status'] === 200, 'HTTP ' . $mixed['status']);
check('bad points are reported individually', count((array) ($mixed['json']['data']['rejected'] ?? [])) === 2,
    json_encode($mixed['json']['data'] ?? null));

check('an empty points array is refused',
    api('POST', '/tracking/location', ['points' => []], $agentToken)['status'] === 422);
check('location requires a token',
    api('POST', '/tracking/location', ['points' => [['latitude' => 26.9, 'longitude' => 75.7]]])['status'] === 401);

// An agent may read their own trail and nobody else's.
$agentUserId = (int) ($login['json']['data']['user']['id'] ?? 0);
$ownTrail = api('GET', '/tracking/' . $agentUserId . '/trail?date=' . date('Y-m-d'), null, $agentToken);
check('an agent can read their own trail', $ownTrail['status'] === 200, 'HTTP ' . $ownTrail['status']);
check('the trail returns points', is_array($ownTrail['json']['data']['points'] ?? null));

$otherTrail = api('GET', '/tracking/' . ($agentUserId + 1000) . '/trail', null, $agentToken);
check('an agent cannot read another user\'s trail', $otherTrail['status'] === 403, 'HTTP ' . $otherTrail['status']);

// Withdrawal stops collection immediately.
$withdraw = api('POST', '/tracking/consent/withdraw', null, $agentToken);
check('POST /tracking/consent/withdraw returns 200', $withdraw['status'] === 200, 'HTTP ' . $withdraw['status']);
$afterWithdraw = api('POST', '/tracking/location', ['points' => [['latitude' => 26.9124, 'longitude' => 75.7873]]], $agentToken);
check('location is refused again after withdrawal', $afterWithdraw['status'] === 412, 'HTTP ' . $afterWithdraw['status']);

// Put it back so later checks are unaffected.
api('POST', '/tracking/consent', ['notice_version' => $noticeVersion], $agentToken);

// ---- Customer data sheet --------------------------------------------------
// The sheet leaves the device as a file, so it is scoped to leads assigned to
// this agent - not merely leads in their branch, which is all the rest of the
// lead API requires.
$sheet = apiDownload('/customers/' . $leadId . '/sheet', $agentToken);
check('GET /customers/{id}/sheet returns 200', $sheet['status'] === 200, 'HTTP ' . $sheet['status']);
check('sheet is delivered as a PDF', str_starts_with($sheet['body'], '%PDF-'), substr($sheet['body'], 0, 8));
check('sheet is sent as an attachment', stripos($sheet['headers'], 'attachment') !== false);
check('sheet is served as application/pdf', stripos($sheet['headers'], 'application/pdf') !== false);
check('sheet is not cached', stripos($sheet['headers'], 'no-store') !== false);
check('sheet requires a token', apiDownload('/customers/' . $leadId . '/sheet')['status'] === 401);

// A second agent's lead must be refused. The rest of the lead API allows an agent
// to read anything in their branch; the sheet deliberately does not, because it
// leaves the device as a file.
$agent2Login = api('POST', '/auth/login', ['employee_code' => 'AGT002', 'password' => 'Agent@123']);
$agent2Token = (string) ($agent2Login['json']['data']['access_token'] ?? '');
if ($agent2Token !== '') {
    $agent2Leads = api('GET', '/leads?per_page=1', null, $agent2Token);
    $agent2LeadId = (int) ($agent2Leads['json']['data'][0]['id'] ?? 0);
    if ($agent2LeadId > 0 && $agent2LeadId !== $leadId) {
        $foreign = apiDownload('/customers/' . $agent2LeadId . '/sheet', $agentToken);
        check(
            'an agent cannot take the sheet for another agent\'s lead',
            $foreign['status'] === 403,
            'HTTP ' . $foreign['status']
        );
        check(
            'but that lead\'s own agent can',
            apiDownload('/customers/' . $agent2LeadId . '/sheet', $agent2Token)['status'] === 200
        );
    }
}

// ---------------------------------------------------------------------------
section('Visit submission (append-only, idempotent)');

$before = count((array) api('GET', '/visits?loan_account_id=' . $leadId, null, $agentToken)['json']['data']);

$uuid = sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
    substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));

$visitPayload = [
    'loan_account_id'       => $leadId,
    'visit_date'            => date('Y-m-d'),
    'visit_time'            => '11:15',
    'village'               => $firstLead['village'] ?? 'Kotri',
    'customer_met'          => true,
    'borrower_alive'        => true,
    'same_address'          => true,
    'occupation'            => 'agriculture',
    'ready_to_pay'          => true,
    'promise_amount'        => 12500,
    'promise_date'          => date('Y-m-d', strtotime('+7 days')),
    'reason_crop_loss'      => true,
    'rec_recovery_possible' => true,
    'remarks'               => 'API smoke test visit. Customer agreed to pay after harvest.',
    'client_uuid'           => $uuid,
    'app_version'           => '1.0.0',
    'device_info'           => 'smoke-test',
];

$submit = api('POST', '/visits', $visitPayload, $agentToken);
check('POST /visits returns 201', $submit['status'] === 201, 'HTTP ' . $submit['status'] . ' ' . $submit['raw']);
check('submission returns a visit id', !empty($submit['json']['data']['visit_id']));
check('submission creates a promise', !empty($submit['json']['data']['promise_id']));
check('submission returns the updated lead', ($submit['json']['data']['lead']['current_status'] ?? '') === 'promise');
check('submission reports no warnings', ($submit['json']['data']['warnings'] ?? ['x']) === []);

$visitId = (int) ($submit['json']['data']['visit_id'] ?? 0);

// Idempotency: the same client_uuid must not create a second report.
$retry = api('POST', '/visits', $visitPayload, $agentToken);
check('resubmitting the same client_uuid is idempotent',
    $retry['status'] === 200 && ($retry['json']['data']['duplicate'] ?? false) === true,
    'HTTP ' . $retry['status']);
check('the retry returns the original visit id', (int) ($retry['json']['data']['visit_id'] ?? 0) === $visitId);

$after = count((array) api('GET', '/visits?loan_account_id=' . $leadId, null, $agentToken)['json']['data']);
check('only one visit was appended', $after === $before + 1, "before={$before} after={$after}");

// A promise amount with no date is a validation error, not a silent drop.
$badPromise = api('POST', '/visits', array_merge($visitPayload, [
    'client_uuid'    => 'bad-promise-' . bin2hex(random_bytes(4)),
    'promise_amount' => 5000,
    'promise_date'   => '',
]), $agentToken);
check('promise amount without a date returns 422', $badPromise['status'] === 422, 'HTTP ' . $badPromise['status']);

$badDate = api('POST', '/visits', array_merge($visitPayload, [
    'client_uuid' => 'bad-date-' . bin2hex(random_bytes(4)),
    'visit_date'  => 'not-a-date',
]), $agentToken);
check('invalid visit date returns 422', $badDate['status'] === 422, 'HTTP ' . $badDate['status']);

$badLead = api('POST', '/visits', array_merge($visitPayload, [
    'client_uuid'     => 'bad-lead-' . bin2hex(random_bytes(4)),
    'loan_account_id' => 99999999,
]), $agentToken);
check('visit against an unknown lead returns 404', $badLead['status'] === 404, 'HTTP ' . $badLead['status']);

// ---------------------------------------------------------------------------
section('Visit retrieval');

$visitShow = api('GET', '/visits/' . $visitId, null, $agentToken);
check('GET /visits/{id} returns 200', $visitShow['status'] === 200, 'HTTP ' . $visitShow['status']);

$report = (array) ($visitShow['json']['data']['report'] ?? []);
foreach (['general', 'borrower', 'loan', 'contact', 'verification', 'recovery', 'non_payment_reason', 'recommendation'] as $group) {
    check("visit report has the {$group} group", array_key_exists($group, $report));
}
check('visit report echoes the remarks', str_contains((string) ($report['remarks'] ?? ''), 'API smoke test'));
check('visit report records the promise', ($report['recovery']['promise_amount'] ?? 0) == 12500.0);
check('visit report flags customer_met as boolean', ($report['contact']['customer_met'] ?? null) === true);
check('visit report snapshots the account number',
    ($report['loan']['loan_account_number'] ?? '') === $accountNumber);
check('visit report records the source', ($report['source'] ?? '') === 'android');

// ---------------------------------------------------------------------------
section('Promises');

$promises = api('GET', '/promises', null, $agentToken);
check('GET /promises returns 200', $promises['status'] === 200, 'HTTP ' . $promises['status']);
check('promises include pagination meta', isset($promises['json']['meta']['total']));

$promiseId = (int) ($submit['json']['data']['promise_id'] ?? 0);

// An agent records a promise during a visit but does not decide its outcome:
// marking it kept or broken is a branch decision, so this must be refused.
$agentSettle = api('POST', '/promises/' . $promiseId . '/settle', ['status' => 'kept'], $agentToken);
check('agent cannot settle a promise', $agentSettle['status'] === 403, 'HTTP ' . $agentSettle['status']);
$promiseAfterRefusal = api('GET', '/promises?status=pending', null, $agentToken);
$stillPending = false;
foreach ((array) $promiseAfterRefusal['json']['data'] as $row) {
    if ((int) ($row['id'] ?? 0) === $promiseId) {
        $stillPending = true;
        break;
    }
}
check('the promise is still pending after the refusal', $stillPending);

// ---------------------------------------------------------------------------
section('Notifications');

$notifications = api('GET', '/notifications', null, $agentToken);
check('GET /notifications returns 200', $notifications['status'] === 200, 'HTTP ' . $notifications['status']);
check('notifications report an unread count', isset($notifications['json']['unread_count']));

$unreadCount = api('GET', '/notifications/unread-count', null, $agentToken);
check('GET /notifications/unread-count returns 200', $unreadCount['status'] === 200);

$notificationList = (array) $notifications['json']['data'];
if ($notificationList !== []) {
    $notificationId = (int) $notificationList[0]['id'];
    $read = api('POST', '/notifications/' . $notificationId . '/read', [], $agentToken);
    check('marking a notification read returns 200', $read['status'] === 200, 'HTTP ' . $read['status']);
}

$readAll = api('POST', '/notifications/read-all', [], $agentToken);
check('read-all returns 200', $readAll['status'] === 200);
check('read-all zeroes the unread count', ($readAll['json']['data']['unread_count'] ?? -1) === 0);

// ---------------------------------------------------------------------------
section('Agent authorisation limits');

$importAttempt = api('POST', '/import/upload', [], $agentToken);
check('agent cannot upload lead files', $importAttempt['status'] === 403, 'HTTP ' . $importAttempt['status']);

$assignAttempt = api('POST', '/leads/assign', ['lead_ids' => [$leadId], 'agent_id' => $agentId], $agentToken);
check('agent cannot assign leads', $assignAttempt['status'] === 403, 'HTTP ' . $assignAttempt['status']);

$transferAttempt = api('POST', '/leads/transfer', ['lead_ids' => [$leadId], 'branch_id' => 1], $agentToken);
check('agent cannot transfer leads', $transferAttempt['status'] === 403, 'HTTP ' . $transferAttempt['status']);

$broadcastAttempt = api('POST', '/notifications/send', ['title' => 'x', 'body' => 'y'], $agentToken);
check('agent cannot broadcast notifications', $broadcastAttempt['status'] === 403, 'HTTP ' . $broadcastAttempt['status']);

// ---------------------------------------------------------------------------
section('Manager & admin scope');

$managerLogin = api('POST', '/auth/login', ['employee_code' => 'MGR001', 'password' => 'Manager@123']);
check('branch manager can sign in', $managerLogin['status'] === 200, 'HTTP ' . $managerLogin['status']);
check('manager gets the branch_manager role', ($managerLogin['json']['data']['user']['role'] ?? '') === 'branch_manager');
$managerToken = (string) ($managerLogin['json']['data']['access_token'] ?? '');

$managerLeads = api('GET', '/leads?per_page=100', null, $managerToken);
check('manager can list branch leads', $managerLeads['status'] === 200, 'HTTP ' . $managerLeads['status']);

$managerBranchId = (int) ($managerLogin['json']['data']['user']['branch_id'] ?? 0);
$sameBranch = true;
foreach ((array) $managerLeads['json']['data'] as $lead) {
    if ((int) ($lead['branch_id'] ?? 0) !== $managerBranchId) {
        $sameBranch = false;
        break;
    }
}
check('manager only sees their own branch', $sameBranch);

// Branch isolation cannot be widened with a query parameter.
$otherBranch = api('GET', '/leads?branch_id=999&per_page=100', null, $managerToken);
$stillScoped = true;
foreach ((array) $otherBranch['json']['data'] as $lead) {
    if ((int) ($lead['branch_id'] ?? 0) !== $managerBranchId) {
        $stillScoped = false;
        break;
    }
}
check('branch_id parameter cannot widen a manager scope', $stillScoped);

$managerTransfer = api('POST', '/leads/transfer', ['lead_ids' => [$leadId], 'branch_id' => 2], $managerToken);
check('manager cannot transfer across branches', $managerTransfer['status'] === 403, 'HTTP ' . $managerTransfer['status']);

// Promise settlement is the manager's decision, not the agent's.
$managerSettle = api('POST', '/promises/' . $promiseId . '/settle', ['status' => 'kept', 'notes' => 'Paid at branch counter.'], $managerToken);
check('manager can settle a promise', $managerSettle['status'] === 200, 'HTTP ' . $managerSettle['status'] . ' ' . $managerSettle['raw']);
check('promise status becomes kept', ($managerSettle['json']['data']['status'] ?? '') === 'kept');

$settleAgain = api('POST', '/promises/' . $promiseId . '/settle', ['status' => 'broken'], $managerToken);
check('a settled promise cannot be settled twice', $settleAgain['status'] === 422, 'HTTP ' . $settleAgain['status']);

$badStatus = api('POST', '/promises/' . $promiseId . '/settle', ['status' => 'nonsense'], $managerToken);
check('invalid promise status is rejected', $badStatus['status'] === 422, 'HTTP ' . $badStatus['status']);

// ---------------------------------------------------------------------------
section('Reports API');

$reportIndex = api('GET', '/reports', null, $managerToken);
check('GET /reports returns 200', $reportIndex['status'] === 200, 'HTTP ' . $reportIndex['status']);
check('8 report types are listed', count((array) $reportIndex['json']['data']) === 8,
    (string) count((array) $reportIndex['json']['data']));

foreach (['daily', 'weekly', 'monthly', 'branch', 'village', 'loan-type', 'agent', 'promise'] as $type) {
    $response = api('GET', '/reports/' . $type, null, $managerToken);
    check("GET /reports/{$type} returns 200", $response['status'] === 200, 'HTTP ' . $response['status']);
    check("report [{$type}] returns columns", is_array($response['json']['data']['columns'] ?? null));
    check("report [{$type}] returns rows", is_array($response['json']['data']['rows'] ?? null));
}

check('unknown report type returns 404', api('GET', '/reports/nonsense', null, $managerToken)['status'] === 404);

// ---------------------------------------------------------------------------
section('Media access control');

$traversal = api('GET', '/media?f=' . urlencode('../config/config.php'), null, $agentToken);
check('media traversal is rejected', in_array($traversal['status'], [400, 403, 404], true), 'HTTP ' . $traversal['status']);

$badExt = api('GET', '/media?f=' . urlencode('evil.php'), null, $agentToken);
check('media rejects executable extensions', in_array($badExt['status'], [400, 403, 404, 415], true),
    'HTTP ' . $badExt['status']);

$noAuthMedia = api('GET', '/media?f=' . urlencode('photos/2026/07/x.png'));
check('media requires authentication', $noAuthMedia['status'] === 401, 'HTTP ' . $noAuthMedia['status']);

// ---------------------------------------------------------------------------
section('Sign out');

$logout = api('POST', '/auth/logout', ['refresh_token' => $newRefresh, 'device_token' => 'smoke-device-token-001'], $agentToken);
check('logout returns 200', $logout['status'] === 200, 'HTTP ' . $logout['status']);

$revoked = api('POST', '/auth/refresh', ['refresh_token' => $newRefresh]);
check('the refresh token is revoked after logout', $revoked['status'] === 401, 'HTTP ' . $revoked['status']);

// ---------------------------------------------------------------------------
section('404 handling');

$unknown = api('GET', '/does-not-exist', null, $agentToken);
check('unknown API path returns 404 JSON', $unknown['status'] === 404 && hasEnvelope($unknown['json']),
    'HTTP ' . $unknown['status'] . ' ' . substr($unknown['raw'], 0, 80));

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
printf("  API SMOKE: %d passed, %d failed\n", $passed, $failed);
if ($failures !== []) {
    echo '  Failed: ' . implode('; ', $failures) . "\n";
}
echo str_repeat('=', 60) . "\n";

exit($failed === 0 ? 0 : 1);
