<?php
/**
 * Seeds a small but realistic demo dataset: branches, a manager, agents,
 * imported leads, visits, promises and notifications.
 *
 * Used by tools/smoke-panel.sh, and handy for a first look at the panel.
 *
 *   LRMS_DB_HOST=... LRMS_DB_PORT=... php tools/seed-demo.php
 */

declare(strict_types=1);

// Which application tree to seed against. Overridable so the same seeder can
// target a built hosting package, not just the repository's admin/ directory.
$root = getenv('LRMS_APP_ROOT') ?: dirname(__DIR__) . '/admin';
$root = rtrim($root, '/');
if (!is_file($root . '/app/Core/helpers.php')) {
    fwrite(STDERR, "seed-demo: '$root' is not a D2 Recovery application root\n");
    exit(1);
}
define('APP_PATH', $root . '/app');
define('ROOT_PATH', $root);

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require $root . '/app/Core/helpers.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\Promise;
use App\Models\User;
use App\Services\ImportService;
use App\Services\TrackingService;
use App\Services\VisitService;

// Prefer an existing config.php (written by the smoke script); fall back to env.
$configFile = $root . '/config/config.php';
if (is_file($configFile)) {
    Config::load(require $configFile);
} else {
    // The crypto keys matter as much as the database credentials here. Mobile
    // numbers, Aadhaar numbers and addresses are encrypted with data_key and
    // indexed with hash_pepper, so seeding with keys the application does not
    // share produces a dataset whose PII silently reads back as null - which is
    // exactly how this fallback was caught. Take them from the environment when
    // offered, and say plainly what happens when they are not.
    $appKey = getenv('LRMS_APP_KEY') ?: null;
    $dataKey = getenv('LRMS_DATA_KEY') ?: null;
    $pepper = getenv('LRMS_HASH_PEPPER') ?: null;

    if ($dataKey === null || $pepper === null) {
        fwrite(
            STDERR,
            "seed-demo: no config.php and no LRMS_DATA_KEY/LRMS_HASH_PEPPER, so random keys\n"
            . "           will be used. Encrypted fields (mobile, Aadhaar, address) will read\n"
            . "           back as null in any app configured with different keys. Point\n"
            . "           LRMS_APP_ROOT at the tree whose config.php you are using, or pass\n"
            . "           LRMS_APP_KEY / LRMS_DATA_KEY / LRMS_HASH_PEPPER.\n"
        );
    }

    Config::load([
        'db' => [
            'host'    => getenv('LRMS_DB_HOST') ?: '127.0.0.1',
            'port'    => (int) (getenv('LRMS_DB_PORT') ?: 3306),
            'name'    => getenv('LRMS_DB_NAME') ?: 'lrms',
            'user'    => getenv('LRMS_DB_USER') ?: 'root',
            'pass'    => getenv('LRMS_DB_PASS') ?: 'root',
            'charset' => 'utf8mb4',
        ],
        'app_key'     => $appKey ?? bin2hex(random_bytes(32)),
        'data_key'    => $dataKey ?? bin2hex(random_bytes(32)),
        'hash_pepper' => $pepper ?? bin2hex(random_bytes(32)),
        'app'         => ['debug' => true, 'timezone' => 'Asia/Kolkata'],
        'paths'       => ['uploads' => $root . '/uploads', 'storage' => $root . '/storage'],
        'uploads'     => [
            'max_photo_bytes'    => 8388608,
            'max_document_bytes' => 12582912,
            'max_import_bytes'   => 26214400,
            'allowed_image_mime' => ['image/jpeg', 'image/png', 'image/webp'],
            'allowed_doc_mime'   => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        ],
        'session'     => ['name' => 'lrms_seed', 'lifetime' => 7200, 'secure' => false],
    ]);
}

date_default_timezone_set('Asia/Kolkata');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/seed';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'lrms-seed';

$db = Database::instance();

