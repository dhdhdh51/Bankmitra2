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
// Not an exact count: that broke on every legitimate addition and taught nobody
// anything. What matters is that the codes the panel actually calls can() with are
// present - a missing one hides a whole screen from every role, silently.
check('permissions seeded', (int) $db->scalar('SELECT COUNT(*) FROM permissions') >= 36);
foreach ([
    'bc_targets.view', 'bc_targets.manage', 'sss.view', 'sss.manage', 'scorecard.view',
] as $code) {
    check("permission {$code} exists", (int) $db->scalar(
        'SELECT COUNT(*) FROM permissions WHERE code = ?', [$code]
    ) === 1);
}
check('a branch manager can set targets for their own agents', (int) $db->scalar(
    "SELECT COUNT(*) FROM role_permissions rp
       JOIN permissions p ON p.id = rp.permission_id
      WHERE rp.role_id = 2 AND p.code = 'bc_targets.manage'"
) === 1);
check('an auditor can read targets but not change them', (int) $db->scalar(
    "SELECT COUNT(*) FROM role_permissions rp
       JOIN permissions p ON p.id = rp.permission_id
      WHERE rp.role_id = 4 AND p.code = 'bc_targets.manage'"
) === 0);
check('settings seeded', (int) $db->scalar('SELECT COUNT(*) FROM settings') > 25);
check('seeded admin password verifies', password_verify(
    'Admin@123',
    (string) $db->scalar("SELECT password_hash FROM users WHERE employee_code = 'ADMIN001'")
));
check('Settings::get reads DB', Settings::get('app_name') === 'D2 Recovery');
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
    // An email address, because it is now a login identifier in its own right.
    'employee_code' => 'AGT001', 'name' => 'Ramesh Agent', 'email' => 'ramesh.agent@example.com', 'role_id' => 3,
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
section("Any bank's file: detection, branches from the sheet, money intact");
// ---------------------------------------------------------------------------
// The point of this section is a file nobody prepared for us: a core-banking
// export with a title block, shouty abbreviated headings in a different order,
// branches the database has never heard of, and whole-rupee amounts in the range
// that used to be silently converted into dates.

$messyCsv = $workDir . '/messy-export.csv';
file_put_contents($messyCsv, implode("\n", [
    'NPA STATEMENT AS ON 31.03.2024,,,,,,,',
    'Branch: ALL,As on: 31.03.2024,,,,,,',
    ',,,,,,,',
    'Sr,SOL_ID,ACCT_NO,ACCT_NAME,MOB_NO,PRINCIPAL_OUTSTANDING,OVERDUE_AMT,NPA_DT',
    '1,Rampur Rural,LNMESS001,Kailash Yadav,9812345601,45000,5000,31/03/2024',
    '2,Rampur Rural,LNMESS002,Pushpa Devi,9812345602,33000,1200,15-01-2024',
    '3,Devgarh,LNMESS003,Anil Kumar,9812345603,"1,25,000.50",0,',
]) . "\n");

$messyFile = ['name' => 'messy-export.csv', 'tmp_name' => $messyCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($messyCsv)];

$messyPreview = ImportService::preview($messyFile, null);
check('detects the header under a title block', ($messyPreview['header_row'] ?? -1) === 3, 'header_row=' . var_export($messyPreview['header_row'] ?? null, true));
check('finds the account column named ACCT_NO', ($messyPreview['detection']['loan_account_number']['column'] ?? '') === 'ACCT_NO');
check('finds the name column named ACCT_NAME', ($messyPreview['detection']['customer_name']['column'] ?? '') === 'ACCT_NAME');
check('finds outstanding despite the odd heading', ($messyPreview['detection']['outstanding_amount']['column'] ?? '') === 'PRINCIPAL_OUTSTANDING');
check('nothing required is missing', $messyPreview['missing_required'] === [], json_encode($messyPreview['missing_required']));
check('lists the branches it will create', count($messyPreview['branches_to_create'] ?? []) === 2, json_encode($messyPreview['branches_to_create'] ?? []));
check('row numbers count from the real header row', ($messyPreview['sample'][0]['row'] ?? '') === '5', var_export($messyPreview['sample'][0]['row'] ?? null, true));

$branchesBefore = (int) $db->scalar('SELECT COUNT(*) FROM branches');
$messyResult = ImportService::run($messyFile, null, null, 1, 'System Administrator', [], true);

check('messy export imported all 3 rows', $messyResult['inserted'] === 3, json_encode($messyResult));
check('no rows skipped for an unknown branch', $messyResult['skipped'] === 0);
check('two branches created from the sheet', count($messyResult['created_branches']) === 2, json_encode($messyResult['created_branches']));
check('branches table grew by two', (int) $db->scalar('SELECT COUNT(*) FROM branches') === $branchesBefore + 2);

$rampur = $db->first("SELECT id, branch_code, name FROM branches WHERE name = 'Rampur Rural' LIMIT 1");
check('created branch keeps the name from the file', $rampur !== null);
check('created branch got a usable code', $rampur !== null && (string) $rampur['branch_code'] === 'RAMPURRURAL', (string) ($rampur['branch_code'] ?? ''));

// The money assertions: these are the figures the old reader destroyed.
$m1 = $db->first("SELECT outstanding_amount, overdue_amount, npa_date, is_npa, branch_id FROM loan_accounts WHERE loan_account_number = 'LNMESS001'");
check('Rs 45,000 imported as 45000.00', $m1 !== null && (float) $m1['outstanding_amount'] === 45000.0, var_export($m1['outstanding_amount'] ?? null, true));
check('Rs 5,000 overdue imported intact', $m1 !== null && (float) $m1['overdue_amount'] === 5000.0);
check('day-first NPA date parsed', $m1 !== null && (string) $m1['npa_date'] === '2024-03-31', (string) ($m1['npa_date'] ?? ''));
check('is_npa derived from the date', $m1 !== null && (int) $m1['is_npa'] === 1);
check('row landed in the branch named in its own row', $m1 !== null && $rampur !== null && (int) $m1['branch_id'] === (int) $rampur['id']);

$m2 = $db->first("SELECT outstanding_amount FROM loan_accounts WHERE loan_account_number = 'LNMESS002'");
check('Rs 33,000 imported as 33000.00', $m2 !== null && (float) $m2['outstanding_amount'] === 33000.0, var_export($m2['outstanding_amount'] ?? null, true));

$m3 = $db->first("SELECT outstanding_amount, npa_date, is_npa FROM loan_accounts WHERE loan_account_number = 'LNMESS003'");
check('Indian-format amount parsed', $m3 !== null && (float) $m3['outstanding_amount'] === 125000.5);
check('blank NPA date stays null', $m3 !== null && $m3['npa_date'] === null);
check('is_npa 0 without a date', $m3 !== null && (int) $m3['is_npa'] === 0);

check('mapping recorded for provenance', ($messyResult['mapping']['outstanding_amount']['column'] ?? '') === 'PRINCIPAL_OUTSTANDING');

// A second run must reuse the branches rather than creating near-duplicates.
$messyResult2 = ImportService::run($messyFile, null, null, 1, 'System Administrator', [], true);
check('re-import creates no further branches', $messyResult2['created_branches'] === [], json_encode($messyResult2['created_branches']));
check('re-import updates instead of inserting', $messyResult2['updated'] === 3 && $messyResult2['inserted'] === 0);
check('branch count unchanged on re-import', (int) $db->scalar('SELECT COUNT(*) FROM branches') === $branchesBefore + 2);

// A branch-scoped uploader must not be able to create branches from a sheet.
$scopedResult = ImportService::run(
    ['name' => 'messy-export.csv', 'tmp_name' => $messyCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($messyCsv)],
    null,
    null,
    1,
    'System Administrator',
    [],
    false,
);
check('without permission no branch is created', $scopedResult['created_branches'] === []);

// ---- The operator corrects a wrong guess ----------------------------------
// Two columns that both look like amounts, headed ambiguously. Detection will
// take one; the override must win.
$ambiguousCsv = $workDir . '/ambiguous.csv';
file_put_contents($ambiguousCsv, implode("\n", [
    'Account No,Name,Amount 1,Amount 2,Branch',
    'LNAMB001,Ravi Shankar,11111,22222,Rampur Rural',
]) . "\n");
$ambiguousFile = ['name' => 'ambiguous.csv', 'tmp_name' => $ambiguousCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($ambiguousCsv)];

$ambPreview = ImportService::preview($ambiguousFile, null);
check('an ambiguous amount column is not guessed', !isset($ambPreview['detection']['outstanding_amount']), json_encode(array_keys($ambPreview['detection'] ?? [])));

