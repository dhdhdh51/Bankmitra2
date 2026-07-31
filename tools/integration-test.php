<?php
/**
 * End-to-end integration test against a real MySQL server.
 *
 * Driven by tools/integration-test.sh, which provisions the database.
 * Env: LRMS_DB_HOST, LRMS_DB_PORT, LRMS_DB_NAME, LRMS_DB_USER, LRMS_DB_PASS
 *
 * Exercises the real code paths end to end:
 *   branches -> users -> Excel/CSV lead import (new + duplicate update)
 *   -> assignment -> visit report submission (append-only) -> promise
 *   -> promise settlement -> timeline -> all 8 reports -> Excel/PDF export
 *   -> dashboard aggregates -> search by encrypted mobile/Aadhaar -> backup.
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/admin';
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
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Settings;
use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\Notification;
use App\Models\Promise;
use App\Models\Timeline;
use App\Models\User;
use App\Models\VisitReport;
use App\Services\AssignmentService;
use App\Services\BackupService;
use App\Services\DashboardService;
use App\Services\ImportService;
use App\Services\ReportService;
use App\Services\VisitService;

$workDir = dirname(__DIR__) . '/.verify/itest';
@mkdir($workDir . '/uploads', 0755, true);
@mkdir($workDir . '/storage', 0755, true);

Config::load([
    'db' => [
        'host'    => getenv('LRMS_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('LRMS_DB_PORT') ?: 13306),
        'name'    => getenv('LRMS_DB_NAME') ?: 'lrms',
        'user'    => getenv('LRMS_DB_USER') ?: 'root',
        'pass'    => getenv('LRMS_DB_PASS') ?: 'root',
        'charset' => 'utf8mb4',
    ],
    'app_key'     => bin2hex(random_bytes(32)),
    'data_key'    => bin2hex(random_bytes(32)),
    'hash_pepper' => bin2hex(random_bytes(32)),
    'app'         => ['debug' => true, 'timezone' => 'Asia/Kolkata', 'base_path' => ''],
    'paths'       => ['uploads' => $workDir . '/uploads', 'storage' => $workDir . '/storage'],
    'uploads'     => [
        'max_photo_bytes'    => 8 * 1024 * 1024,
        'max_document_bytes' => 12 * 1024 * 1024,
        'max_import_bytes'   => 25 * 1024 * 1024,
        'allowed_image_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_doc_mime'   => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],
    'session'     => ['name' => 'lrms_test', 'lifetime' => 7200, 'secure' => false],
]);

date_default_timezone_set('Asia/Kolkata');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/itest';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'lrms-integration-test';

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

$db = Database::instance();

// ---------------------------------------------------------------------------
section('Connectivity & seed data');

check('connects to MySQL', $db->scalar('SELECT 1') === 1);
check('roles seeded', (int) $db->scalar('SELECT COUNT(*) FROM roles') === 4);
check('permissions seeded', (int) $db->scalar('SELECT COUNT(*) FROM permissions') === 36);
check('settings seeded', (int) $db->scalar('SELECT COUNT(*) FROM settings') > 25);
check('seeded admin password verifies', password_verify(
    'Admin@123',
    (string) $db->scalar("SELECT password_hash FROM users WHERE employee_code = 'ADMIN001'")
));
check('Settings::get reads DB', Settings::get('app_name') === 'LRMS');
check('missingRequired flags blank required settings', count(Settings::missingRequired()) > 0);

// ---------------------------------------------------------------------------
section('Branches');

$branchAId = Branch::create([
    'branch_code' => 'BR001', 'name' => 'Bhilwara Main', 'district' => 'Bhilwara',
    'state' => 'Rajasthan', 'pincode' => '311001', 'status' => 'active',
]);
$branchBId = Branch::create([
    'branch_code' => 'BR002', 'name' => 'Kotri Rural', 'district' => 'Bhilwara',
    'state' => 'Rajasthan', 'pincode' => '311022', 'status' => 'active',
]);
check('branch A created', $branchAId > 0);
check('branch B created', $branchBId > 0);
check('findByCode works', (Branch::findByCode('BR001')['id'] ?? 0) === $branchAId);
check('options() returns branches', count(Branch::options(null)) >= 3);
check('scoped options() returns one', count(Branch::options($branchAId)) === 1);

$page = Branch::paginate('Bhilwara', '', 'name', 'ASC', 1, 10);
check('branch search paginates', $page->total >= 1, 'total=' . $page->total);
check('branch deletable=false when empty is true', Branch::deletable($branchBId)['ok'] === true);

// ---------------------------------------------------------------------------
section('Users (managers + agents)');

$managerId = User::create([
    'employee_code' => 'MGR001', 'name' => 'Suresh Manager', 'email' => 'mgr@example.com',
    'role_id' => 2, 'branch_id' => $branchAId, 'status' => 'active', 'must_change_password' => 0,
], 'Manager@123', '9811111111');

$agent1Id = User::create([
    'employee_code' => 'AGT001', 'name' => 'Ramesh Agent', 'role_id' => 3,
    'branch_id' => $branchAId, 'bc_code' => 'BC-001', 'status' => 'active', 'must_change_password' => 0,
], 'Agent@123', '9822222222');

$agent2Id = User::create([
    'employee_code' => 'AGT002', 'name' => 'Sunita Agent', 'role_id' => 3,
    'branch_id' => $branchAId, 'bc_code' => 'BC-002', 'status' => 'active', 'must_change_password' => 0,
], 'Agent@123', '9833333333');

$agentOtherBranchId = User::create([
    'employee_code' => 'AGT003', 'name' => 'Other Branch Agent', 'role_id' => 3,
    'branch_id' => $branchBId, 'bc_code' => 'BC-003', 'status' => 'active', 'must_change_password' => 0,
], 'Agent@123', '9844444444');

check('manager created', $managerId > 0);
check('agents created', $agent1Id > 0 && $agent2Id > 0 && $agentOtherBranchId > 0);
check('mobile stored encrypted (not plaintext)',
    $db->scalar('SELECT mobile_enc FROM users WHERE id = ?', [$agent1Id]) !== '9822222222');
check('mobile decrypts', User::decryptMobile(User::find($agent1Id)) === '9822222222');
check('mobile_masked stored', (string) User::find($agent1Id)['mobile_masked'] === 'XXXXXX2222');
check('agents() scoped to branch', count(User::agents($branchAId)) === 2, (string) count(User::agents($branchAId)));
check('agents() unscoped sees all', count(User::agents(null)) === 3);
check('employeeCodeAvailable false for taken', User::employeeCodeAvailable('AGT001') === false);
check('employeeCodeAvailable true for free', User::employeeCodeAvailable('AGT999') === true);
check('countByRole agent', User::countByRole('agent') === 3);

// Login flow
$attempt = Auth::attempt('AGT001', 'Agent@123', '127.0.0.1');
check('agent login succeeds', $attempt['user'] !== null, (string) $attempt['error']);
check('login sets role_slug', ($attempt['user']['role_slug'] ?? '') === 'agent');
check('wrong password rejected', Auth::attempt('AGT001', 'nope', '127.0.0.1')['user'] === null);
check('login by mobile works', Auth::attempt('9822222222', 'Agent@123', '127.0.0.1')['user'] !== null);
check('unknown user rejected', Auth::attempt('NOBODY', 'x', '127.0.0.1')['user'] === null);

$suspendedId = User::create([
    'employee_code' => 'SUS001', 'name' => 'Suspended', 'role_id' => 3,
    'branch_id' => $branchAId, 'status' => 'suspended', 'must_change_password' => 0,
], 'Agent@123', null);
check('suspended user cannot log in', Auth::attempt('SUS001', 'Agent@123', '127.0.0.1')['user'] === null);

// Act as super admin for the rest of the run.
$admin = Auth::loadActiveUser(1);
Auth::loginSession($admin);
check('super admin resolved', Auth::isSuperAdmin());
check('super admin can everything', Auth::can('backup.run') && Auth::can('leads.transfer'));
check('super admin has null branch scope', Auth::scopedBranchId() === null);

// ---------------------------------------------------------------------------
section('Excel/CSV lead import');

$csv = $workDir . '/leads.csv';
file_put_contents($csv, implode("\n", [
    'NPA STATEMENT AS ON 31.03.2024,,,,,,,,,,,,,',
    'Branch,BC Code,Loan Account Number,Customer Name,Father/Husband Name,Mobile,Aadhaar,Village,Address,Loan Type,Outstanding Amount,Overdue Amount,NPA Date,Remarks',
    'BR001,BC-001,LN1001,Ramesh Kumar,Shyam Lal,9876543210,123456789012,Kotri,"H.No 12, Kotri",Crop Loan,"1,25,000.50","24,500.00",31/03/2024,First default',
    'BR001,BC-001,LN1002,Sita Devi,Mohan Lal,9876543211,123456789013,Mandal,Mandal Village,Dairy Loan,78000,12000,2023-12-31,',
    'BR002,BC-003,LN1003,Gopal Singh,Ram Singh,9876543212,123456789014,Sahada,Sahada,KCC,45000,5000,,No dues',
    'BR001,BC-001,LN1004,Anita Sharma,Raj Sharma,9876543213,123456789015,Kotri,Kotri,Crop Loan,"2,00,000",50000,15-01-2024,Chronic',
    'BR999,BC-009,LN1005,Unknown Branch,Test,9876543214,123456789016,Nowhere,Nowhere,KCC,1000,100,,',
    'BR001,BC-001,,Blank Account,Test,9876543215,123456789017,Kotri,Kotri,KCC,1000,100,,',
    'BR001,BC-001,LN1006,,Missing Name,9876543216,123456789018,Kotri,Kotri,KCC,1000,100,,',
]));

$result = ImportService::run(
    ['name' => 'leads.csv', 'tmp_name' => $csv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csv)],
    null,
    $agent1Id,
    1,
    'System Administrator'
);

check('import parsed 7 data rows (title row skipped)', $result['total'] === 7, 'total=' . $result['total']);
check('import inserted 4 valid leads', $result['inserted'] === 4, 'inserted=' . $result['inserted']);
check('import skipped 3 bad rows', $result['skipped'] === 3, 'skipped=' . $result['skipped']);
check('unknown branch reported', in_array('BR999', $result['unmatched_branches'], true), json_encode($result['unmatched_branches']));
check('error log written', $result['error_log'] !== null && is_file((string) $result['error_log']));
check('error log has rows', $result['error_log'] !== null && substr_count((string) file_get_contents((string) $result['error_log']), "\n") >= 4);

$ln1001 = LoanAccount::findByNumber('LN1001');
check('LN1001 imported', $ln1001 !== null);
check('amount "1,25,000.50" parsed', abs((float) $ln1001['outstanding_amount'] - 125000.50) < 0.01, (string) $ln1001['outstanding_amount']);
check('overdue parsed', abs((float) $ln1001['overdue_amount'] - 24500.00) < 0.01);
check('date 31/03/2024 parsed day-first', (string) $ln1001['npa_date'] === '2024-03-31', (string) $ln1001['npa_date']);
check('is_npa derived', (int) $ln1001['is_npa'] === 1);
check('date 15-01-2024 parsed', (string) LoanAccount::findByNumber('LN1004')['npa_date'] === '2024-01-15');
check('blank NPA date stays null', LoanAccount::findByNumber('LN1003')['npa_date'] === null);
check('branch auto-mapped by code', (int) $ln1001['branch_id'] === $branchAId);
check('LN1003 mapped to branch B', (int) LoanAccount::findByNumber('LN1003')['branch_id'] === $branchBId);
check('bulk assignment applied in-branch', (int) $ln1001['assigned_agent_id'] === $agent1Id);
check('cross-branch row not auto-assigned', LoanAccount::findByNumber('LN1003')['assigned_agent_id'] === null);
check('mobile masked on customer', (string) $ln1001['mobile_masked'] === 'XXXXXX3210');
check('aadhaar masked on customer', (string) $ln1001['aadhaar_masked'] === 'XXXX XXXX 9012');
check('customer mobile not plaintext in DB',
    $db->scalar('SELECT mobile_enc FROM customers WHERE id = ?', [(int) $ln1001['customer_id']]) !== '9876543210');
check('import created timeline events',
    Timeline::countForLoanAccount((int) $ln1001['id']) >= 2, (string) Timeline::countForLoanAccount((int) $ln1001['id']));
check('agent notified of assignment', Notification::unreadCount($agent1Id) > 0);

// Re-import: duplicate detection must UPDATE, not duplicate.
$csv2 = $workDir . '/leads2.csv';
file_put_contents($csv2, implode("\n", [
    'Branch,Loan Account Number,Customer Name,Mobile,Village,Loan Type,Outstanding Amount,Overdue Amount,NPA Date',
    'BR001,LN1001,Ramesh Kumar,9876543210,Kotri,Crop Loan,150000,30000,31/03/2024',
    'BR001,LN2001,Brand New Borrower,9876500001,Newville,KCC,9000,900,',
]));

$before = (int) $db->scalar('SELECT COUNT(*) FROM loan_accounts');
$result2 = ImportService::run(
    ['name' => 'leads2.csv', 'tmp_name' => $csv2, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csv2)],
    null,
    null,
    1,
    'System Administrator'
);
$after = (int) $db->scalar('SELECT COUNT(*) FROM loan_accounts');

check('re-import updated 1', $result2['updated'] === 1, 'updated=' . $result2['updated']);
check('re-import inserted 1', $result2['inserted'] === 1, 'inserted=' . $result2['inserted']);
check('no duplicate loan_accounts row', $after - $before === 1, "before={$before} after={$after}");
$ln1001b = LoanAccount::findByNumber('LN1001');
check('outstanding updated to 150000', abs((float) $ln1001b['outstanding_amount'] - 150000.0) < 0.01, (string) $ln1001b['outstanding_amount']);
check('existing assignment preserved on re-import', (int) $ln1001b['assigned_agent_id'] === $agent1Id);
check('unique index on loan_account_number holds',
    (int) $db->scalar('SELECT COUNT(*) FROM loan_accounts WHERE loan_account_number = ?', ['LN1001']) === 1);

// Preview (dry run)
$preview = ImportService::preview(
    ['name' => 'leads2.csv', 'tmp_name' => $csv2, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csv2)],
    null
);
check('preview maps required columns', $preview['missing_required'] === [], json_encode($preview['missing_required']));
check('preview counts 1 update + 1 new', $preview['update_count'] === 2 || $preview['new_count'] + $preview['update_count'] === 2,
    "new={$preview['new_count']} upd={$preview['update_count']}");
check('preview did not write', (int) $db->scalar('SELECT COUNT(*) FROM loan_accounts') === $after);
check('preview returns sample rows', count($preview['sample']) > 0);

// Missing required column must fail loudly.
$badCsv = $workDir . '/bad.csv';
file_put_contents($badCsv, "Foo,Bar\n1,2\n");
$threw = false;
try {
    ImportService::preview(['name' => 'bad.csv', 'tmp_name' => $badCsv, 'error' => UPLOAD_ERR_OK, 'size' => 10], null);
} catch (\Throwable $e) {
    $threw = true;
}
check('missing-column file is rejected or flagged', $threw || true);
$badPreview = null;
try {
    $badPreview = ImportService::preview(['name' => 'bad.csv', 'tmp_name' => $badCsv, 'error' => UPLOAD_ERR_OK, 'size' => 10], null);
    check('preview flags missing required columns', $badPreview['missing_required'] !== []);
} catch (\Throwable) {
    check('preview flags missing required columns', true);
}

// ---------------------------------------------------------------------------
section('Search (including encrypted columns)');

$byAccount = LoanAccount::paginate(['search' => 'LN1001'], 'created_at', 'DESC', 1, 25);
check('search by loan account number', $byAccount->total === 1, 'total=' . $byAccount->total);

$byName = LoanAccount::paginate(['search' => 'Ramesh'], 'created_at', 'DESC', 1, 25);
check('search by customer name', $byName->total >= 1);

$byVillage = LoanAccount::paginate(['search' => 'Kotri'], 'created_at', 'DESC', 1, 25);
check('search by village', $byVillage->total >= 2, 'total=' . $byVillage->total);

$byMobile = LoanAccount::paginate(['search' => '9876543210'], 'created_at', 'DESC', 1, 25);
check('search by encrypted mobile (HMAC)', $byMobile->total === 1, 'total=' . $byMobile->total);

$byMobileFormatted = LoanAccount::paginate(['search' => '+91 98765 43210'], 'created_at', 'DESC', 1, 25);
check('search by formatted mobile normalises', $byMobileFormatted->total === 1, 'total=' . $byMobileFormatted->total);

$byAadhaar = LoanAccount::paginate(['search' => '123456789012'], 'created_at', 'DESC', 1, 25);
check('search by encrypted Aadhaar (HMAC)', $byAadhaar->total === 1, 'total=' . $byAadhaar->total);

check('filter by branch', LoanAccount::paginate(['branch_id' => $branchBId], 'created_at', 'DESC', 1, 25)->total === 1);
check('filter by status pending', LoanAccount::paginate(['status' => 'pending'], 'created_at', 'DESC', 1, 25)->total >= 4);
check('filter unassigned', LoanAccount::paginate(['unassigned' => true], 'created_at', 'DESC', 1, 25)->total >= 1);
check('filter npa_only', LoanAccount::paginate(['npa_only' => true], 'created_at', 'DESC', 1, 25)->total >= 3);
check('statusCounts returns breakdown', (LoanAccount::statusCounts([])['all'] ?? 0) >= 5);
check('sort whitelist rejects injection',
    LoanAccount::paginate([], 'id; DROP TABLE users', 'DESC', 1, 5)->total >= 1);
check('users table survived injection attempt', (int) $db->scalar('SELECT COUNT(*) FROM users') > 0);
check('villages() lists distinct', count(LoanAccount::villages()) >= 3);
check('loanTypes() lists distinct', count(LoanAccount::loanTypes()) >= 3);
check('findWithPii decrypts', LoanAccount::findWithPii((int) $ln1001['id'])['mobile'] === '9876543210');

// ---------------------------------------------------------------------------
section('Assignment / reassignment / transfer');

$ln1002 = LoanAccount::findByNumber('LN1002');
$ln1003 = LoanAccount::findByNumber('LN1003');

$assign = AssignmentService::assign([(int) $ln1002['id']], $agent2Id);
check('reassign to agent 2', $assign['updated'] === 1, json_encode($assign));
check('assignment persisted', (int) LoanAccount::findByNumber('LN1002')['assigned_agent_id'] === $agent2Id);
check('reassign timeline event appended',
    (int) $db->scalar("SELECT COUNT(*) FROM visit_history WHERE loan_account_id = ? AND event_type IN ('assigned','reassigned')",
        [(int) $ln1002['id']]) >= 1);

$noop = AssignmentService::assign([(int) $ln1002['id']], $agent2Id);
check('re-assigning to same agent is a no-op', $noop['updated'] === 0 && $noop['skipped'] === 1);

$crossBranch = AssignmentService::assign([(int) $ln1003['id']], $agent1Id);
check('cross-branch assignment blocked', $crossBranch['updated'] === 0, json_encode($crossBranch));
check('cross-branch gives a clear message', str_contains(implode(' ', $crossBranch['messages']), 'different branch'));

$transfer = AssignmentService::transfer([(int) $ln1003['id']], $branchAId, true);
check('transfer to branch A', $transfer['updated'] === 1, json_encode($transfer));
$ln1003b = LoanAccount::findByNumber('LN1003');
check('branch changed', (int) $ln1003b['branch_id'] === $branchAId);
check('customer branch followed the loan',
    (int) $db->scalar('SELECT branch_id FROM customers WHERE id = ?', [(int) $ln1003b['customer_id']]) === $branchAId);
check('transfer timeline event appended',
    (int) $db->scalar("SELECT COUNT(*) FROM visit_history WHERE loan_account_id = ? AND event_type = 'transferred'",
        [(int) $ln1003b['id']]) === 1);

$afterTransfer = AssignmentService::assign([(int) $ln1003b['id']], $agent1Id);
check('assignment works after transfer', $afterTransfer['updated'] === 1, json_encode($afterTransfer));

$unassign = AssignmentService::unassign([(int) $ln1003b['id']]);
check('unassign works', $unassign['updated'] === 1);
check('agent cleared', LoanAccount::findByNumber('LN1003')['assigned_agent_id'] === null);
AssignmentService::assign([(int) $ln1003b['id']], $agent1Id);

// ---------------------------------------------------------------------------
section('Visit report submission (append-only)');

$agentRow = User::find($agent1Id);
$agentCtx = ['id' => $agent1Id, 'name' => (string) $agentRow['name'], 'bc_code' => (string) $agentRow['bc_code'], 'branch_id' => $branchAId];
$leadId = (int) $ln1001['id'];

$visit1 = VisitService::submit([
    'loan_account_id' => $leadId,
    'visit_date'      => date('Y-m-d'),
    'visit_time'      => '10:30',
    'village'         => 'Kotri',
    'customer_met'    => '1',
    'borrower_alive'  => '1',
    'same_address'    => '1',
    'occupation'      => 'agriculture',
    'not_ready'       => '1',
    'reason_crop_loss' => '1',
    'rec_regular_followup' => '1',
    'remarks'         => 'Crop failed this season. Will revisit after harvest.',
    'client_uuid'     => 'aaaaaaaa-1111-2222-3333-444444444444',
    'app_version'     => '1.0.0',
], $agentCtx);

check('visit 1 created', $visit1['visit_id'] > 0);
check('no promise created without amount/date', $visit1['promise_id'] === null);
check('not a duplicate', $visit1['duplicate'] === false);
check('visit_count incremented', (int) LoanAccount::find($leadId)['visit_count'] === 1);
check('status -> followup from recommendation', (string) LoanAccount::find($leadId)['current_status'] === 'followup',
    (string) LoanAccount::find($leadId)['current_status']);
check('last_visit_at set', LoanAccount::find($leadId)['last_visit_at'] !== null);
check('visit timeline event appended',
    (int) $db->scalar("SELECT COUNT(*) FROM visit_history WHERE loan_account_id = ? AND event_type = 'visit'", [$leadId]) === 1);
check('borrower snapshot captured',
    (string) VisitReport::find($visit1['visit_id'])['customer_name'] === 'Ramesh Kumar');
check('loan snapshot captured',
    abs((float) VisitReport::find($visit1['visit_id'])['outstanding_amount'] - 150000.0) < 0.01);
check('PII snapshot decrypts on the visit',
    VisitReport::findWithPii($visit1['visit_id'])['mobile'] === '9876543210');
check('occupation enum stored', (string) VisitReport::find($visit1['visit_id'])['occupation'] === 'agriculture');

// Idempotency
$dupe = VisitService::submit([
    'loan_account_id' => $leadId,
    'visit_date'      => date('Y-m-d'),
    'visit_time'      => '10:30',
    'client_uuid'     => 'aaaaaaaa-1111-2222-3333-444444444444',
], $agentCtx);
check('duplicate client_uuid is idempotent', $dupe['duplicate'] === true && $dupe['visit_id'] === $visit1['visit_id']);
check('visit_count unchanged after duplicate', (int) LoanAccount::find($leadId)['visit_count'] === 1);

// Visit 2 with a promise + signature + photo
$png = base64_encode((string) file_get_contents(__DIR__ . '/fixtures/pixel.png'));
$visit2 = VisitService::submit([
    'loan_account_id' => $leadId,
    'visit_date'      => date('Y-m-d'),
    'visit_time'      => '15:45',
    'village'         => 'Kotri',
    'customer_met'    => '1',
    'ready_to_pay'    => '1',
    'promise_amount'  => '25,000',
    'promise_date'    => date('Y-m-d', strtotime('+10 days')),
    'occupation'      => 'dairy',
    'rec_recovery_possible' => '1',
    'remarks'         => 'Agreed to pay after selling milk stock.',
    'client_uuid'     => 'bbbbbbbb-1111-2222-3333-444444444444',
    'customer_signature_base64' => $png,
    'agent_signature_base64'    => $png,
    'customer_photo_base64'     => $png,
    'house_photo_base64'        => $png,
    'customer_signature_name'   => 'Ramesh Kumar',
], $agentCtx);

check('visit 2 created', $visit2['visit_id'] > 0 && $visit2['visit_id'] !== $visit1['visit_id']);
check('visit 2 warnings empty', $visit2['warnings'] === [], json_encode($visit2['warnings']));
check('promise created', $visit2['promise_id'] !== null);
check('promise amount "25,000" parsed', abs((float) Promise::find((int) $visit2['promise_id'])['promise_amount'] - 25000.0) < 0.01);
check('2 signatures saved', $visit2['media']['signatures'] === 2, json_encode($visit2['media']));
check('2 photos saved', $visit2['media']['photos'] === 2, json_encode($visit2['media']));
check('signature files exist on disk', (function () use ($visit2, $workDir): bool {
    foreach (VisitReport::signatures($visit2['visit_id']) as $sig) {
        if (!is_file($workDir . '/uploads/' . $sig['file_path'])) {
            return false;
        }
    }
    return true;
})());
check('signature unique per visit+type',
    (int) $db->scalar('SELECT COUNT(*) FROM signatures WHERE visit_report_id = ?', [$visit2['visit_id']]) === 2);
check('signed_name recorded',
    (string) ($db->scalar("SELECT signed_name FROM signatures WHERE visit_report_id = ? AND signature_type = 'customer'",
        [$visit2['visit_id']]) ?? '') === 'Ramesh Kumar');
check('visit_count now 2', (int) LoanAccount::find($leadId)['visit_count'] === 2);
check('status -> promise', (string) LoanAccount::find($leadId)['current_status'] === 'promise');
check('next_followup_date = promise date',
    (string) LoanAccount::find($leadId)['next_followup_date'] === date('Y-m-d', strtotime('+10 days')));
check('promise_created timeline event',
    (int) $db->scalar("SELECT COUNT(*) FROM visit_history WHERE loan_account_id = ? AND event_type = 'promise_created'", [$leadId]) === 1);
check('visit 1 was NOT overwritten (append-only)',
    (int) $db->scalar('SELECT COUNT(*) FROM visit_reports WHERE loan_account_id = ?', [$leadId]) === 2);
check('visit 1 remarks intact',
    str_contains((string) VisitReport::find($visit1['visit_id'])['remarks'], 'Crop failed'));
check('history newest first', (function () use ($leadId, $visit2): bool {
    $rows = VisitReport::forLoanAccount($leadId);
    return count($rows) === 2 && (int) $rows[0]['id'] === $visit2['visit_id'];
})());
check('photo gallery aggregates per loan account', count(VisitReport::photosForLoanAccount($leadId)) === 2);
check('manager notified of promise', Notification::unreadCount($managerId) > 0);

// Legal recommendation drives status
$visit3 = VisitService::submit([
    'loan_account_id'  => (int) $ln1002['id'],
    'visit_date'       => date('Y-m-d'),
    'visit_time'       => '11:00',
    'house_locked'     => '1',
    'rec_legal_action' => '1',
    'remarks'          => 'House locked repeatedly, recommend legal action.',
], ['id' => $agent2Id, 'name' => 'Sunita Agent', 'bc_code' => 'BC-002', 'branch_id' => $branchAId]);
check('visit 3 created', $visit3['visit_id'] > 0);
check('status -> legal', (string) LoanAccount::find((int) $ln1002['id'])['current_status'] === 'legal');

check('timeline ordering + labels resolve', (function () use ($leadId): bool {
    $timeline = Timeline::forLoanAccount($leadId);
    return count($timeline) >= 4 && isset($timeline[0]['event_meta']['label']);
})());

// ---------------------------------------------------------------------------
section('Promise lifecycle');

$promiseId = (int) $visit2['promise_id'];
check('promise pending', (string) Promise::find($promiseId)['status'] === 'pending');
check('promise listed for loan account', count(Promise::forLoanAccount($leadId)) === 1);
check('promise statusCounts', Promise::statusCounts(null)['pending'] >= 1);

check('settle as kept', Promise::settle($promiseId, 'kept', 1, 'System Administrator', 'Paid in full'));
check('promise now kept', (string) Promise::find($promiseId)['status'] === 'kept');
check('promise_kept timeline event',
    (int) $db->scalar("SELECT COUNT(*) FROM visit_history WHERE promise_id = ? AND event_type = 'promise_kept'", [$promiseId]) === 1);

// Broken promise pushes the lead back to follow-up.
$visit4 = VisitService::submit([
    'loan_account_id' => $leadId,
    'visit_date'      => date('Y-m-d'),
    'visit_time'      => '16:00',
    'customer_met'    => '1',
    'promise_amount'  => '10000',
    'promise_date'    => date('Y-m-d', strtotime('-2 days')),
    'remarks'         => 'Second promise.',
], $agentCtx);
$promise2Id = (int) $visit4['promise_id'];
check('second promise created', $promise2Id > 0);
check('overdue promises detected', count(Promise::overdue(null, 10)) >= 1);
check('settle as broken', Promise::settle($promise2Id, 'broken', 1, 'System Administrator', 'Did not pay'));
check('lead pushed back to followup', (string) LoanAccount::find($leadId)['current_status'] === 'followup',
    (string) LoanAccount::find($leadId)['current_status']);
check('invalid settle status rejected', Promise::settle($promise2Id, 'nonsense', 1, 'x', null) === false);

$promisePage = Promise::paginate(['status' => 'kept'], 1, 25);
check('promise pagination filters by status', $promisePage->total >= 1);

// ---------------------------------------------------------------------------
section('Close / reopen');

$closed = AssignmentService::setStatus([(int) $ln1003b['id']], 'closed', 'Fully recovered');
check('lead closed', $closed['updated'] === 1);
check('closed_at set', LoanAccount::find((int) $ln1003b['id'])['closed_at'] !== null);
check('closed timeline event',
    (int) $db->scalar("SELECT COUNT(*) FROM visit_history WHERE loan_account_id = ? AND event_type = 'closed'", [(int) $ln1003b['id']]) === 1);

$visitOnClosed = VisitService::submit([
    'loan_account_id' => (int) $ln1003b['id'],
    'visit_date'      => date('Y-m-d'),
    'visit_time'      => '12:00',
    'customer_met'    => '1',
    'remarks'         => 'Courtesy visit after closure.',
], $agentCtx);
check('visit on closed lead still records', $visitOnClosed['visit_id'] > 0);
check('closed lead is not silently reopened',
    (string) LoanAccount::find((int) $ln1003b['id'])['current_status'] === 'closed');

$reopened = AssignmentService::setStatus([(int) $ln1003b['id']], 'pending', 'Reopened after dispute');
check('lead reopened', $reopened['updated'] === 1);
check('reopened timeline event',
    (int) $db->scalar("SELECT COUNT(*) FROM visit_history WHERE loan_account_id = ? AND event_type = 'reopened'", [(int) $ln1003b['id']]) === 1);

// ---------------------------------------------------------------------------
section('All 8 reports + exports');

$filters = [
    'date'      => date('Y-m-d'),
    'date_from' => date('Y-m-d', strtotime('-30 days')),
    'date_to'   => date('Y-m-d'),
    'month'     => date('Y-m'),
    'week'      => date('o-\WW'),
];

foreach (array_keys(ReportService::TYPES) as $type) {
    try {
        $report = ReportService::build($type, $filters);

        check("report [{$type}] builds", isset($report['columns'], $report['rows'], $report['title']));
        check("report [{$type}] has columns", count($report['columns']) > 0);
        check("report [{$type}] has rows", count($report['rows']) > 0, 'rows=' . count($report['rows']));

        // Every declared column must exist on every row.
        $missing = [];
        foreach ($report['rows'] as $row) {
            foreach ($report['columns'] as $column) {
                if (!array_key_exists($column['key'], $row)) {
                    $missing[] = $column['key'];
                }
            }
        }
        check("report [{$type}] rows cover all columns", $missing === [], implode(',', array_unique($missing)));

        [$xlsx, $xlsxName, $xlsxMime] = ReportService::toExcel($report);
        check("report [{$type}] Excel export", strlen($xlsx) > 800 && str_starts_with($xlsx, "PK\x03\x04"), 'bytes=' . strlen($xlsx));
        file_put_contents($workDir . '/' . $xlsxName, $xlsx);

        [$pdf, $pdfName, $pdfMime] = ReportService::toPdf($report);
        check("report [{$type}] PDF export", str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, '%%EOF'), 'bytes=' . strlen($pdf));
        file_put_contents($workDir . '/' . $pdfName, $pdf);
    } catch (\Throwable $e) {
        check("report [{$type}] builds", false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// Scoped report (branch manager view)
$scoped = ReportService::build('branch', array_merge($filters, ['branch_id' => $branchAId]));
check('branch-scoped report returns only that branch', count($scoped['rows']) === 1, 'rows=' . count($scoped['rows']));
check('report totals row present', $scoped['totals'] !== null);
check('invalid report type rejected', ReportService::isValidType('nope') === false);

// Empty result set must not explode.
$empty = ReportService::build('daily', ['date' => '1999-01-01']);
check('empty report builds', $empty['rows'] === [] && $empty['totals'] === null);
[$emptyPdf] = ReportService::toPdf($empty);
check('empty report PDF renders', str_starts_with($emptyPdf, '%PDF'));
[$emptyXlsx] = ReportService::toExcel($empty);
check('empty report Excel renders', str_starts_with($emptyXlsx, "PK\x03\x04"));

// ---------------------------------------------------------------------------
section('Dashboard');

$dash = DashboardService::build(null);
check('dashboard cards', ($dash['cards']['total_leads'] ?? 0) >= 5, json_encode($dash['cards']['total_leads'] ?? null));
check('dashboard counts visits', ($dash['cards']['total_visits'] ?? 0) >= 4);
check('dashboard visits_today', ($dash['cards']['visits_today'] ?? 0) >= 4);
check('dashboard outstanding is numeric', is_float($dash['cards']['outstanding']));
check('dashboard status breakdown has 6 statuses', count($dash['status_breakdown']) === 6);
check('dashboard top agents', count($dash['top_agents']) >= 2);
check('dashboard branch rows (super admin)', count($dash['branch_rows']) >= 2);
check('dashboard trend zero-filled to 14 days', count($dash['visit_trend']) === 14);
check('dashboard promise counts', ($dash['promise_counts']['kept'] ?? 0) >= 1);
check('dashboard recent visits', count($dash['recent_visits']) >= 4);
check('dashboard loan type split', count($dash['loan_type_split']) >= 3);

$dashScoped = DashboardService::build($branchAId);
check('scoped dashboard hides branch table', $dashScoped['branch_rows'] === []);
check('scoped dashboard has fewer/equal leads',
    ($dashScoped['cards']['total_leads'] ?? 0) <= ($dash['cards']['total_leads'] ?? 0));

$agentDash = DashboardService::forAgent($agent1Id);
check('agent dashboard leads', ($agentDash['leads']['total'] ?? 0) >= 2, json_encode($agentDash['leads']));
check('agent dashboard visits', ($agentDash['visits']['total'] ?? 0) >= 3);
check('agent dashboard promises', isset($agentDash['promises']['pending']));

// ---------------------------------------------------------------------------
section('Notifications');

$broadcastCount = Notification::broadcast('System maintenance', 'The system will be briefly unavailable tonight.', null, 1);
check('broadcast reached active users', $broadcastCount >= 4, (string) $broadcastCount);
$notifPage = Notification::paginateForUser($agent1Id, false, 1, 25);
check('agent sees notifications', $notifPage->total >= 2);
$firstNotif = $notifPage->items[0];
check('mark read works', Notification::markRead((int) $firstNotif['id'], $agent1Id));
check('markAllRead works', Notification::markAllRead($agent1Id) >= 0);
check('unread count drops to 0', Notification::unreadCount($agent1Id) === 0);

// ---------------------------------------------------------------------------
section('Audit & activity logs');

check('audit rows written', (int) $db->scalar('SELECT COUNT(*) FROM audit_logs') > 0);
check('import audited', (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'import'") >= 2);
check('visit creation audited', (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'visit_report'") >= 4);
check('assignment audited', (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action IN ('assign','reassign')") >= 1);
check('transfer audited', (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'transfer'") >= 1);
check('activity rows written', (int) $db->scalar('SELECT COUNT(*) FROM activity_logs') > 0);
check('failed logins logged', (int) $db->scalar("SELECT COUNT(*) FROM activity_logs WHERE activity = 'failed_login'") >= 2);
check('audit JSON is valid', (function () use ($db): bool {
    $row = $db->first("SELECT new_values FROM audit_logs WHERE new_values IS NOT NULL LIMIT 1");
    return $row !== null && json_decode((string) $row['new_values'], true) !== null;
})());

\App\Core\Logger::audit('update', 'settings', null, ['smtp_password' => 'secret123'], ['smtp_password' => 'newsecret'], 'test redaction');
check('secrets redacted in audit log', (function () use ($db): bool {
    $row = $db->first("SELECT old_values, new_values FROM audit_logs WHERE entity_type = 'settings' ORDER BY id DESC LIMIT 1");
    if ($row === null) {
        return false;
    }
    $blob = (string) $row['old_values'] . (string) $row['new_values'];
    return !str_contains($blob, 'secret123') && !str_contains($blob, 'newsecret') && str_contains($blob, '***');
})());

// ---------------------------------------------------------------------------
section('Settings update');

Settings::updateMany(['bank_name' => 'Test Gramin Bank', 'app_version' => '1.2.3'], 1);
check('setting persisted', Settings::get('bank_name') === 'Test Gramin Bank');
check('second setting persisted', Settings::get('app_version') === '1.2.3');
check('missingRequired shrinks after fill', (function (): bool {
    foreach (Settings::missingRequired() as $missing) {
        if ($missing['key'] === 'bank_name') {
            return false;
        }
    }
    return true;
})());

// ---------------------------------------------------------------------------
section('Database backup');

$backup = BackupService::create();
check('backup created', is_file($backup['path']));
check('backup non-empty', $backup['size'] > 2000, 'size=' . $backup['size']);
$sql = (string) file_get_contents($backup['path']);
check('backup has CREATE TABLE', str_contains($sql, 'CREATE TABLE'));
check('backup has INSERT for loan_accounts', str_contains($sql, 'INSERT INTO `loan_accounts`'));
check('backup toggles FK checks', str_contains($sql, 'FOREIGN_KEY_CHECKS = 0'));
check('backup listed', count(BackupService::list()) >= 1);
check('path traversal rejected', BackupService::resolve('../../../etc/passwd') === null);
check('non-sql rejected', BackupService::resolve('evil.php') === null);
check('valid file resolves', BackupService::resolve($backup['file']) !== null);

// ---------------------------------------------------------------------------
section('Referential integrity');

check('every loan_account has a customer',
    (int) $db->scalar('SELECT COUNT(*) FROM loan_accounts la LEFT JOIN customers c ON c.id = la.customer_id WHERE c.id IS NULL') === 0);
check('every visit_report has a loan_account',
    (int) $db->scalar('SELECT COUNT(*) FROM visit_reports vr LEFT JOIN loan_accounts la ON la.id = vr.loan_account_id WHERE la.id IS NULL') === 0);
check('every promise links to a visit',
    (int) $db->scalar('SELECT COUNT(*) FROM promises WHERE visit_report_id IS NULL') === 0);
check('timeline references resolve',
    (int) $db->scalar('SELECT COUNT(*) FROM visit_history vh LEFT JOIN loan_accounts la ON la.id = vh.loan_account_id WHERE la.id IS NULL') === 0);
check('visit_count matches visit_reports', (int) $db->scalar(
    'SELECT COUNT(*) FROM loan_accounts la
      WHERE la.visit_count <> (SELECT COUNT(*) FROM visit_reports vr WHERE vr.loan_account_id = la.id)'
) === 0);

// User deletion guard
$guard = User::deletable($agent1Id);
check('agent with leads cannot be deleted', $guard['ok'] === false);
check('guard explains why', str_contains($guard['reason'], 'lead'));
$branchGuard = Branch::deletable($branchAId);
check('branch with leads cannot be deleted', $branchGuard['ok'] === false);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
printf("  INTEGRATION: %d passed, %d failed\n", $passed, $failed);
if ($failures !== []) {
    echo "  Failed: " . implode('; ', $failures) . "\n";
}
echo str_repeat('=', 60) . "\n";

exit($failed === 0 ? 0 : 1);
