<?php
/**
 * Standalone smoke test for the hand-rolled core engines (no database needed).
 *
 *   php tools/selftest-core.php
 *
 * Verifies the pieces that have no third-party library behind them:
 * Crypto (AES-256-GCM + HMAC), Jwt (HS256), Xlsx writer, XlsxReader round-trip,
 * Pdf writer structure, Validator rules, and Paginator maths.
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

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Jwt;
use App\Core\Pdf;
use App\Core\Validator;
use App\Core\Xlsx;
use App\Core\XlsxReader;

Config::load([
    'app_key'     => bin2hex(random_bytes(32)),
    'data_key'    => bin2hex(random_bytes(32)),
    'hash_pepper' => bin2hex(random_bytes(32)),
    'app'         => ['debug' => true, 'timezone' => 'Asia/Kolkata'],
]);
date_default_timezone_set('Asia/Kolkata');

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

function section(string $name): void
{
    echo "\n== {$name}\n";
}

// ---------------------------------------------------------------------------
section('Crypto');

$mobile = '9876543210';
$cipher = Crypto::encrypt($mobile);
check('encrypt returns non-null', $cipher !== null);
check('ciphertext differs from plaintext', $cipher !== $mobile);
check('ciphertext is base64 ASCII', $cipher !== null && preg_match('#^[A-Za-z0-9+/=]+$#', $cipher) === 1);
check('roundtrip decrypt', Crypto::decrypt($cipher) === $mobile, var_export(Crypto::decrypt($cipher), true));
check('fits VARBINARY(255)', $cipher !== null && strlen($cipher) <= 255, 'len=' . strlen((string) $cipher));

$aadhaar = '123456789012';
check('aadhaar roundtrip', Crypto::decrypt(Crypto::encrypt($aadhaar)) === $aadhaar);

check('nonce makes ciphertexts differ', Crypto::encrypt($mobile) !== Crypto::encrypt($mobile));
check('null passthrough', Crypto::encrypt(null) === null && Crypto::decrypt(null) === null);
check('tamper detection', Crypto::decrypt(base64_encode('v1' . str_repeat("\x00", 40))) === null);
check('garbage input is safe', Crypto::decrypt('not-base64!!!') === null);

$h1 = Crypto::searchHash('+91 98765 43210');
$h2 = Crypto::searchHash('09876543210');
$h3 = Crypto::searchHash('9876543210');
check('hash is deterministic across formats', $h1 === $h2 && $h2 === $h3, "{$h1} / {$h2} / {$h3}");
check('hash is 64 hex chars', $h1 !== null && strlen($h1) === 64 && ctype_xdigit($h1));
check('different numbers hash differently', Crypto::searchHash('9999999999') !== $h1);
check('mask mobile', Crypto::maskMobile('9876543210') === 'XXXXXX3210', (string) Crypto::maskMobile('9876543210'));
check('mask aadhaar', Crypto::maskAadhaar('123456789012') === 'XXXX XXXX 9012', (string) Crypto::maskAadhaar('123456789012'));

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// A blank key must fail loudly, and name itself.
//
// A live deployment ran with empty crypto keys. config.php existed, so the app
// booted and most of it worked; the failure only appeared when something touched
// encryption, as a bare HTTP 500. Creating a user with a mobile number failed,
// and so did any sign-in whose identifier contained a digit - because that falls
// through to the mobile-hash lookup, while an identifier with no digits never
// reaches the crypto and returned a clean 401. A fault that depends on whether
// your text contains a digit is nearly undiagnosable from outside.
// ---------------------------------------------------------------------------
$withKeys = static function (array $overrides, callable $fn): array {
    $original = [
        'app_key'     => Config::get('app_key'),
        'data_key'    => Config::get('data_key'),
        'hash_pepper' => Config::get('hash_pepper'),
    ];
    Config::load(array_merge([
        'db'  => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'x', 'user' => 'r', 'pass' => '', 'charset' => 'utf8mb4'],
        'app' => ['debug' => true, 'timezone' => 'Asia/Kolkata'],
    ], $original, $overrides));
    Crypto::reset();

    try {
        $fn();
        $result = ['threw' => false, 'message' => ''];
    } catch (\Throwable $e) {
        $result = ['threw' => true, 'message' => $e->getMessage()];
    }

    Config::load(array_merge([
        'db'  => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'x', 'user' => 'r', 'pass' => '', 'charset' => 'utf8mb4'],
        'app' => ['debug' => true, 'timezone' => 'Asia/Kolkata'],
    ], $original));
    Crypto::reset();
    return $result;
};

$blankPepper = $withKeys(['hash_pepper' => ''], static fn () => Crypto::searchHash('9876543210'));
check('a blank hash_pepper is refused rather than silently hashing nothing', $blankPepper['threw']);
check('and the error names the missing key', str_contains($blankPepper['message'], 'hash_pepper'),
    $blankPepper['message']);

$blankDataKey = $withKeys(['data_key' => ''], static fn () => Crypto::encrypt('9876543210'));
check('a blank data_key is refused', $blankDataKey['threw']);
check('and that error names its key too', str_contains($blankDataKey['message'], 'data_key'),
    $blankDataKey['message']);

$shortKey = $withKeys(['hash_pepper' => 'tooshort'], static fn () => Crypto::searchHash('9876543210'));
check('a key under 16 characters is refused', $shortKey['threw'], $shortKey['message']);

// A passphrase is allowed, so an operator who ignores the generator is not stuck.
$passphrase = $withKeys(
    ['hash_pepper' => 'a-long-enough-passphrase-instead-of-hex'],
    static fn () => Crypto::searchHash('9876543210')
);
check('a long passphrase is accepted and hashed', !$passphrase['threw'], $passphrase['message']);

section('JWT');

$token = Jwt::encode(['sub' => 42, 'role' => 'agent'], 600);
check('token has three segments', count(explode('.', $token)) === 3);
$claims = Jwt::decode($token);
check('decodes subject', ($claims['sub'] ?? null) === 42);
check('decodes role', ($claims['role'] ?? null) === 'agent');
check('has exp claim', isset($claims['exp']));
check('rejects tampered payload', Jwt::decode(
    explode('.', $token)[0] . '.' . Crypto::b64UrlEncode('{"sub":1}') . '.' . explode('.', $token)[2]
) === null);
check('rejects bad signature', Jwt::decode(substr($token, 0, -3) . 'aaa') === null);
check('rejects alg=none', Jwt::decode(
    Crypto::b64UrlEncode('{"typ":"JWT","alg":"none"}') . '.' . Crypto::b64UrlEncode('{"sub":1}') . '.'
) === null);
check('rejects malformed', Jwt::decode('abc') === null && Jwt::decode('') === null && Jwt::decode(null) === null);

$expired = Jwt::encode(['sub' => 7], -3600);
check('rejects expired token', Jwt::decode($expired) === null);
check('isExpired detects expiry', Jwt::isExpired($expired) === true);
check('isExpired false for valid', Jwt::isExpired($token) === false);

// ---------------------------------------------------------------------------
section('XLSX writer + reader roundtrip');

check('ZipArchive available', Xlsx::available());

$headings = ['Loan Account', 'Customer Name', 'Village', 'Outstanding', 'Overdue', 'NPA Date'];
$rows = [
    ['LN00000001', 'Ramesh Kumar',      'Bhilwara', 125000.50, 24500.00, '2024-03-31'],
    ['LN00000002', 'Sita Devi',         'Kotri',     78000.00, 12000.00, '2023-12-31'],
    ['LN00000003', "O'Brien & Sons <Co>", 'Mandal',   4500.25,   500.75, ''],
];
$totals = ['TOTAL', '', '', 207500.75, 37000.75, ''];

$xlsx = Xlsx::build('Daily Report', $headings, $rows, 'Daily Visit Report', 'For 30 Jul 2026', $totals);
check('workbook is a zip', str_starts_with($xlsx, "PK\x03\x04"));
check('workbook has size', strlen($xlsx) > 1500, 'bytes=' . strlen($xlsx));

$tmp = tempnam(sys_get_temp_dir(), 'lrms_test_') . '.xlsx';
file_put_contents($tmp, $xlsx);

$zip = new ZipArchive();
check('zip opens', $zip->open($tmp) === true);
foreach ([
    '[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml',
    'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml',
] as $part) {
    check("part exists: {$part}", $zip->locateName($part) !== false);
}
$sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
check('sheet xml parses', @simplexml_load_string($sheetXml) !== false);
check('xml escaping applied', str_contains($sheetXml, '&amp;') && str_contains($sheetXml, '&lt;'));
$zip->close();

$read = XlsxReader::read($tmp);
check('reader found headings', $read['headings'] === $headings, json_encode($read['headings']));
check('reader row count (incl. totals)', count($read['rows']) === 4, (string) count($read['rows']));
check('reader row 1 account', ($read['rows'][0][0] ?? '') === 'LN00000001', json_encode($read['rows'][0] ?? []));
check('reader preserves special chars', ($read['rows'][2][1] ?? '') === "O'Brien & Sons <Co>", json_encode($read['rows'][2] ?? []));
check('reader kept blank cell as empty (no column shift)', ($read['rows'][2][5] ?? 'x') === '', json_encode($read['rows'][2] ?? []));
check('reader numeric value', abs((float) ($read['rows'][0][3] ?? 0) - 125000.5) < 0.001, (string) ($read['rows'][0][3] ?? ''));
unlink($tmp);

// column name mapping
check('columnName 1=A', Xlsx::columnName(1) === 'A');
check('columnName 26=Z', Xlsx::columnName(26) === 'Z');
check('columnName 27=AA', Xlsx::columnName(27) === 'AA');
check('columnName 28=AB', Xlsx::columnName(28) === 'AB');

// ---------------------------------------------------------------------------
section('CSV reader');

$csvPath = tempnam(sys_get_temp_dir(), 'lrms_csv_') . '.csv';
file_put_contents($csvPath, "\xEF\xBB\xBFBranch,Loan Account Number,Customer Name,Mobile\nHO001,LN1,Ramesh,9876543210\nHO001,LN2,\"Devi, Sita\",9876543211\n");
$csv = XlsxReader::read($csvPath);
check('csv strips BOM from first heading', ($csv['headings'][0] ?? '') === 'Branch', json_encode($csv['headings']));
check('csv row count', count($csv['rows']) === 2);
check('csv quoted comma preserved', ($csv['rows'][1][2] ?? '') === 'Devi, Sita', json_encode($csv['rows'][1] ?? []));
unlink($csvPath);

$semiPath = tempnam(sys_get_temp_dir(), 'lrms_csv2_') . '.csv';
file_put_contents($semiPath, "Branch;Loan Account Number\nHO001;LN9\n");
$semi = XlsxReader::read($semiPath);
check('csv semicolon delimiter detected', ($semi['headings'][1] ?? '') === 'Loan Account Number', json_encode($semi['headings']));
unlink($semiPath);

// Bank exports often carry a merged title row and a blank spacer above the
// real header. Taking "first non-empty row" as the header shifts every column.
$titledPath = tempnam(sys_get_temp_dir(), 'lrms_csv3_') . '.csv';
file_put_contents(
    $titledPath,
    "NPA STATEMENT AS ON 31.03.2024,,,\n"
    . ",,,\n"
    . "Branch,Loan Account Number,Customer Name,Mobile\n"
    . "HO001,LN7,Ramesh,9876543210\n"
);
$titled = XlsxReader::read($titledPath);
check('csv skips title + spacer rows', ($titled['headings'][1] ?? '') === 'Loan Account Number', json_encode($titled['headings']));
check('csv title-row file yields 1 data row', count($titled['rows']) === 1, (string) count($titled['rows']));
check('csv title-row data intact', ($titled['rows'][0][1] ?? '') === 'LN7', json_encode($titled['rows'][0] ?? []));
unlink($titledPath);

// Ragged rows must not shift columns either.
$raggedPath = tempnam(sys_get_temp_dir(), 'lrms_csv4_') . '.csv';
file_put_contents($raggedPath, "Branch,Loan Account Number,Customer Name,Mobile\nHO001,LN8,Sita\n");
$ragged = XlsxReader::read($raggedPath);
check('ragged row padded to header width', count($ragged['rows'][0] ?? []) === 4, json_encode($ragged['rows'][0] ?? []));
unlink($raggedPath);

check('excel serial to date', XlsxReader::excelSerialToDate(45382.0) === '2024-03-31', (string) XlsxReader::excelSerialToDate(45382.0));

// ---------------------------------------------------------------------------
section('PDF writer');

$pdf = new Pdf('Daily Visit Report', 'For 30 Jul 2026 - All branches', true, 'Confidential');
$pdf->setColumns([
    ['label' => 'Loan Account', 'width' => 1.4],
    ['label' => 'Customer',     'width' => 1.6],
    ['label' => 'Village',      'width' => 1.2],
    ['label' => 'Outstanding',  'width' => 1.0, 'align' => 'right'],
    ['label' => 'Status',       'width' => 0.9],
]);
$pdf->tableHeader();
for ($i = 1; $i <= 120; $i++) {
    $pdf->row([
        'LN' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
        'Customer Name ' . $i . ' with a deliberately very long name to force truncation',
        'Village ' . $i,
        1000.0 * $i,
        'Pending',
    ]);
}
$pdf->totalRow(['TOTAL', '', '', 7260000.0, '']);
$pdf->spacer(12);
$pdf->heading('Remarks');
$pdf->paragraph('Unicode check: ₹ 1,25,000 — “quoted” … and a very long remark line that must wrap across multiple lines to prove the wrapping logic works without overflowing the printable width of the page.');
$pdf->keyValueBlock(['Customer Met' => 'Yes', 'House Locked' => 'No', 'Promise Amount' => '25,000', 'Promise Date' => '15 Aug 2026']);
$pdfBytes = $pdf->output();

check('pdf header', str_starts_with($pdfBytes, '%PDF-1.4'));
check('pdf trailer', str_contains($pdfBytes, '%%EOF'));
check('pdf has xref', str_contains($pdfBytes, "\nxref\n"));
check('pdf has catalog', str_contains($pdfBytes, '/Type /Catalog'));
check('pdf paginated to multiple pages', substr_count($pdfBytes, '/Type /Page ') >= 3, 'pages=' . substr_count($pdfBytes, '/Type /Page '));
check('pdf declares Helvetica', str_contains($pdfBytes, '/BaseFont /Helvetica'));
check('rupee transliterated (no raw UTF-8 rupee)', !str_contains($pdfBytes, "\xE2\x82\xB9"));
check('pdf Kids count matches page objects', (function () use ($pdfBytes): bool {
    if (preg_match('#/Count (\d+)#', $pdfBytes, $m) !== 1) {
        return false;
    }
    return (int) $m[1] === substr_count($pdfBytes, '/Type /Page ');
})());
check('pdf size sane', strlen($pdfBytes) > 5000, 'bytes=' . strlen($pdfBytes));

// xref offsets must actually point at "N 0 obj"
$xrefOk = true;
if (preg_match('#startxref\s+(\d+)#', $pdfBytes, $m) === 1) {
    $xrefPos = (int) $m[1];
    $xrefOk = substr($pdfBytes, $xrefPos, 4) === 'xref';
} else {
    $xrefOk = false;
}
check('startxref points at xref table', $xrefOk);

check('pdf text metrics measure width', Pdf::stringWidth('Hello', 10.0) > 0);
check('pdf fit truncates long text', str_ends_with(Pdf::fit('A very long value indeed', 30.0, 8.0, false), '...'));
check('pdf fit keeps short text', Pdf::fit('OK', 100.0, 8.0, false) === 'OK');
check('pdf wrap splits lines', count(Pdf::wrap(str_repeat('word ', 80), 200.0, 9.0, false)) > 1);
check('pdf text() converts rupee', Pdf::text('₹100') === 'Rs.100', Pdf::text('₹100'));

// ---------------------------------------------------------------------------
section('Validator');

$v = Validator::make(
    ['name' => '', 'mobile' => '12345', 'aadhaar' => '123456789012', 'amount' => 'abc', 'status' => 'nope'],
    [
        'name'    => 'required|max:150',
        'mobile'  => 'required|mobile',
        'aadhaar' => 'required|aadhaar',
        'amount'  => 'required|numeric',
        'status'  => 'required|in:active,inactive',
    ]
);
check('validator fails', $v->fails());
check('required error', isset($v->errors()['name']));
check('mobile error', isset($v->errors()['mobile']));
check('aadhaar passes', !isset($v->errors()['aadhaar']));
check('numeric error', isset($v->errors()['amount']));
check('in error', isset($v->errors()['status']));

$ok = Validator::make(
    ['name' => 'Ramesh', 'mobile' => '9876543210', 'visit_date' => '2026-07-30', 'visit_time' => '14:30', 'amt' => '1,250.50'],
    ['name' => 'required|max:150', 'mobile' => 'required|mobile', 'visit_date' => 'required|date', 'visit_time' => 'required|time', 'amt' => 'numeric']
);
check('valid payload passes', $ok->passes(), json_encode($ok->errors()));
check('optional empty field skipped', Validator::make(['x' => ''], ['x' => 'nullable|date'])->passes());
check('invalid date caught', Validator::make(['d' => '2026-02-31'], ['d' => 'date'])->fails());
check('confirmed rule', Validator::make(['p' => 'a', 'p_confirmation' => 'b'], ['p' => 'confirmed'])->fails());

// ---------------------------------------------------------------------------
section('Paginator');

$p = new App\Core\Paginator([], 0, 1, 25);
check('empty total pages = 1', $p->lastPage() === 1);
check('empty from = 0', $p->from() === 0);

$p2 = new App\Core\Paginator(array_fill(0, 25, ['id' => 1]), 137, 3, 25);
check('lastPage 137/25 = 6', $p2->lastPage() === 6);
check('from = 51', $p2->from() === 51, (string) $p2->from());
check('to = 75', $p2->to() === 75, (string) $p2->to());
check('hasNext', $p2->hasNext());
check('hasPrevious', $p2->hasPrevious());
check('meta shape', $p2->meta()['total'] === 137 && $p2->meta()['current_page'] === 3);
check('window has gaps', in_array('...', $p2->window(1), true), json_encode($p2->window(1)));

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 52) . "\n";
printf("  %d passed, %d failed\n", $passed, $failed);
echo str_repeat('-', 52) . "\n";

exit($failed === 0 ? 0 : 1);