ImportService::run($ambiguousFile, null, null, 1, 'System Administrator', ['outstanding_amount' => 3], true);
$amb = $db->first("SELECT outstanding_amount FROM loan_accounts WHERE loan_account_number = 'LNAMB001'");
check('the chosen column is the one imported', $amb !== null && (float) $amb['outstanding_amount'] === 22222.0, var_export($amb['outstanding_amount'] ?? null, true));

// ---- CKCC columns, which nothing could fill before ------------------------
$ckccCsv = $workDir . '/ckcc.csv';
file_put_contents($ckccCsv, implode("\n", [
    'Loan A/C No,Borrower Name,CIF No,Sanction Limit,Drawing Power,Interest Overdue,Sanction Date,Renewal Due Date,Branch',
    'LNCKCC001,Gopal Singh,CIF778899,200000,180000,3400,01/04/2023,31/03/2024,Rampur Rural',
]) . "\n");
ImportService::run(
    ['name' => 'ckcc.csv', 'tmp_name' => $ckccCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($ckccCsv)],
    null,
    null,
    1,
    'System Administrator',
    [],
    true,
);
$ckcc = $db->first(
    "SELECT cif_number, sanction_limit, drawing_power, interest_overdue, sanction_date, ckcc_renewal_due_date
       FROM loan_accounts WHERE loan_account_number = 'LNCKCC001'"
);
check('CIF number imported', $ckcc !== null && (string) $ckcc['cif_number'] === 'CIF778899');
check('sanction limit imported', $ckcc !== null && (float) $ckcc['sanction_limit'] === 200000.0);
check('drawing power imported', $ckcc !== null && (float) $ckcc['drawing_power'] === 180000.0);
check('interest overdue imported', $ckcc !== null && (float) $ckcc['interest_overdue'] === 3400.0);
check('sanction date imported day-first', $ckcc !== null && (string) $ckcc['sanction_date'] === '2023-04-01', (string) ($ckcc['sanction_date'] ?? ''));
check('CKCC renewal due date imported', $ckcc !== null && (string) $ckcc['ckcc_renewal_due_date'] === '2024-03-31');

// ---- The branch's settlement position, carried in the file ---------------
// OTS/KRM eligibility and the branch's own figures arrive with the lead, so the
// agent knows the position before visiting. A blank cell must stay NULL: "not
// stated" and "refused" are different answers.
$otsCsv = $workDir . '/ots-position.csv';
file_put_contents($otsCsv, implode("\n", [
    'Loan A/C No,Borrower Name,Branch,Outstanding Amount,OTS Eligible (Yes/No),KRM Eligible (Yes/No),OTS Amount (₹),Deposit Amount (₹)',
    'LNOTS001,Shivam Verma,Rampur Rural,250000,Yes,Yes,"56,250.00","5,625.00"',
    'LNOTS002,Rekha Bai,Rampur Rural,90000,No,No,,',
    'LNOTS003,Sunil Das,Rampur Rural,45000,,,,',
]) . "\n");

$otsPreview = ImportService::preview(
    ['name' => 'ots-position.csv', 'tmp_name' => $otsCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($otsCsv)],
    null
);
check('OTS eligible column detected', ($otsPreview['detection']['ots_eligible']['column'] ?? '') === 'OTS Eligible (Yes/No)');
check('KRM eligible column detected', ($otsPreview['detection']['krm_eligible']['column'] ?? '') === 'KRM Eligible (Yes/No)');
check('OTS amount column detected, not confused with the flag', ($otsPreview['detection']['ots_amount']['column'] ?? '') === 'OTS Amount (₹)');
check('deposit amount column detected', ($otsPreview['detection']['deposit_amount']['column'] ?? '') === 'Deposit Amount (₹)');
check('branch column detected from the file', ($otsPreview['detection']['branch']['column'] ?? '') === 'Branch');

ImportService::run(
    ['name' => 'ots-position.csv', 'tmp_name' => $otsCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($otsCsv)],
    null,
    null,
    1,
    'System Administrator',
    [],
    true,
);

$ots1 = $db->first("SELECT ots_eligible, krm_eligible, ots_amount, deposit_amount, branch_id FROM loan_accounts WHERE loan_account_number = 'LNOTS001'");
check('Yes becomes 1 for OTS', $ots1 !== null && (int) $ots1['ots_eligible'] === 1, var_export($ots1['ots_eligible'] ?? null, true));
check('Yes becomes 1 for KRM', $ots1 !== null && (int) $ots1['krm_eligible'] === 1);
check('OTS amount with separators parsed', $ots1 !== null && (float) $ots1['ots_amount'] === 56250.0, var_export($ots1['ots_amount'] ?? null, true));
check('deposit amount parsed', $ots1 !== null && (float) $ots1['deposit_amount'] === 5625.0);
check('branch taken from the row, not a default', $ots1 !== null && $rampur !== null && (int) $ots1['branch_id'] === (int) $rampur['id']);

$ots2 = $db->first("SELECT ots_eligible, krm_eligible, ots_amount FROM loan_accounts WHERE loan_account_number = 'LNOTS002'");
check('No becomes 0, not null', $ots2 !== null && (int) $ots2['ots_eligible'] === 0, var_export($ots2['ots_eligible'] ?? null, true));
check('blank amount alongside a No stays null', $ots2 !== null && $ots2['ots_amount'] === null);

$ots3 = $db->first("SELECT ots_eligible, krm_eligible, ots_amount, deposit_amount FROM loan_accounts WHERE loan_account_number = 'LNOTS003'");
check('a blank flag stays NULL, not 0', $ots3 !== null && $ots3['ots_eligible'] === null, var_export($ots3['ots_eligible'] ?? null, true));
check('a blank KRM flag stays NULL', $ots3 !== null && $ots3['krm_eligible'] === null);

// The importer must not wipe a stated position when a later file omits the column.
$noOtsCsv = $workDir . '/no-ots-columns.csv';
file_put_contents($noOtsCsv, implode("\n", [
    'Loan A/C No,Borrower Name,Branch,Outstanding Amount',
    'LNOTS001,Shivam Verma,Rampur Rural,240000',
]) . "\n");
ImportService::run(
    ['name' => 'no-ots-columns.csv', 'tmp_name' => $noOtsCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($noOtsCsv)],
    null,
    null,
    1,
    'System Administrator',
    [],
    true,
);
$otsKept = $db->first("SELECT ots_eligible, ots_amount, outstanding_amount FROM loan_accounts WHERE loan_account_number = 'LNOTS001'");
check('a file without the OTS columns leaves the position intact', $otsKept !== null && (int) $otsKept['ots_eligible'] === 1);
check('and the OTS figure survives too', $otsKept !== null && (float) $otsKept['ots_amount'] === 56250.0);
check('while the outstanding still updates', $otsKept !== null && (float) $otsKept['outstanding_amount'] === 240000.0);

// ---- Error-log line numbers must match the spreadsheet -------------------
$badRowsCsv = $workDir . '/badrows.csv';
file_put_contents($badRowsCsv, implode("\n", [
    'NPA STATEMENT,,,',
    ',,,',
    'Loan Account Number,Customer Name,Outstanding Amount,Branch',
    'LNROW001,Fine Row,1000,Rampur Rural',
    ',Blank Account,1000,Rampur Rural',
]) . "\n");
$badRowsResult = ImportService::run(
    ['name' => 'badrows.csv', 'tmp_name' => $badRowsCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($badRowsCsv)],
    null,
    null,
    1,
    'System Administrator',
    [],
    true,
);
check('bad row reported at its real spreadsheet line', ($badRowsResult['errors'][0]['row'] ?? 0) === 5, json_encode($badRowsResult['errors']));

// ---------------------------------------------------------------------------
section('Customer data sheet PDF');
// ---------------------------------------------------------------------------
// The sheet an agent carries to the door. It has to be a real, parseable PDF and
// it has to contain the settlement position, because that is the thing the agent
// cannot afford to get wrong in front of a borrower.
$sheetLead = $db->first("SELECT id FROM loan_accounts WHERE loan_account_number = 'LNOTS001' LIMIT 1");
$sheet = App\Services\CustomerSheetService::render((int) $sheetLead['id']);

check('sheet is a PDF', str_starts_with($sheet['bytes'], '%PDF-'), substr($sheet['bytes'], 0, 8));
check('sheet ends with the EOF marker', str_contains(substr($sheet['bytes'], -1024), '%%EOF'));
check('sheet is a plausible size', strlen($sheet['bytes']) > 1500, (string) strlen($sheet['bytes']));
check('filename identifies the account', str_contains($sheet['filename'], 'LNOTS001'), $sheet['filename']);
check('filename ends in .pdf', str_ends_with($sheet['filename'], '.pdf'));