if ((int) $db->scalar('SELECT COUNT(*) FROM loan_accounts') > 0) {
    echo "demo data already present, skipping\n";
    exit(0);
}

// Act as the super admin so audit/timeline entries have an author.
Auth::loginSession(Auth::loadActiveUser(1));

Settings::updateMany([
    'bank_name'   => 'Gramin Vikas Bank',
    'sms_provider' => 'demo',
    'sms_api_url' => 'https://example.invalid/send?to={mobile}&text={message}&key={key}',
    'sms_api_key' => 'demo-key',
], 1);

/**
 * A small JPEG that looks vaguely like a photograph, as base64.
 *
 * Deliberately a real encoded image rather than a fixed blob: Uploader sniffs the
 * MIME with finfo and rejects anything that getimagesize() cannot read, so a fake
 * would be refused and the seed would silently produce visits with no media.
 */
function demo_photo_base64(int $seed): string
{
    $image = imagecreatetruecolor(640, 480);
    for ($x = 0; $x < 640; $x += 16) {
        imagefilledrectangle(
            $image,
            $x,
            0,
            $x + 15,
            479,
            (int) imagecolorallocate($image, ($seed * 7 + $x) % 200, 110 + ($seed % 90), 160 - ($x % 140))
        );
    }
    imagestring($image, 5, 18, 18, 'DEMO PHOTO ' . $seed, (int) imagecolorallocate($image, 255, 255, 255));

    ob_start();
    imagejpeg($image, null, 82);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return base64_encode($bytes);
}

/** Line art on transparency, like a real captured signature. */
function demo_signature_base64(): string
{
    $image = imagecreatetruecolor(520, 180);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

    $ink = (int) imagecolorallocate($image, 12, 12, 24);
    imagesetthickness($image, 5);
    imagearc($image, 150, 90, 200, 90, 20, 300, $ink);
    imagearc($image, 320, 95, 180, 70, 200, 20, $ink);
    imageline($image, 40, 140, 480, 132, $ink);

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return base64_encode($bytes);
}