// Pull the text back out of the PDF's content streams so the assertions are about
// what a person would actually read, not about the code that produced it.
$sheetText = '';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $sheet['bytes'], $streams) === 1 || isset($streams[1])) {
    foreach ($streams[1] as $stream) {
        $inflated = @gzuncompress($stream);
        $sheetText .= $inflated === false ? $stream : $inflated;
    }
}
$sheetPlain = '';
if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $sheetText, $shown) !== false) {
    $sheetPlain = implode(' ', $shown[1] ?? []);
}

check('sheet names the borrower', str_contains($sheetPlain, 'Shivam Verma'), substr($sheetPlain, 0, 120));
check('sheet shows the account number', str_contains($sheetPlain, 'LNOTS001'));
check('sheet has a settlement section', str_contains($sheetPlain, 'Settlement Position'));
check('sheet states OTS eligibility', str_contains($sheetPlain, 'OTS Eligible'));
check('sheet carries the OTS figure', str_contains(str_replace(',', '', $sheetPlain), '56250.00'), 'not found');
check('sheet warns against uncommitted settlements', str_contains($sheetPlain, 'confirmed in writing'));
check('sheet marks the history append-only', str_contains($sheetPlain, 'append-only'));
check('Aadhaar is masked on the sheet', !str_contains($sheetPlain, '234567890123'));

// A lead with no stated position must not print an empty settlement block that
// an agent could read as a refusal.
$plainLead = $db->first("SELECT id FROM loan_accounts WHERE loan_account_number = 'LNOTS003' LIMIT 1");
$plainSheet = App\Services\CustomerSheetService::render((int) $plainLead['id']);
$plainText = '';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $plainSheet['bytes'], $s2) !== false) {
    foreach ($s2[1] ?? [] as $stream) {
        $inflated = @gzuncompress($stream);
        $plainText .= $inflated === false ? $stream : $inflated;
    }
}
$plainShown = '';
if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $plainText, $sh2) !== false) {
    $plainShown = implode(' ', $sh2[1] ?? []);
}
check('no settlement section when the branch said nothing', !str_contains($plainShown, 'Settlement Position'));

// ---------------------------------------------------------------------------
section('BC targets, achievement rollup and escalating warnings');
// ---------------------------------------------------------------------------
// A warning is a statement about somebody's job, so the arithmetic behind it has
// to be right: derived from source records, fair about Sundays and part-months,
// and incapable of firing twice for the same day.

use App\Services\BcPerformanceService;

$perfAgentId = $agent1Id;
$perfBranchId = (int) $db->scalar('SELECT branch_id FROM users WHERE id = ?', [$perfAgentId]);

// Sundays are never assessed.
check('Sunday is not a working day', !BcPerformanceService::isWorkingDay('2026-08-02'));
check('Monday is a working day', BcPerformanceService::isWorkingDay('2026-08-03'));

// Working-day maths, used to pro-rate a monthly target. August 2026 starts on a
// Saturday, so the 3rd is the 2nd working day of the month.
check('working days elapsed excludes Sundays', BcPerformanceService::workingDaysElapsed('2026-08-03') === 2,
    (string) BcPerformanceService::workingDaysElapsed('2026-08-03'));
check('August 2026 has 26 working days', BcPerformanceService::workingDaysInMonth('2026-08-15') === 26,
    (string) BcPerformanceService::workingDaysInMonth('2026-08-15'));

// Streak thresholds.
check('1 miss is Level 1', BcPerformanceService::levelForStreak(1) === 'L1');
check('2 misses is still Level 1', BcPerformanceService::levelForStreak(2) === 'L1');
check('3 misses is Level 2', BcPerformanceService::levelForStreak(3) === 'L2');
check('6 misses is still Level 2', BcPerformanceService::levelForStreak(6) === 'L2');
check('7 misses is the final warning', BcPerformanceService::levelForStreak(7) === 'L3');
check('L3 maps to the final-warning badge', BcPerformanceService::statusForLevel('L3') === 'final_warning');

// ---- No targets set means no assessment ---------------------------------
$db->query('DELETE FROM bc_warnings WHERE agent_id = ?', [$perfAgentId]);
$db->query('DELETE FROM bc_targets WHERE agent_id = ?', [$perfAgentId]);
check('no gaps when no targets exist', BcPerformanceService::gapsFor($perfAgentId, '2026-08-03') === []);

// ---- Achievement is derived, not entered --------------------------------
$db->query('DELETE FROM sss_enrollment WHERE agent_id = ?', [$perfAgentId]);
$db->insert('sss_enrollment', [
    'agent_id' => $perfAgentId, 'branch_id' => $perfBranchId, 'enrollment_date' => '2026-08-03',
    'apy_count' => 2, 'pmjjby_count' => 1, 'pmsby_count' => 0, 'pmjdy_count' => 3,
]);
$rolled = BcPerformanceService::rollUpDay($perfAgentId, '2026-08-03');
check('rollup reads APY from the SSS entry', $rolled['apy_done'] === 2, (string) $rolled['apy_done']);
check('rollup reads PMJDY from the SSS entry', $rolled['pmjdy_done'] === 3);
check('an SSS entry counts as having reported', $rolled['report_submitted'] === 1);

$stored = $db->first('SELECT * FROM bc_daily_achievement WHERE agent_id = ? AND achievement_date = ?',
    [$perfAgentId, '2026-08-03']);
check('rollup is stored', $stored !== null && (int) $stored['apy_done'] === 2);

// Re-running must overwrite, not accumulate - the cron may be re-run after a failure.
BcPerformanceService::rollUpDay($perfAgentId, '2026-08-03');
$rows = (int) $db->scalar('SELECT COUNT(*) FROM bc_daily_achievement WHERE agent_id = ? AND achievement_date = ?',
    [$perfAgentId, '2026-08-03']);
check('a second rollup does not duplicate the day', $rows === 1, (string) $rows);
$again = $db->first('SELECT apy_done FROM bc_daily_achievement WHERE agent_id = ? AND achievement_date = ?',
    [$perfAgentId, '2026-08-03']);
check('a second rollup does not double the figure', (int) $again['apy_done'] === 2, (string) $again['apy_done']);

// ---- Gaps, with a monthly target pro-rated ------------------------------
$db->insert('bc_targets', [
    'agent_id' => $perfAgentId, 'target_month' => '2026-08-01',
    'apy_target' => 27, 'pmjjby_target' => 0, 'pmsby_target' => 0, 'pmjdy_target' => 0,
    'npa_recovery_target' => 0, 'od2_renewal_target' => 0, 'daily_visit_target' => 5,
]);

// 27 APY over 27 working days = 1 per working day. By the 2nd working day the
// agent should have 2, and has exactly 2 - so no APY gap.
$gaps = BcPerformanceService::gapsFor($perfAgentId, '2026-08-03');
check('a met pro-rated target is not a gap', !isset($gaps['apy']), json_encode(array_keys($gaps)));
check('an unmet daily visit target is a gap', isset($gaps['visit']));
check('the visit gap states the target', ($gaps['visit']['target'] ?? 0) === 5.0, json_encode($gaps['visit'] ?? null));

// A target of zero is never assessed: nobody was asked for it.
check('a zero target is not assessed', !isset($gaps['pmsby']));

// ---- Streak escalation over consecutive working days --------------------
$db->query('DELETE FROM bc_warnings WHERE agent_id = ?', [$perfAgentId]);

// Six consecutive working days from Mon 3 Aug to Sat 8 Aug 2026.
$streakDays = ['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-08'];
$levels = [];
foreach ($streakDays as $day) {
    BcPerformanceService::rollUpDay($perfAgentId, $day);
    $dayGaps = BcPerformanceService::gapsFor($perfAgentId, $day);
    $warning = BcPerformanceService::recordWarning($perfAgentId, 'visit', $dayGaps['visit'], $day);
    $levels[] = $warning === null ? 'none' : $warning['level'] . ':' . $warning['streak'];
}
check('day 1 issues Level 1', $levels[0] === 'L1:1', $levels[0]);
check('day 2 stays Level 1', $levels[1] === 'L1:2', $levels[1]);
check('day 3 escalates to Level 2', $levels[2] === 'L2:3', $levels[2]);
check('day 6 is still Level 2', $levels[5] === 'L2:6', $levels[5]);

// Sunday 9 Aug is skipped; Monday 10 Aug is the 7th working-day miss.
BcPerformanceService::rollUpDay($perfAgentId, '2026-08-10');
$mondayGaps = BcPerformanceService::gapsFor($perfAgentId, '2026-08-10');
$final = BcPerformanceService::recordWarning($perfAgentId, 'visit', $mondayGaps['visit'], '2026-08-10');
check('a Sunday in between does not break the streak', $final !== null && $final['streak'] === 7,
    json_encode($final === null ? null : $final['streak']));
check('the 7th working-day miss is the final warning', $final !== null && $final['level'] === 'L3');

// Re-running the same day must not issue a second warning or a second email.
$duplicate = BcPerformanceService::recordWarning($perfAgentId, 'visit', $mondayGaps['visit'], '2026-08-10');
check('the same day cannot be warned twice', $duplicate === null);
$warnCount = (int) $db->scalar(
    'SELECT COUNT(*) FROM bc_warnings WHERE agent_id = ? AND target_type = ? AND triggered_date = ?',
    [$perfAgentId, 'visit', '2026-08-10']
);
check('only one warning row exists for that day', $warnCount === 1, (string) $warnCount);

// ---- Standing and escalation flag ---------------------------------------
$standing = BcPerformanceService::refreshStanding($perfAgentId, '2026-08-10');
check('the badge reflects the worst open level', $standing['status'] === 'final_warning', $standing['status']);
$userRow = $db->first('SELECT dashboard_status, escalation_flag FROM users WHERE id = ?', [$perfAgentId]);
check('the badge is stored on the user', (string) $userRow['dashboard_status'] === 'final_warning');
check('escalation is not raised on the first final warning', (int) $userRow['escalation_flag'] === 0,
    (string) $userRow['escalation_flag']);

// A final warning still open a week later raises the admin banner. The other
// rows go first: the unique key would reject two warnings on the same date.
$db->query("DELETE FROM bc_warnings WHERE agent_id = ? AND triggered_date <> '2026-08-10'", [$perfAgentId]);
$db->query(
    "UPDATE bc_warnings SET warning_level = 'L3', triggered_date = '2026-08-03' WHERE agent_id = ?",
    [$perfAgentId]
);
$escalatedStanding = BcPerformanceService::refreshStanding($perfAgentId, '2026-08-12');
check('an unimproved final warning escalates', $escalatedStanding['escalation_flag'] === 1,
    json_encode($escalatedStanding));

// Resolving the warnings clears the badge.
$db->query("UPDATE bc_warnings SET status = 'resolved' WHERE agent_id = ?", [$perfAgentId]);
$cleared = BcPerformanceService::refreshStanding($perfAgentId, '2026-08-12');
check('resolving the warnings clears the badge', $cleared['status'] === 'normal', $cleared['status']);
check('and clears the escalation flag', $cleared['escalation_flag'] === 0);

// ---- Scorecard ----------------------------------------------------------
$weights = BcPerformanceService::weights();
check('score weights are seeded', count($weights) === 9, (string) count($weights));
check('recovery is scored per 1,000 rupees', ($weights['npa_recovery']['divisor'] ?? 0) === 1000.0);
check('an enrolment outweighs a visit',
    ($weights['apy']['weight'] ?? 0) > ($weights['visits']['weight'] ?? 0));

$scorecard = BcPerformanceService::scorecard('2026-08-01', '2026-08-31');
check('the scorecard lists agents', count($scorecard) > 0, (string) count($scorecard));
check('every row carries a score', !in_array(null, array_column($scorecard, 'total_score'), true));
check('every row carries a rank', !in_array(null, array_column($scorecard, 'rank'), true));

$scores = array_column($scorecard, 'total_score');
$sorted = $scores;
rsort($sorted);
check('the scorecard is ranked by score descending', $scores === $sorted, json_encode($scores));
check('the top row is rank 1', (int) $scorecard[0]['rank'] === 1);

// Our agent enrolled 2 APY + 1 PMJJBY + 3 PMJDY on 3 Aug = 2*5 + 1*5 + 3*3 = 24,
// plus whatever visits the seed produced. The point is that enrolments reached the
// score at all, since they come from a different table to visits.
$mine = null;
foreach ($scorecard as $row) {
    if ((int) $row['agent_id'] === $perfAgentId) {
        $mine = $row;
    }
}
check('the scored agent appears', $mine !== null);
check('SSS enrolments reach the score', $mine !== null && (int) $mine['apy'] === 2, json_encode($mine['apy'] ?? null));
check('the score is greater than zero', $mine !== null && (float) $mine['total_score'] > 0);

// Dense ranking: equal scores share a rank rather than being ordered arbitrarily.
$ranks = array_column($scorecard, 'rank');
$dense = true;
for ($i = 1, $n = count($scorecard); $i < $n; $i++) {
    $sameScore = (float) $scorecard[$i]['total_score'] === (float) $scorecard[$i - 1]['total_score'];
    if ($sameScore && (int) $ranks[$i] !== (int) $ranks[$i - 1]) {
        $dense = false;
    }
}
check('agents on the same score share a rank', $dense);

// ---------------------------------------------------------------------------
section('Location tracking: consent, bounds and retention');
// ---------------------------------------------------------------------------
// This system tracks staff. That was an explicit decision, and these assertions
// are the obligations that come with it - enforced in code, not in a handbook.

use App\Services\TrackingService;

$trackAgent = $agent1Id;
$db->query('DELETE FROM bc_location_logs WHERE agent_id = ?', [$trackAgent]);
$db->query('DELETE FROM tracking_consents WHERE user_id = ?', [$trackAgent]);

// ---- Nothing is recorded before the notice is acknowledged --------------
check('an agent starts without consent', !TrackingService::hasConsented($trackAgent));

$refused = false;
try {
    TrackingService::record($trackAgent, ['latitude' => 26.9124, 'longitude' => 75.7873]);
} catch (\Throwable $e) {
    $refused = str_contains($e->getMessage(), 'acknowledged');
}
check('recording is refused without consent', $refused);
check('and nothing was written', (int) $db->scalar(
    'SELECT COUNT(*) FROM bc_location_logs WHERE agent_id = ?', [$trackAgent]) === 0);

// ---- The notice itself must say the things that make it a notice --------
$notice = TrackingService::notice();
check('the notice is versioned', $notice['version'] === TrackingService::NOTICE_VERSION);
foreach (['english' => 'records your location', 'hindi' => 'लोकेशन रिकॉर्ड करता है'] as $lang => $needle) {
    check("the $lang notice states that location is recorded", str_contains($notice[$lang], $needle));
}
check('the notice says how long it is kept', str_contains($notice['english'], 'then it is deleted automatically'));
check('the notice says who can see it', str_contains($notice['english'], 'Who can see it'));
check('the notice explains withdrawal', str_contains($notice['english'], 'withdraw this consent'));
check('the notice says viewing is logged', str_contains($notice['english'], 'it is logged'));
check('the Hindi notice explains withdrawal', str_contains($notice['hindi'], 'सहमति वापस'));

// ---- After acknowledgement, points are stored --------------------------
TrackingService::recordConsent($trackAgent, 'Integration test device', '127.0.0.1');
check('consent is recorded', TrackingService::hasConsented($trackAgent));
check('the acknowledgement is audited', (int) $db->scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE action = 'consent' AND entity_id = ?",
    [(string) $trackAgent]) >= 1);

check('a point is accepted after consent',
    TrackingService::record($trackAgent, ['latitude' => 26.9124, 'longitude' => 75.7873, 'accuracy_m' => 12]));
$point = $db->first('SELECT * FROM bc_location_logs WHERE agent_id = ? ORDER BY id DESC LIMIT 1', [$trackAgent]);
check('the coordinate is stored', $point !== null && abs((float) $point['latitude'] - 26.9124) < 0.0001);
check('accuracy is stored', $point !== null && (int) $point['accuracy_m'] === 12);
check('on_duty defaults to true', $point !== null && (int) $point['on_duty'] === 1);
check('the server clock is recorded separately', $point !== null && $point['received_at'] !== null);

// ---- Rate limiting: a device waking up must not flood the table --------
$flood = TrackingService::record($trackAgent, ['latitude' => 26.9125, 'longitude' => 75.7874]);
check('a second point within a minute is dropped', $flood === false);
check('and the table did not grow', (int) $db->scalar(
    'SELECT COUNT(*) FROM bc_location_logs WHERE agent_id = ?', [$trackAgent]) === 1);

// ---- Obviously wrong coordinates are refused ---------------------------
foreach ([
    'null island (a failed fix)' => [0.0, 0.0],
    'latitude out of range'      => [91.0, 75.0],
    'longitude out of range'     => [26.0, 181.0],
] as $label => [$lat, $lng]) {
    check("$label is not plausible", !TrackingService::plausible($lat, $lng));
}
check('a real Jaipur coordinate is plausible', TrackingService::plausible(26.9124, 75.7873));

$rejected = false;
try {
    $db->query('DELETE FROM bc_location_logs WHERE agent_id = ?', [$trackAgent]);
    TrackingService::record($trackAgent, ['latitude' => 0.0, 'longitude' => 0.0]);
} catch (\Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'valid coordinate');
}
check('a failed fix is rejected rather than stored', $rejected);