// ---------------------------------------------------------------------------
// Branches
// ---------------------------------------------------------------------------
$branchIds = [];
foreach ([
    ['BR001', 'Bhilwara Main', 'Bhilwara', 'Rajasthan', '311001'],
    ['BR002', 'Kotri Rural', 'Bhilwara', 'Rajasthan', '311022'],
    ['BR003', 'Mandal Branch', 'Bhilwara', 'Rajasthan', '311604'],
] as [$code, $name, $district, $state, $pin]) {
    $branchIds[$code] = Branch::create([
        'branch_code' => $code, 'name' => $name, 'district' => $district,
        'state' => $state, 'pincode' => $pin, 'status' => 'active',
    ]);
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------
User::create([
    'employee_code' => 'MGR001', 'name' => 'Suresh Chandra', 'email' => 'suresh@example.com',
    'designation' => 'Branch Manager', 'role_id' => 2, 'branch_id' => $branchIds['BR001'],
    'status' => 'active', 'must_change_password' => 0,
], 'Manager@123', '9811100001');

$agents = [];
foreach ([
    ['AGT001', 'Ramesh Kumar', 'BC-001', 'BR001'],
    ['AGT002', 'Sunita Devi', 'BC-002', 'BR001'],
    ['AGT003', 'Mohan Lal', 'BC-003', 'BR002'],
    ['AGT004', 'Kavita Sharma', 'BC-004', 'BR003'],
] as $index => [$code, $name, $bcCode, $branchCode]) {
    $agents[$code] = User::create([
        'employee_code' => $code, 'name' => $name, 'bc_code' => $bcCode,
        'designation' => 'BC/DC Agent', 'role_id' => 3, 'branch_id' => $branchIds[$branchCode],
        'status' => 'active', 'must_change_password' => 0,
    ], 'Agent@123', '982220000' . ($index + 1));

    // Acknowledge the location notice.
    //
    // Not decoration: TrackingService gates every stored coordinate on consent, so
    // without this the seeded visits all record gps_source = 'denied', every
    // per-photo fix is dropped, and the entire geo-tagging path ships exercised by
    // nothing at all - which is exactly the state it was in until this line existed.
    TrackingService::recordConsent($agents[$code], 'Seeded demo device', '127.0.0.1');
}

// ---------------------------------------------------------------------------
// Leads, imported through the real import pipeline
// ---------------------------------------------------------------------------
$villages = ['Kotri', 'Mandal', 'Sahada', 'Gulabpura', 'Hurda', 'Banera'];
$loanTypes = ['Crop Loan', 'Dairy Loan', 'KCC', 'SHG Loan', 'Tractor Loan'];
$names = [
    'Ramesh Kumar', 'Sita Devi', 'Gopal Singh', 'Anita Sharma', 'Mahesh Jat',
    'Kamla Bai', 'Prakash Meena', 'Sunita Kumari', 'Rajesh Gurjar', 'Leela Devi',
    'Naresh Chand', 'Pushpa Devi', 'Dinesh Sharma', 'Radha Bai', 'Suresh Meena',
    'Manju Devi', 'Vijay Singh', 'Geeta Kumari', 'Arjun Lal', 'Shanti Bai',
    'Hariram Jat', 'Bhagwati Devi', 'Om Prakash', 'Rekha Sharma', 'Kailash Chand',
];

$csvPath = sys_get_temp_dir() . '/lrms_demo_leads.csv';
$rows = ['Branch,BC Code,Loan Account Number,Customer Name,Father/Husband Name,Mobile,Aadhaar,Village,Address,Loan Type,Outstanding Amount,Overdue Amount,NPA Date,Remarks'];

$branchCodes = array_keys($branchIds);
for ($i = 1; $i <= 60; $i++) {
    $branchCode = $branchCodes[$i % count($branchCodes)];
    $name = $names[($i - 1) % count($names)];
    $village = $villages[$i % count($villages)];
    $loanType = $loanTypes[$i % count($loanTypes)];
    $outstanding = 25000 + ($i * 3175);
    $overdue = (int) round($outstanding * (0.08 + (($i % 5) * 0.04)));
    $npaDate = $i % 3 === 0 ? sprintf('%02d/%02d/2024', ($i % 28) + 1, ($i % 12) + 1) : '';

    $rows[] = sprintf(
        '%s,BC-00%d,LN%08d,%s,%s,98765%05d,1234%08d,%s,"House %d, %s",%s,%s,%s,%s,%s',
        $branchCode,
        ($i % 4) + 1,
        100000 + $i,
        $name,
        'Father of ' . $name,
        10000 + $i,
        10000000 + $i,
        $village,
        $i,
        $village,
        $loanType,
        number_format($outstanding, 2, '.', ''),
        number_format($overdue, 2, '.', ''),
        $npaDate,
        $i % 7 === 0 ? 'Chronic defaulter' : ''
    );
}

file_put_contents($csvPath, implode("\n", $rows));

$result = ImportService::run(
    ['name' => 'demo_leads.csv', 'tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath)],
    null,
    null,
    1,
    'System Administrator'
);
printf("imported: %d new, %d updated, %d skipped\n", $result['inserted'], $result['updated'], $result['skipped']);

// ---------------------------------------------------------------------------
// Assign leads round-robin to the agents in each branch
// ---------------------------------------------------------------------------
$agentsByBranch = [];
foreach ($agents as $code => $agentId) {
    $agent = User::find($agentId);
    $agentsByBranch[(int) $agent['branch_id']][] = $agentId;
}

$leadRows = $db->all('SELECT id, branch_id FROM loan_accounts ORDER BY id');
$counter = 0;
foreach ($leadRows as $lead) {
    $pool = $agentsByBranch[(int) $lead['branch_id']] ?? [];
    if ($pool === []) {
        continue;
    }
    // Leave roughly a fifth unassigned so the "Unassigned" filter has data.
    if ($counter % 5 === 4) {
        $counter++;
        continue;
    }
    $agentId = $pool[$counter % count($pool)];
    $db->update('loan_accounts', [
        'assigned_agent_id' => $agentId,
        'assigned_at'       => date('Y-m-d H:i:s'),
        'assigned_by'       => 1,
    ], ['id' => (int) $lead['id']]);
    \App\Models\Timeline::record(
        (int) $lead['id'],
        'assigned',
        'Assigned to agent',
        'Initial allocation.',
        1,
        'System Administrator'
    );
    $counter++;
}

// ---------------------------------------------------------------------------
// Visits spread over the last 20 days, some with promises
// ---------------------------------------------------------------------------
$assigned = $db->all(
    'SELECT la.id, la.assigned_agent_id, la.branch_id, c.village
       FROM loan_accounts la JOIN customers c ON c.id = la.customer_id
      WHERE la.assigned_agent_id IS NOT NULL ORDER BY la.id LIMIT 40'
);

$visitCount = 0;
$promiseIds = [];

foreach ($assigned as $index => $lead) {
    $agentId = (int) $lead['assigned_agent_id'];
    $agentRow = User::find($agentId);
    $agentCtx = [
        'id'        => $agentId,
        'name'      => (string) $agentRow['name'],
        'bc_code'   => (string) ($agentRow['bc_code'] ?? ''),
        'branch_id' => (int) $lead['branch_id'],
    ];

    $daysAgo = $index % 20;
    $visitDate = date('Y-m-d', strtotime("-{$daysAgo} days"));

    $makesPromise = $index % 3 === 0;
    $notReady = $index % 3 === 1;
    $locked = $index % 3 === 2;

    $payload = [
        'loan_account_id' => (int) $lead['id'],
        'visit_date'      => $visitDate,
        'visit_time'      => sprintf('%02d:%02d', 9 + ($index % 8), ($index * 7) % 60),
        'village'         => (string) $lead['village'],
        'borrower_alive'  => '1',
        'same_address'    => '1',
        'occupation'      => ['agriculture', 'dairy', 'business', 'labour'][$index % 4],
        'client_uuid'     => sprintf('%08x-0000-4000-8000-%012x', $index, $index),
        'app_version'     => '1.0.0',
    ];

    // Every third visit carries a geo-stamped photograph and both signatures, so the
    // printed report, the photo gallery and the approval screen all have something
    // real to render. Seeded data with no media meant the PDF's image embedding was
    // exercised by nothing at all.
    if ($index % 3 === 0) {
        $payload['gps_source'] = 'device';
        $payload['gps_latitude'] = (string) round(19.0728 + ($index * 0.0007), 7);
        $payload['gps_longitude'] = (string) round(72.8826 + ($index * 0.0005), 7);
        $payload['gps_accuracy_m'] = (string) (8 + ($index % 20));
        $payload['gps_captured_at'] = $visitDate . ' ' . sprintf('%02d:%02d:00', 9 + ($index % 8), ($index * 7) % 60);

        // A camera photograph gets its own fix; see VisitService::photoPoint().
        $payload['customer_photo_base64'] = demo_photo_base64($index);
        $payload['customer_photo_source'] = 'camera';
        $payload['customer_photo_gps_source'] = 'camera';
        $payload['customer_photo_latitude'] = $payload['gps_latitude'];
        $payload['customer_photo_longitude'] = $payload['gps_longitude'];
        $payload['customer_photo_accuracy_m'] = $payload['gps_accuracy_m'];

        // And a gallery-picked one, which must print as having no location rather
        // than quietly inheriting the visit's.
        $payload['house_photo_base64'] = demo_photo_base64($index + 100);
        $payload['house_photo_source'] = 'gallery';

        $payload['customer_signature_base64'] = demo_signature_base64();
        $payload['customer_signature_name'] = (string) $lead['customer_name'];
        $payload['agent_signature_base64'] = demo_signature_base64();
        $payload['agent_signature_name'] = (string) $agentCtx['name'];
    }

    if ($makesPromise) {
        $payload['customer_met'] = '1';
        $payload['ready_to_pay'] = '1';
        $payload['promise_amount'] = (string) (5000 + ($index * 750));
        $payload['promise_date'] = date('Y-m-d', strtotime('+' . (($index % 14) - 5) . ' days'));
        $payload['rec_recovery_possible'] = '1';
        $payload['remarks'] = 'Customer agreed to pay after selling produce.';
    } elseif ($notReady) {
        $payload['customer_met'] = '1';
        $payload['not_ready'] = '1';
        $payload['reason_crop_loss'] = '1';
        $payload['rec_regular_followup'] = '1';
        $payload['remarks'] = 'Crop loss this season; requested more time.';
    } else {
        $payload['house_locked'] = '1';
        $payload['phone_switched_off'] = '1';
        $payload['rec_legal_action'] = $index % 9 === 2 ? '1' : '0';
        $payload['remarks'] = 'House locked, neighbours say borrower is away.';
    }

    $visit = VisitService::submit($payload, $agentCtx);
    $visitCount++;

    if ($visit['promise_id'] !== null) {
        $promiseIds[] = (int) $visit['promise_id'];
    }
}

// ---------------------------------------------------------------------------
// One KRM/OTS settlement and one CKCC renewal report.
//
// Without these two, the panel's settlement and renewal cards are never rendered
// by any test run and the app's contract fixtures cannot capture them - the new
// view code would ship having never been executed.
// ---------------------------------------------------------------------------
$specialLeads = array_slice($assigned, 0, 2);

if (isset($specialLeads[0])) {
    $otsLeadId = (int) $specialLeads[0]['id'];
    $otsLead = LoanAccount::find($otsLeadId);
    $rlb = round((float) $otsLead['outstanding_amount'] * 0.55, 2);
    $payable = round($rlb * 0.225, 2);

    VisitService::submit([
        'loan_account_id' => $otsLeadId,
        'report_type'     => 'ots',
        'visit_date'      => date('Y-m-d', strtotime('-2 days')),
        'visit_time'      => '11:20:00',
        'customer_met'    => '1',
        'ready_to_pay'    => '1',
        'ots'             => '1',
        'rec_ots'         => '1',
        'remarks'         => 'Borrower willing to settle under KRM OTS.',
        'sp_cbc_name'     => 'S. Verma',
        'ots_details[eligible_for_ots]'        => '1',
        'ots_details[scheme]'                  => 'krm_ots',
        'ots_details[relief_waiver_percent]'   => '77.5',
        'ots_details[rlb_amount]'              => (string) $rlb,
        'ots_details[borrower_payable_amount]' => (string) $payable,
        'ots_details[total_settlement_amount]' => (string) $payable,
        'ots_details[required_deposit_amount]' => (string) round($payable * 0.10, 2),
        'ots_details[deposit_received]'        => '1',
        'ots_details[deposit_amount]'          => (string) round($payable * 0.10, 2),
        'ots_details[deposit_date]'            => date('Y-m-d', strtotime('-1 day')),
        'ots_details[deposit_reference]'       => 'RCPT/2026/004417',
        'ots_details[balance_payable]'         => (string) round($payable * 0.90, 2),
        'ots_details[proposed_final_payment_date]' => date('Y-m-d', strtotime('+60 days')),
        'ots_details[approval_status]'         => 'approved',
        'ots_details[validity_from]'           => date('Y-m-d', strtotime('-1 day')),
        'ots_details[validity_to]'             => date('Y-m-d', strtotime('+89 days')),
        'ots_details[expected_closure_date]'   => date('Y-m-d', strtotime('+92 days')),
        'ots_details[borrower_accepted]'       => '1',
    ], $agentCtx);
    $visitCount++;
}

if (isset($specialLeads[1])) {
    $ckccLeadId = (int) $specialLeads[1]['id'];

    // A renewal six days out, so the seeded data exercises the amber "within 7
    // days" state rather than the calm one.
    Database::instance()->query(
        'UPDATE loan_accounts
            SET loan_type = ?, cif_number = ?, sanction_date = ?, sanction_limit = ?,
                drawing_power = ?, interest_overdue = ?, ckcc_renewal_due_date = ?
          WHERE id = ?',
        [
            'CKCC', 'CIF' . str_pad((string) $ckccLeadId, 7, '0', STR_PAD_LEFT),
            date('Y-m-d', strtotime('-19 months')), 300000, 285000, 14250,
            date('Y-m-d', strtotime('+6 days')), $ckccLeadId,
        ]
    );

    VisitService::submit([
        'loan_account_id' => $ckccLeadId,
        'report_type'     => 'ckcc_renewal',
        'visit_date'      => date('Y-m-d', strtotime('-1 day')),
        'visit_time'      => '09:45:00',
        'customer_met'    => '1',
        'borrower_alive'  => '1',
        'same_address'    => '1',
        'occupation'      => 'agriculture',
        'remarks'         => 'Renewal papers collected, e-KYC done on the spot.',
        'sp_cbc_name'     => 'S. Verma',
        'ckcc_details[eligible_for_renewal]'   => '1',
        'ckcc_details[kyc_status]'             => 'complete',
        'ckcc_details[aadhaar_seeded]'         => '1',
        'ckcc_details[mobile_linked]'          => '1',
        'ckcc_details[aadhaar_auth_completed]' => '1',
        'ckcc_details[doc_aadhaar]'            => '1',
        'ckcc_details[doc_passbook]'           => '1',
        'ckcc_details[doc_khasra_khatauni]'    => '1',
        'ckcc_details[doc_photograph]'         => '1',
        'ckcc_details[doc_mobile_available]'   => '1',
        'ckcc_details[willing_to_renew]'       => '1',
        'ckcc_details[documents_handed_over]'  => '1',
        'ckcc_details[renewal_form_signed]'    => '1',
        'ckcc_details[ekyc_completed]'         => '1',
        'ckcc_details[agent_observation]'      => 'Borrower cooperative. Land records in order, crop standing.',
        'ckcc_details[rec_renew_immediately]'  => '1',
        'ckcc_details[rec_documents_submitted]' => '1',
        'ckcc_details[st_customer_contacted]'  => '1',
        'ckcc_details[st_customer_verified]'   => '1',
        'ckcc_details[st_documents_collected]' => '1',
        'ckcc_details[st_application_submitted]' => '1',
    ], $agentCtx);
    $visitCount++;
}

// Settle some promises so the promise report has all statuses.
foreach ($promiseIds as $index => $promiseId) {
    if ($index % 3 === 0) {
        Promise::settle($promiseId, 'kept', 1, 'System Administrator', 'Paid at branch counter.');
    } elseif ($index % 3 === 1) {
        Promise::settle($promiseId, 'broken', 1, 'System Administrator', 'Did not turn up.');
    }
}

// Close a handful of leads.
$toClose = array_slice(array_column($assigned, 'id'), 0, 4);
\App\Services\AssignmentService::setStatus(array_map('intval', $toClose), 'closed', 'Fully recovered.');

\App\Models\Notification::broadcast(
    'Recovery drive this Saturday',
    'All BC/DC agents please cover the pending NPA accounts in your village allocation.',
    null,
    1
);

printf(
    "seeded: %d branches, %d users, %d leads, %d visits, %d promises\n",
    count($branchIds),
    (int) $db->scalar('SELECT COUNT(*) FROM users'),
    (int) $db->scalar('SELECT COUNT(*) FROM loan_accounts'),
    $visitCount,
    (int) $db->scalar('SELECT COUNT(*) FROM promises')
);

@unlink($csvPath);