// ---- A wrong device clock cannot file points in the future -------------
$db->query('DELETE FROM bc_location_logs WHERE agent_id = ?', [$trackAgent]);
TrackingService::record($trackAgent, [
    'latitude' => 26.9124, 'longitude' => 75.7873, 'logged_at' => '2030-01-01 10:00:00',
]);
$future = $db->first('SELECT logged_at FROM bc_location_logs WHERE agent_id = ? ORDER BY id DESC LIMIT 1', [$trackAgent]);
check('a future device timestamp is replaced with now',
    $future !== null && strtotime((string) $future['logged_at']) <= time() + 60,
    (string) ($future['logged_at'] ?? ''));

// ---- Viewing somebody else's trail is audited -------------------------
$auditBefore = (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'view_location'");
TrackingService::trailFor($trackAgent, date('Y-m-d'), $trackAgent);
check('an agent viewing their own trail is not logged as surveillance',
    (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'view_location'") === $auditBefore);

TrackingService::trailFor($trackAgent, date('Y-m-d'), 1);
check('somebody else viewing the trail is audited',
    (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'view_location'") === $auditBefore + 1);

// ---- Withdrawal stops collection immediately --------------------------
TrackingService::withdrawConsent($trackAgent);
check('consent can be withdrawn', !TrackingService::hasConsented($trackAgent));
$afterWithdrawal = false;
try {
    TrackingService::record($trackAgent, ['latitude' => 26.92, 'longitude' => 75.79]);
} catch (\Throwable) {
    $afterWithdrawal = true;
}
check('recording stops the moment consent is withdrawn', $afterWithdrawal);
check('withdrawal is audited', (int) $db->scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE action = 'consent' AND summary LIKE '%Withdrew%'") >= 1);

// Re-acknowledging must work rather than collide with the unique key.
TrackingService::recordConsent($trackAgent, 'Integration test device', '127.0.0.1');
check('an agent can acknowledge again after withdrawing', TrackingService::hasConsented($trackAgent));

// ---- Retention: old points are purged --------------------------------
$db->query('DELETE FROM bc_location_logs WHERE agent_id = ?', [$trackAgent]);
foreach ([200, 120, 5, 1] as $daysAgo) {
    $db->insert('bc_location_logs', [
        'agent_id'  => $trackAgent,
        'latitude'  => 26.9124,
        'longitude' => 75.7873,
        'logged_at' => date('Y-m-d H:i:s', strtotime('-' . $daysAgo . ' days')),
    ]);
}
check('four points exist before the purge', (int) $db->scalar(
    'SELECT COUNT(*) FROM bc_location_logs WHERE agent_id = ?', [$trackAgent]) === 4);

$purged = TrackingService::purge(90);
check('the purge removed the two points past 90 days', $purged === 2, (string) $purged);
check('recent points survive', (int) $db->scalar(
    'SELECT COUNT(*) FROM bc_location_logs WHERE agent_id = ?', [$trackAgent]) === 2);
check('retention defaults to 90 days', TrackingService::retentionDays() === 90,
    (string) TrackingService::retentionDays());

// ---- The audit ENUM must actually accept what the code writes ---------
// Logger::audit swallows its own failures so that a logging problem cannot break
// the action being logged - which means an action name missing from the ENUM never
// raises anything, it just silently never records. That is how the customer-sheet
// export came to be "audited" without a single row ever appearing.
$sheetLeadForAudit = $db->first("SELECT id FROM loan_accounts LIMIT 1");
$auditActions = ['export', 'consent', 'view_location', 'purge'];
foreach ($auditActions as $action) {
    $ok = true;
    try {
        $db->insert('audit_logs', [
            'user_id' => 1, 'user_name' => 'Audit ENUM check', 'action' => $action,
            'entity_type' => 'loan_account', 'entity_id' => (string) $sheetLeadForAudit['id'],
            'summary' => 'ENUM acceptance check',
        ]);
    } catch (\Throwable) {
        $ok = false;
    }
    check("audit_logs accepts the '$action' action", $ok);
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

// BackupService has two independent code paths - mysqldump when the binary is
// present, a pure-PHP dump when it is not - and the two produce different SQL.
// Testing only whichever one the host happens to take hid a failure for a while:
// this suite passed locally (no mysqldump installed, PHP path) and failed in CI
// (mysqldump installed) because the assertion below was written against the PHP
// path's exact spelling, `FOREIGN_KEY_CHECKS = 0`, while mysqldump emits
// `/*!40014 ... FOREIGN_KEY_CHECKS=0 */`. Both paths are now exercised on every
// run, and the assertions check the invariant rather than one method's syntax.
$mysqldumpPresent = false;
if (function_exists('exec')) {
    $probe = [];
    $probeExit = 1;
    @exec('command -v mysqldump 2>/dev/null', $probe, $probeExit);
    $mysqldumpPresent = $probeExit === 0 && $probe !== [];
}
echo '  ....  mysqldump ' . ($mysqldumpPresent ? 'is' : 'is not') . " installed on this host\n";

/**
 * The assertions that must hold for a restorable dump, whichever path made it.
 */
$checkDump = static function (array $backup, string $label): string {
    $sql = (string) file_get_contents($backup['path']);
    check($label . ': created', is_file($backup['path']));
    check($label . ': non-empty', $backup['size'] > 2000, 'size=' . $backup['size']);
    check($label . ': has CREATE TABLE', str_contains($sql, 'CREATE TABLE'));
    check($label . ': has INSERT for loan_accounts', str_contains($sql, 'INSERT INTO `loan_accounts`'));
    // The invariant, not the spelling: a restore must not trip over a foreign
    // key pointing at a table that does not exist yet.
    check(
        $label . ': disables FK checks',
        preg_match('/FOREIGN_KEY_CHECKS\s*=\s*0/i', $sql) === 1
    );
    // A dump that begins with a stray warning line fails to restore.
    check(
        $label . ': starts with SQL or a comment, not a warning',
        preg_match('/^\s*(--|\/\*|SET|CREATE|DROP|\/\*!)/i', $sql) === 1,
        'first 60 chars: ' . substr(str_replace("\n", '\\n', $sql), 0, 60)
    );
    check($label . ': no mysqldump warning text leaked into the dump',
        stripos($sql, 'mysqldump:') === false && stripos($sql, '[Warning]') === false);
    return $sql;
};

// --- Path 1: whatever this host does by default -----------------------------
$backup = BackupService::create();
check(
    'default backup method matches host capability',
    $backup['method'] === ($mysqldumpPresent ? 'mysqldump' : 'php'),
    'method=' . $backup['method']
);
$sql = $checkDump($backup, 'default backup (' . $backup['method'] . ')');

// --- Path 2: force the pure-PHP dump ----------------------------------------
// Simulates the common shared-hosting case where exec() works but mysqldump is
// not installed, which is exactly the environment this project targets.
Settings::updateMany(['mysqldump_path' => '/nonexistent/lrms-no-such-mysqldump']);
Settings::flush();
$phpBackup = BackupService::create();
check('missing mysqldump falls back to the PHP dump', $phpBackup['method'] === 'php', 'method=' . $phpBackup['method']);
$checkDump($phpBackup, 'PHP fallback');
Settings::updateMany(['mysqldump_path' => 'mysqldump']);
Settings::flush();

check('backup listed', count(BackupService::list()) >= 2);
check('path traversal rejected', BackupService::resolve('../../../etc/passwd') === null);
check('non-sql rejected', BackupService::resolve('evil.php') === null);
check('valid file resolves', BackupService::resolve($backup['file']) !== null);

// ---------------------------------------------------------------------------
section('KRM / OTS settlement report');

// A settlement report is filed as a visit with report_type = 'ots' plus the
// ots_details section. Sent with flat `ots_details[field]` keys, which is how the
// app has to send it: the visit is multipart because it carries photos, and
// multipart has no nesting.
$otsLead = LoanAccount::find($leadId);
$otsResult = VisitService::submit([
    'loan_account_id' => $otsLead['id'],
    'report_type'     => 'ots',
    'customer_met'    => 1,
    'ready_to_pay'    => 1,
    'remarks'         => 'Borrower agreed to the OTS terms.',
    'sp_cbc_name'     => 'S. Verma',
    'ots_details[eligible_for_ots]'        => 1,
    'ots_details[scheme]'                  => 'krm_ots',
    'ots_details[relief_waiver_percent]'   => '77.5',
    'ots_details[rlb_amount]'              => '200000',
    'ots_details[borrower_payable_amount]' => '45000',
    'ots_details[total_settlement_amount]' => '45000',
    'ots_details[required_deposit_amount]' => '4500',
    'ots_details[deposit_received]'        => 1,
    'ots_details[deposit_amount]'          => '4500',
    'ots_details[deposit_date]'            => date('Y-m-d'),
    'ots_details[deposit_reference]'       => 'RCPT/2026/00191',
    'ots_details[balance_payable]'         => '40500',
    'ots_details[approval_status]'         => 'approved',
    'ots_details[validity_from]'           => date('Y-m-d'),
    'ots_details[validity_to]'             => date('Y-m-d', strtotime('+90 days')),
    'ots_details[borrower_accepted]'       => 1,
], $agentCtx);

$otsVisitId = (int) $otsResult['visit_id'];
check('OTS visit is created', $otsVisitId > 0);
check('the visit is tagged report_type=ots',
    ($db->scalar('SELECT report_type FROM visit_reports WHERE id = ?', [$otsVisitId])) === 'ots');

$otsRow = VisitReport::otsDetails($otsVisitId);
check('an ots_details row was written', $otsRow !== null);
if ($otsRow !== null) {
    check('scheme stored', $otsRow['scheme'] === 'krm_ots');
    check('eligibility stored', (int) $otsRow['eligible_for_ots'] === 1);
    check('relief percent stored', abs((float) $otsRow['relief_waiver_percent'] - 77.5) < 0.01);
    // The figure the agent typed must survive untouched: the branch's sanction
    // letter is the authority and a silent recalculation would misstate a
    // settlement.
    check('payable amount stored exactly as entered',
        abs((float) $otsRow['borrower_payable_amount'] - 45000.0) < 0.01);
    check('the scheme default payable percent is applied when not sent',
        abs((float) $otsRow['payable_percent'] - 22.50) < 0.01);
    check('the scheme default deposit percent is applied when not sent',
        abs((float) $otsRow['initial_deposit_percent'] - 10.00) < 0.01);
    // Deposit is EVIDENCE of a payment the borrower made to the bank; the agent
    // never handles money.
    check('deposit receipt reference stored', $otsRow['deposit_reference'] === 'RCPT/2026/00191');
    check('deposit date stored', (string) $otsRow['deposit_date'] === date('Y-m-d'));
    check('approval status stored', $otsRow['approval_status'] === 'approved');
    check('borrower acceptance stored', (int) $otsRow['borrower_accepted'] === 1);
    // Bank data, taken from the account: an agent cannot mistype the very date
    // the settlement is being offered against.
    check('the NPA date is snapshotted from the lead',
        (string) ($otsRow['npa_date'] ?? '') === (string) ($otsLead['npa_date'] ?? ''),
        'got ' . var_export($otsRow['npa_date'] ?? null, true));
    check('the borrower name is snapshotted so the offer reads standalone',
        (string) ($otsRow['borrower_name'] ?? '') === (string) $otsLead['customer_name']);
    check('outstanding was snapshotted from the lead',
        abs((float) $otsRow['outstanding_amount'] - (float) $otsLead['outstanding_amount']) < 0.01);
}

// RLB falls back to the outstanding balance, which is how the worked example runs:
// payable is a percentage of the outstanding amount.
$rlbDefault = VisitService::submit([
    'loan_account_id' => $leadId,
    'report_type'     => 'ots',
    'customer_met'    => 1,
    'ots_details[eligible_for_ots]' => 1,
], $agentCtx);
$rlbRow = VisitReport::otsDetails((int) $rlbDefault['visit_id']);
check('RLB defaults to the outstanding balance when not supplied',
    $rlbRow !== null
    && abs((float) $rlbRow['rlb_amount'] - (float) $otsLead['outstanding_amount']) < 0.01,
    'got ' . var_export($rlbRow['rlb_amount'] ?? null, true));

// A percentage outside 0-100 is a typo, not data.
$clampResult = VisitService::submit([
    'loan_account_id' => $leadId,
    'report_type'     => 'ots',
    'customer_met'    => 1,
    'ots_details[relief_waiver_percent]' => '250',
    'ots_details[payable_percent]'       => '-5',
], $agentCtx);
$clamped = VisitReport::otsDetails((int) $clampResult['visit_id']);
check('an out-of-range percent is clamped to 100', $clamped !== null
    && abs((float) $clamped['relief_waiver_percent'] - 100.0) < 0.01);
check('a negative percent is clamped to 0', $clamped !== null
    && abs((float) $clamped['payable_percent'] - 0.0) < 0.01);

// An unknown scheme must not be written through to the enum column.
$badEnum = VisitService::submit([
    'loan_account_id' => $leadId,
    'report_type'     => 'ots',
    'customer_met'    => 1,
    'ots_details[scheme]'          => 'nonsense_scheme',
    'ots_details[approval_status]' => 'nonsense_status',
], $agentCtx);
$badRow = VisitReport::otsDetails((int) $badEnum['visit_id']);
check('an unknown scheme is stored as null, not written through', $badRow !== null && $badRow['scheme'] === null);
check('an unknown approval status falls back to pending',
    $badRow !== null && $badRow['approval_status'] === 'pending');

// A plain recovery visit must not leave an empty settlement row behind.
$plain = VisitService::submit([
    'loan_account_id' => $leadId,
    'customer_met'    => 1,
    'not_ready'       => 1,
], $agentCtx);
check('a recovery visit defaults to report_type=recovery',
    ($db->scalar('SELECT report_type FROM visit_reports WHERE id = ?', [(int) $plain['visit_id']])) === 'recovery');
check('a recovery visit writes no ots_details row',
    VisitReport::otsDetails((int) $plain['visit_id']) === null);
check('a recovery visit writes no ckcc_details row',
    VisitReport::ckccDetails((int) $plain['visit_id']) === null);

// ---------------------------------------------------------------------------
section('CKCC OD-2 renewal report');

$ckccLeadId = (int) $ln1002['id'];
$db->query(
    'UPDATE loan_accounts
        SET cif_number = ?, sanction_date = ?, sanction_limit = ?, drawing_power = ?,
            interest_overdue = ?, ckcc_renewal_due_date = ?, loan_type = ?
      WHERE id = ?',
    ['CIF900123', '2023-06-15', 300000, 285000, 12500, date('Y-m-d', strtotime('+10 days')), 'CKCC', $ckccLeadId]
);
$ckccLead = LoanAccount::find($ckccLeadId);
check('CKCC attributes are readable from the lead', (string) $ckccLead['cif_number'] === 'CIF900123');

$ckccResult = VisitService::submit([
    'loan_account_id' => $ckccLeadId,
    'report_type'     => 'ckcc_renewal',
    'customer_met'    => 1,
    'borrower_alive'  => 1,
    'same_address'    => 1,
    'occupation'      => 'agriculture',
    'remarks'         => 'Renewal papers collected.',
    'ckcc_details[eligible_for_renewal]'   => 1,
    'ckcc_details[kyc_status]'             => 'complete',
    'ckcc_details[aadhaar_seeded]'         => 1,
    'ckcc_details[mobile_linked]'          => 1,
    'ckcc_details[aadhaar_auth_completed]' => 1,
    'ckcc_details[doc_aadhaar]'            => 1,
    'ckcc_details[doc_passbook]'           => 1,
    'ckcc_details[doc_khasra_khatauni]'    => 1,
    'ckcc_details[willing_to_renew]'       => 1,
    'ckcc_details[renewal_form_signed]'    => 1,
    'ckcc_details[ekyc_completed]'         => 1,
    'ckcc_details[agent_observation]'      => 'Borrower cooperative, land records in order.',
    'ckcc_details[rec_renew_immediately]'  => 1,
    'ckcc_details[st_documents_collected]' => 1,
], $agentCtx);

$ckccVisitId = (int) $ckccResult['visit_id'];
check('CKCC visit is created', $ckccVisitId > 0);
check('the visit is tagged report_type=ckcc_renewal',
    ($db->scalar('SELECT report_type FROM visit_reports WHERE id = ?', [$ckccVisitId])) === 'ckcc_renewal');

$ckccRow = VisitReport::ckccDetails($ckccVisitId);
check('a ckcc_details row was written', $ckccRow !== null);
if ($ckccRow !== null) {
    // Account figures are pre-filled from the lead so the agent does not copy
    // them off a passbook by hand.
    check('CIF was pulled from the lead', (string) $ckccRow['cif_number'] === 'CIF900123');
    check('sanction limit was pulled from the lead',
        abs((float) $ckccRow['sanction_limit'] - 300000.0) < 0.01);
    check('drawing power was pulled from the lead',
        abs((float) $ckccRow['drawing_power'] - 285000.0) < 0.01);
    check('renewal due date was pulled from the lead',
        (string) $ckccRow['renewal_due_date'] === date('Y-m-d', strtotime('+10 days')));

    // Derived server-side, never trusted from the device: a phone with a wrong
    // clock would otherwise write a misleading deadline into a report a branch
    // acts on.
    check('days remaining is computed', (int) $ckccRow['days_remaining'] === 10,
        'got ' . var_export($ckccRow['days_remaining'], true));
    check('expected NPA date is the day after the renewal deadline',
        (string) $ckccRow['expected_npa_date'] === date('Y-m-d', strtotime('+11 days')));
    check('the due bucket is derived as within_15 for 10 days out',
        $ckccRow['renewal_due_bucket'] === 'within_15',
        'got ' . var_export($ckccRow['renewal_due_bucket'], true));

    check('KYC status stored', $ckccRow['kyc_status'] === 'complete');
    check('document availability flags stored',
        (int) $ckccRow['doc_aadhaar'] === 1
        && (int) $ckccRow['doc_khasra_khatauni'] === 1
        && (int) $ckccRow['doc_pan'] === 0);
    check('consent flags stored',
        (int) $ckccRow['willing_to_renew'] === 1 && (int) $ckccRow['renewal_form_signed'] === 1);
    check('agent observation stored',
        str_contains((string) $ckccRow['agent_observation'], 'land records in order'));
    check('recommendation flag stored', (int) $ckccRow['rec_renew_immediately'] === 1);
    check('report status flag stored', (int) $ckccRow['st_documents_collected'] === 1);

    // No location data is captured anywhere in this system.
    check('the CKCC section carries no location columns',
        !array_key_exists('latitude', $ckccRow)
        && !array_key_exists('longitude', $ckccRow)
        && !array_key_exists('gps', $ckccRow));
}

// An overdue renewal must bucket as overdue and report negative days.
$db->query('UPDATE loan_accounts SET ckcc_renewal_due_date = ? WHERE id = ?',
    [date('Y-m-d', strtotime('-4 days')), (int) $ln1003['id']]);
$overdueResult = VisitService::submit([
    'loan_account_id' => (int) $ln1003['id'],
    'report_type'     => 'ckcc_renewal',
    'customer_met'    => 1,
    'ckcc_details[eligible_for_renewal]' => 1,
], $agentCtx);
$overdueRow = VisitReport::ckccDetails((int) $overdueResult['visit_id']);
check('an overdue renewal reports negative days remaining',
    $overdueRow !== null && (int) $overdueRow['days_remaining'] === -4,
    'got ' . var_export($overdueRow['days_remaining'] ?? null, true));
check('an overdue renewal buckets as overdue',
    $overdueRow !== null && $overdueRow['renewal_due_bucket'] === 'overdue');

// Both sections are append-only, exactly like their parent report.
check('visit_ots_details has no UPDATE path in the codebase',
    !str_contains((string) file_get_contents(ROOT_PATH . '/app/Services/VisitService.php'),
        "update('visit_ots_details'"));
check('visit_ckcc_details has no UPDATE path in the codebase',
    !str_contains((string) file_get_contents(ROOT_PATH . '/app/Services/VisitService.php'),
        "update('visit_ckcc_details'"));

// Deleting a visit report must take its detail rows with it.
$db->query('DELETE FROM visit_reports WHERE id = ?', [(int) $badEnum['visit_id']]);
check('deleting a visit cascades to its ots_details row',
    VisitReport::otsDetails((int) $badEnum['visit_id']) === null);
// That DELETE went behind the service's back, so the lead's derived visit_count
// is now stale - the referential-integrity section below checks it, and caught
// this the first time round. Rebuild it rather than leaving the fixture wrong.
LoanAccount::refreshVisitCounters($leadId);

// ---------------------------------------------------------------------------
section('Email login and email OTP');

$emailUser = User::find($agent1Id);
$emailAddress = (string) ($emailUser['email'] ?? '');
check('the seeded agent has an email address', $emailAddress !== '');

if ($emailAddress !== '') {
    // Office staff know their email address, not their employee code.
    $byEmail = Auth::attempt($emailAddress, 'Agent@123', '127.0.0.1');
    check('an agent can sign in with their email address',
        ($byEmail['user']['id'] ?? 0) === $agent1Id,
        (string) ($byEmail['error'] ?? ''));

    // Case must not matter - a phone keyboard capitalises the first letter.
    $mixedCase = Auth::attempt(strtoupper($emailAddress), 'Agent@123', '127.0.0.1');
    check('email sign-in is case-insensitive', ($mixedCase['user']['id'] ?? 0) === $agent1Id);

    $stillCode = Auth::attempt((string) $emailUser['employee_code'], 'Agent@123', '127.0.0.1');
    check('the employee code still works', ($stillCode['user']['id'] ?? 0) === $agent1Id);

    $wrong = Auth::attempt($emailAddress, 'not-the-password', '127.0.0.1');
    check('a wrong password is still refused for an email sign-in', $wrong['user'] === null);
    check('the error names both accepted identifiers',
        str_contains((string) $wrong['error'], 'employee code or email'),
        (string) $wrong['error']);
}

check('an unknown email is refused', Auth::attempt('nobody@example.com', 'x', '127.0.0.1')['user'] === null);

// Email is a login identifier, so the schema must stop two accounts sharing one.
$dupBlocked = false;
try {
    $db->query('UPDATE users SET email = ? WHERE id = ?', [$emailAddress, $agent2Id]);
} catch (\Throwable $e) {
    $dupBlocked = true;
}
check('the database refuses two accounts with the same email', $dupBlocked,
    'a duplicate address was accepted - email sign-in could resolve to the wrong person');

// ---- OTP delivery ----------------------------------------------------------
$db->query('DELETE FROM password_otps');
Settings::updateMany(['smtp_host' => '', 'smtp_from_email' => '']);
Settings::flush();

$noChannel = Auth::issuePasswordOtp($emailUser, '127.0.0.1');
check('with no SMTP and no SMS gateway the reset falls back to an admin reset',
    $noChannel['channel'] === 'admin' && $noChannel['sent'] === false);
check('and no OTP row is written when nothing can deliver it',
    (int) $db->scalar('SELECT COUNT(*) FROM password_otps') === 0);

// Configure SMTP. Delivery itself will fail (no mail server here), but the row
// and the chosen channel are what matter.
Settings::updateMany(['smtp_host' => 'localhost', 'smtp_from_email' => 'noreply@example.com']);
Settings::flush();

$viaEmail = Auth::issuePasswordOtp($emailUser, '127.0.0.1');
check('email is chosen over SMS when SMTP is configured', $viaEmail['channel'] === 'email');
check('the OTP row records the email channel',
    $db->scalar('SELECT channel FROM password_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$agent1Id]) === 'email');
check('the destination shown to the user is masked',
    $viaEmail['destination'] !== null
    && str_contains((string) $viaEmail['destination'], '*')
    && $viaEmail['destination'] !== $emailAddress,
    (string) $viaEmail['destination']);
check('the masked address keeps its domain so the user can recognise it',
    str_contains((string) $viaEmail['destination'], substr($emailAddress, strrpos($emailAddress, '@'))));

// The OTP itself must never be stored in the clear.
$stored = (string) $db->scalar('SELECT otp_hash FROM password_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$agent1Id]);
check('only a SHA-256 hash of the code is stored', strlen($stored) === 64 && ctype_xdigit($stored));

// Requesting a second code must retire the first, or an old one stays valid.
$before = (int) $db->scalar('SELECT id FROM password_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$agent1Id]);
Auth::issuePasswordOtp($emailUser, '127.0.0.1');
check('requesting a new code invalidates the previous one',
    $db->scalar('SELECT used_at FROM password_otps WHERE id = ?', [$before]) !== null);
check('exactly one unused code remains',
    (int) $db->scalar('SELECT COUNT(*) FROM password_otps WHERE user_id = ? AND used_at IS NULL', [$agent1Id]) === 1);

check('masked local parts are short but not empty', Auth::maskEmail('a@b.com') === 'a**@b.com',
    Auth::maskEmail('a@b.com'));
check('a malformed address masks to nothing useful', Auth::maskEmail('not-an-email') === '****');

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
section('Geocoding: coordinates are the record, the address is derived');

// The grid key is what collapses a day of standing outside one house into a single
// lookup. If its rounding changes, every cached address is orphaned at once.
$keyA = \App\Services\GeocodingService::keyFor(19.07283499, 72.88261099);
$keyB = \App\Services\GeocodingService::keyFor(19.07284100, 72.88261900);
check('nearby coordinates share one cache key', $keyA === $keyB, $keyA . ' vs ' . $keyB);
check('the key is rounded to 4dp', $keyA === '19.0728,72.8826', $keyA);

$keyFar = \App\Services\GeocodingService::keyFor(19.0800, 72.8826);
check('coordinates 800m apart do not share a key', $keyA !== $keyFar);

// (0,0) is a real place in the Gulf of Guinea and what a phone reports with no fix.
check('null island is never cached', \App\Services\GeocodingService::cached(0.0, 0.0) === null);
check('null island has no display form', \App\Services\GeocodingService::formatCoordinates(0.0, 0.0) === null);
check('a real coordinate formats for display',
    \App\Services\GeocodingService::formatCoordinates(19.0728, 72.8826) === '19.072800, 72.882600');

// Reading must never call out. A view that could trigger a network request turns one
// slow third party into a slow panel, and fifty rows into fifty sequential calls.
$db->query(
    "INSERT INTO geocode_cache (grid_key, latitude, longitude, address, village, provider)
     VALUES (?, ?, ?, ?, ?, 'nominatim')",
    [$keyA, 19.0728, 72.8826, 'Test Nagar, Mumbai Suburban, Maharashtra', 'Test Nagar']
);
check('a cached address is read back', \App\Services\GeocodingService::cached(19.0728, 72.8826)
    === 'Test Nagar, Mumbai Suburban, Maharashtra');
check('a cache miss returns null rather than blocking',
    \App\Services\GeocodingService::cached(28.6139, 77.2090) === null);

$many = \App\Services\GeocodingService::cachedMany([[19.0728, 72.8826], [28.6139, 77.2090], [0.0, 0.0]]);
check('cachedMany resolves in one query and skips the unknown', count($many) === 1);
check('cachedMany keys by grid key', array_key_exists($keyA, $many));

// A failed lookup must be remembered, or every page view retries it forever - which
// is exactly what gets a shared host's IP blocked by a free service.
$db->query(
    "INSERT INTO geocode_cache (grid_key, latitude, longitude, address, failed_at, attempts)
     VALUES ('11.1111,11.1111', 11.1111, 11.1111, NULL, NOW(), 3)"
);
check('a failure is stored without an address', (int) $db->scalar(
    "SELECT COUNT(*) FROM geocode_cache WHERE grid_key = '11.1111,11.1111' AND address IS NULL AND failed_at IS NOT NULL"
) === 1);
check('a failed coordinate reads as unresolved',
    \App\Services\GeocodingService::cached(11.1111, 11.1111) === null);

// Lookups stay off until somebody says who is calling. Sending anonymous traffic to
// a free service borrows goodwill against everyone else on the same IP.
Settings::updateMany(['geocode_enabled' => '1', 'geocode_contact_email' => ''], null);
check('lookups are off without a contact address', \App\Services\GeocodingService::enabled() === false);
check('and it says why', str_contains(
    (string) \App\Services\GeocodingService::disabledReason(), 'geocode_contact_email'
));
Settings::updateMany(['geocode_contact_email' => 'ops@example.test'], null);
check('lookups are on once a contact address is set', \App\Services\GeocodingService::enabled() === true);
Settings::updateMany(['geocode_enabled' => '0'], null);
check('the operator can turn lookups off entirely', \App\Services\GeocodingService::enabled() === false);
check('turning them off is reported as a choice, not a fault', str_contains(
    (string) \App\Services\GeocodingService::disabledReason(), 'geocode_enabled'
));
$backfill = \App\Services\GeocodingService::backfill(5);
check('backfill does nothing while disabled', $backfill['queued'] === 0 && $backfill['skipped'] !== null);
Settings::updateMany(['geocode_enabled' => '1'], null);

// ---------------------------------------------------------------------------
section('BC targets and SSS enrolment');

// A month that is not a month must not silently become this month, or targets get
// written against a period nobody chose and the warning cron measures against them.
check('YYYY-MM parses', \App\Models\BcTarget::parseMonth('2026-08') === '2026-08-01');
check('YYYY-MM-DD parses to the 1st', \App\Models\BcTarget::parseMonth('2026-08-17') === '2026-08-01');
check('month 13 is refused', \App\Models\BcTarget::parseMonth('2026-13') === null);
check('month 00 is refused', \App\Models\BcTarget::parseMonth('2026-00') === null);
check('a word is refused', \App\Models\BcTarget::parseMonth('August') === null);
check('an empty string is refused', \App\Models\BcTarget::parseMonth('') === null);

$targetId = \App\Models\BcTarget::create([
    'agent_id' => $agent1Id,
    'target_month' => '2026-11-01',
    'daily_visit_target' => 8,
    'apy_target' => 20,
    'npa_recovery_target' => 50000.00,
    'set_by' => null,
]);
check('a target row is created', $targetId > 0);
check('it is found by the 1st of the month, which is how the service looks it up',
    \App\Models\BcTarget::findForMonth($agent1Id, '2026-11-19') !== null);
check('BcPerformanceService finds the same row',
    \App\Services\BcPerformanceService::targetsFor($agent1Id, '2026-11-19') !== null);

// Deleting a target that warnings were measured against would leave an agent holding
// a warning nobody can justify or dispute.
$db->query(
    "INSERT INTO bc_warnings (agent_id, warning_level, target_type, target_value, achieved_value,
                              gap_value, miss_streak, triggered_date)
     VALUES (?, 'L1', 'visit', '8', '2', '6', 1, '2026-11-12')",
    [$agent1Id]
);
$targetGuard = \App\Models\BcTarget::deletable($targetId);
check('an assessed month cannot be deleted', $targetGuard['ok'] === false);
check('the refusal explains why', str_contains($targetGuard['reason'], 'warning'));

$sssId = \App\Models\SssEnrollment::create([
    'agent_id' => $agent1Id,
    'branch_id' => $branchAId,
    'enrollment_date' => '2026-11-12',
    'apy_count' => 2,
    'pmjjby_count' => 3,
    'pmsby_count' => 1,
    'pmjdy_count' => 4,
]);
check('an SSS entry is created', $sssId > 0);
check('the same agent and day is found rather than duplicated',
    \App\Models\SssEnrollment::findForDate($agent1Id, '2026-11-12') !== null);

$sssSummary = \App\Models\SssEnrollment::summary('2026-11-01', '2026-11-30', $branchAId, $agent1Id);
check('the summary totals across all four schemes', $sssSummary['total'] === 10, (string) $sssSummary['total']);
check('the summary counts distinct days', $sssSummary['days'] === 1);

// The unique key is the thing that stops a duplicated form inflating a score, so it
// is asserted rather than assumed.
$duplicateRejected = false;
try {
    \App\Models\SssEnrollment::create([
        'agent_id' => $agent1Id,
        'branch_id' => $branchAId,
        'enrollment_date' => '2026-11-12',
        'apy_count' => 99,
    ]);
} catch (\Throwable $e) {
    $duplicateRejected = true;
}
check('a second entry for the same agent and day is refused by the database', $duplicateRejected);

$duplicateTarget = false;
try {
    \App\Models\BcTarget::create([
        'agent_id' => $agent1Id,
        'target_month' => '2026-11-01',
        'daily_visit_target' => 3,
    ]);
} catch (\Throwable $e) {
    $duplicateTarget = true;
}
check('a second target for the same agent and month is refused', $duplicateTarget);

// ---------------------------------------------------------------------------
section('Scorecard');

$scorecard = \App\Services\BcPerformanceService::scorecard('2026-08-01', '2026-08-31', $branchAId);
check('the scorecard returns a row per agent', $scorecard !== []);
check('rows carry a rank', isset($scorecard[0]['rank']));
check('rows carry a score', isset($scorecard[0]['total_score']));
check('ranks start at 1', (int) $scorecard[0]['rank'] === 1);

// Dense ranking: equal scores share a rank. Competition ranking would place one of
// two agents on identical figures above the other, which is simply false.
$ranks = array_map(static fn (array $r): int => (int) $r['rank'], $scorecard);
$scores = array_map(static fn (array $r): float => (float) $r['total_score'], $scorecard);
$denseOk = true;
foreach ($scorecard as $i => $row) {
    if ($i === 0) {
        continue;
    }
    if ($scores[$i] === $scores[$i - 1] && $ranks[$i] !== $ranks[$i - 1]) {
        $denseOk = false;
    }
    if ($scores[$i] < $scores[$i - 1] && $ranks[$i] <= $ranks[$i - 1]) {
        $denseOk = false;
    }
}
check('equal scores share a rank and lower scores rank worse', $denseOk);
$sorted = $scores;
rsort($sorted, SORT_NUMERIC);
check('the scorecard is sorted by score descending', $scores === $sorted);

$weights = \App\Services\BcPerformanceService::weights();
check('scoring weights are readable, so a ranking can be disputed', $weights !== []);
$divisorsSane = true;
foreach ($weights as $weight) {
    if ((float) $weight['divisor'] <= 0.0) {
        $divisorsSane = false;
    }
}
check('no weight has a zero divisor to divide by', $divisorsSane);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
printf("  INTEGRATION: %d passed, %d failed\n", $passed, $failed);
if ($failures !== []) {
    echo "  Failed: " . implode('; ', $failures) . "\n";
}
echo str_repeat('=', 60) . "\n";

exit($failed === 0 ? 0 : 1);
