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

use App\Core\ColumnDetector;
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
section('Date-or-amount: cell formats, not guesswork');
// ---------------------------------------------------------------------------
// A date in a spreadsheet is a number wearing a date format. The reader used to
// guess from the value instead - "an integer in the Excel epoch window is a date"
// - which silently turned every whole-rupee balance between 32,874 and 65,380
// into a date. parseAmount() then read that date's YEAR as the amount, so a
// Rs 45,000 outstanding balance was imported as Rs 2,023. These assertions pin
// the fix from both directions: amounts must survive, dates must still convert.

/**
 * Builds a minimal workbook by hand with real number formats. The project's own
 * Xlsx writer never emits a date format, so this cannot be exercised with it.
 *
 * @param list<list<array{value:string,style:int}>> $rows
 */
$buildStyledWorkbook = static function (array $rows, string $customDateFormat): string {
    // cellXfs: 0 General, 1 built-in 14 (m/d/yyyy), 2 custom date, 3 custom currency.
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        . '<numFmt numFmtId="164" formatCode="' . htmlspecialchars($customDateFormat, ENT_QUOTES) . '"/>'
        . '<numFmt numFmtId="165" formatCode="&quot;Rs.&quot;#,##0.00"/>'
        . '</numFmts>'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '</cellXfs></styleSheet>';

    $sheetRows = '';
    foreach ($rows as $rowIndex => $cells) {
        $sheetRows .= '<row r="' . ($rowIndex + 1) . '">';
        $column = 'A';
        foreach ($cells as $cell) {
            $ref = $column . ($rowIndex + 1);
            $styleAttr = $cell['style'] > 0 ? ' s="' . $cell['style'] . '"' : '';
            $sheetRows .= is_numeric($cell['value'])
                ? '<c r="' . $ref . '"' . $styleAttr . '><v>' . $cell['value'] . '</v></c>'
                : '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t>'
                    . htmlspecialchars($cell['value'], ENT_QUOTES) . '</t></is></c>';
            $column++;
        }
        $sheetRows .= '</row>';
    }

    $path = tempnam(sys_get_temp_dir(), 'lrms_ds_') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetRows . '</sheetData></worksheet>');
    $zip->close();

    return $path;
};

$cell = static fn (string $value, int $style = 0): array => ['value' => $value, 'style' => $style];

$styledPath = $buildStyledWorkbook([
    [
        $cell('Account'), $cell('Outstanding'), $cell('Overdue'),
        $cell('NPA Date'), $cell('Sanction Date'), $cell('Updated At'),
    ],
    [
        $cell('LN001'),
        $cell('45000'),          // General: an amount, and squarely in the old danger band
        $cell('33000', 3),       // "Rs."#,##0.00 - quoted literal must not read as a date
        $cell('45382', 1),       // built-in numFmtId 14
        $cell('45382', 2),       // custom dd-mm-yyyy
        $cell('45382.625', 1),   // a date carrying a time fraction
    ],
], 'dd-mm-yyyy');

$styled = XlsxReader::read($styledPath);
$styledRow = $styled['rows'][0] ?? [];
unlink($styledPath);

check('unstyled integer amount stays an amount', ($styledRow[1] ?? '') === '45000', var_export($styledRow[1] ?? null, true));
check('currency-formatted amount stays an amount', ($styledRow[2] ?? '') === '33000', var_export($styledRow[2] ?? null, true));
check('built-in date format becomes a date', ($styledRow[3] ?? '') === '2024-03-31', var_export($styledRow[3] ?? null, true));
check('custom dd-mm-yyyy becomes a date', ($styledRow[4] ?? '') === '2024-03-31', var_export($styledRow[4] ?? null, true));
check('date with a time fraction becomes a date', ($styledRow[5] ?? '') === '2024-03-31', var_export($styledRow[5] ?? null, true));

// The whole point: the corrupted figure must reach parseAmount intact.
$parseAmount = new ReflectionMethod(App\Services\ImportService::class, 'parseAmount');
$parseAmount->setAccessible(true);
check(
    'Rs 45,000 imports as 45000.00, not as the year 2023',
    $parseAmount->invoke(null, (string) ($styledRow[1] ?? '')) === 45000.0,
    var_export($parseAmount->invoke(null, (string) ($styledRow[1] ?? '')), true),
);

// A format code made only of literal text and digits is not a date.
$formatCodeIsDate = new ReflectionMethod(XlsxReader::class, 'formatCodeIsDate');
$formatCodeIsDate->setAccessible(true);
foreach ([
    '#,##0.00' => false,
    '0.00%' => false,
    '"Rs."#,##0.00' => false,
    '[$-4009]#,##0.00' => false,
    '\R\s#,##0' => false,
    'dd-mm-yyyy' => true,
    'd mmm yyyy' => true,
    '[$-409]m/d/yy h:mm AM/PM' => true,
    '[h]:mm:ss' => true,
] as $code => $expected) {
    check(
        sprintf('format %s is %s a date', var_export($code, true), $expected ? '' : 'not'),
        $formatCodeIsDate->invoke(null, $code) === $expected,
    );
}

// ---------------------------------------------------------------------------
section('Column detection: any bank\'s spreadsheet');
// ---------------------------------------------------------------------------
// The importer no longer requires the file to be reformatted into our template,
// so these assertions stand in for "a real bank export lands and maps itself".

$detect = static function (array $headings, array $rows = [], array $overrides = []): array {
    $result = ColumnDetector::detect($headings, $rows, $overrides);
    $named = [];
    foreach ($result['map'] as $field => $index) {
        $named[$field] = $headings[$index] ?? '';
    }
    return [$named, $result];
};

// Our own template, which must of course still be perfect.
[$named] = $detect([
    'Branch', 'BC Code', 'Loan Account Number', 'Customer Name', 'Father/Husband Name',
    'Mobile', 'Aadhaar', 'Village', 'Address', 'Loan Type', 'Outstanding Amount',
    'Overdue Amount', 'NPA Date', 'Remarks',
]);
check('template maps all 14 of its own columns', count($named) === 14, (string) count($named));

// A core-banking export: shouted, abbreviated, underscored, reordered.
[$named] = $detect(
    ['SOL_ID', 'ACCT_NO', 'ACCT_NAME', 'CUST_ID', 'SANC_AMT', 'PRINCIPAL_OUTSTANDING',
     'OVERDUE_AMT', 'INT_OVERDUE', 'DP', 'NPA_DT', 'MOB_NO'],
    [['1234', '0123456789012', 'SITA DEVI', 'C0099', '200000', '145000.50', '12000', '3400', '150000', '2023-06-30', '9812345678']],
);
check('ACCT_NO is the account column', ($named['loan_account_number'] ?? '') === 'ACCT_NO');
check('ACCT_NAME is the customer name', ($named['customer_name'] ?? '') === 'ACCT_NAME');
check('PRINCIPAL_OUTSTANDING is outstanding', ($named['outstanding_amount'] ?? '') === 'PRINCIPAL_OUTSTANDING');
check('OVERDUE_AMT is overdue', ($named['overdue_amount'] ?? '') === 'OVERDUE_AMT');
check('INT_OVERDUE is interest overdue', ($named['interest_overdue'] ?? '') === 'INT_OVERDUE');
check('SANC_AMT is the sanction limit', ($named['sanction_limit'] ?? '') === 'SANC_AMT');
check('DP is drawing power', ($named['drawing_power'] ?? '') === 'DP');
check('NPA_DT is the NPA date', ($named['npa_date'] ?? '') === 'NPA_DT');
check('MOB_NO is the mobile', ($named['mobile'] ?? '') === 'MOB_NO');
check('CUST_ID is the CIF number', ($named['cif_number'] ?? '') === 'CUST_ID');
check('SOL_ID is the branch', ($named['branch'] ?? '') === 'SOL_ID');

// Five money columns side by side. Every one must come from its heading; a wrong
// balance in front of an agent is worse than a missing one.
[$named] = $detect(
    ['A/C No', 'Name', 'Sanction Limit', 'Drawing Power', 'Outstanding', 'Overdue', 'Interest Overdue'],
    [['LN1', 'Ramesh Kumar', '200000', '180000', '145000', '12000', '3400']],
);
check('sanction limit not confused with outstanding', ($named['sanction_limit'] ?? '') === 'Sanction Limit');
check('outstanding not confused with overdue', ($named['outstanding_amount'] ?? '') === 'Outstanding');
check('overdue not confused with interest', ($named['overdue_amount'] ?? '') === 'Overdue');
check('interest overdue kept separate', ($named['interest_overdue'] ?? '') === 'Interest Overdue');

// Two columns of indistinguishable numbers with useless headings: refuse to guess.
[$named] = $detect(['Account No', 'Name', 'Amount 1', 'Amount 2'], [['LN1', 'Ravi', '11111', '22222']]);
check('an ambiguous amount column is left unmapped', !isset($named['outstanding_amount']));
check('but the account and name are still found', isset($named['loan_account_number'], $named['customer_name']));

// The operator corrects it. -1 means "not in this file".
[$named] = $detect(['Account No', 'Name', 'Amount 1', 'Amount 2'], [['LN1', 'Ravi', '11111', '22222']], ['outstanding_amount' => 3]);
check('an override is obeyed', ($named['outstanding_amount'] ?? '') === 'Amount 2');
[$named] = $detect(['Loan Account Number', 'Customer Name', 'Mobile'], [], ['mobile' => -1]);
check('override -1 removes a field', !isset($named['mobile']));

// Hindi headings. The old normaliser stripped every non-ASCII character, which
// reduced these to empty strings.
[$named] = $detect(
    ['शाखा', 'खाता संख्या', 'नाम', 'पिता का नाम', 'मोबाइल', 'ग्राम', 'बकाया राशि'],
    [['कोटरी', 'LN551', 'सीता देवी', 'राम लाल', '9812345678', 'कोटरी', '45000']],
);
check('Hindi account heading maps', ($named['loan_account_number'] ?? '') === 'खाता संख्या');
check('Hindi name heading maps', ($named['customer_name'] ?? '') === 'नाम');
check('Hindi outstanding heading maps', ($named['outstanding_amount'] ?? '') === 'बकाया राशि');

// Typos and transpositions.
[$named] = $detect(['Brnach', 'Loan Acount Number', 'Custmer Name', 'Mobil No'], [['BR001', 'LN1', 'Ramesh', '9876543210']]);
check('transposed "Brnach" still maps', ($named['branch'] ?? '') === 'Brnach');
check('misspelled account heading maps', ($named['loan_account_number'] ?? '') === 'Loan Acount Number');
check('misspelled name heading maps', ($named['customer_name'] ?? '') === 'Custmer Name');

// No usable headings at all: the shape of the values has to carry it.
[$named, $result] = $detect(
    ['Column1', 'Column2', 'Column3', 'Column4'],
    [
        ['LN0000001', 'Ramesh Kumar', '9876543210', '234567890123'],
        ['LN0000002', 'Sita Devi', '9812345670', '345678901234'],
        ['LN0000003', 'Mohan Lal', '9812345671', '456789012345'],
    ],
);
check('account inferred from its shape', ($named['loan_account_number'] ?? '') === 'Column1');
check('name inferred from its shape', ($named['customer_name'] ?? '') === 'Column2');
check('mobile inferred from 10 digits starting 6-9', ($named['mobile'] ?? '') === 'Column3');
check('aadhaar inferred from 12 digits', ($named['aadhaar'] ?? '') === 'Column4');
check('inference is reported as weaker evidence', ($result['source']['mobile'] ?? '') === 'values');
check('inferred confidence stays below a header match', ($result['confidence']['mobile'] ?? 100) < 80);

// A repeated value is not an identifier, however account-shaped it looks.
[$named] = $detect(
    ['Column1', 'Column2'],
    array_fill(0, 6, ['KCC-2024', 'Ramesh Kumar']),
);
check('a repeating column is not taken for an account number', !isset($named['loan_account_number']));

// Header-row scoring, which is what finds the header under a title block.
check('a title row scores nothing', ColumnDetector::headerScore(['NPA STATEMENT AS ON 31.03.2024', '', '', '']) === 0);
check('a two-cell subtitle row scores nothing', ColumnDetector::headerScore(['Branch: BR001', 'As on: 31.03.2024', '', '']) === 0);
check('a data row scores nothing', ColumnDetector::headerScore(['HO001', 'LN7', 'Ramesh', '9876543210']) === 0);
check(
    'the real header row scores highest',
    ColumnDetector::headerScore(['Branch', 'Loan Account Number', 'Customer Name', 'Mobile']) > 0,
);

check('required fields are account number and customer name', ColumnDetector::required() === ['loan_account_number', 'customer_name']);

// The template we hand out must survive our own importer. This catches a new
// field whose label does not match its own vocabulary, and a sample row that has
// drifted out of step with the headings - which is what happened when the sample
// was a literal list and the field count grew.
$templateHeadings = [];
$templateRow = [];
foreach (ColumnDetector::fields() as $meta) {
    check('field "' . $meta['label'] . '" has a sample value', ($meta['example'] ?? '') !== '');
    $templateHeadings[] = $meta['label'];
    $templateRow[] = $meta['example'];
}

$templatePath = tempnam(sys_get_temp_dir(), 'lrms_tpl_') . '.xlsx';
file_put_contents($templatePath, Xlsx::build(
    'Lead Template',
    $templateHeadings,
    [$templateRow],
    'D2 Recovery Lead Import Template',
    'sample'
));
$templateRead = XlsxReader::read($templatePath);
unlink($templatePath);

check('template reads back with every column', count($templateRead['headings']) === count($templateHeadings), (string) count($templateRead['headings']));
$templateDetected = ColumnDetector::detect($templateRead['headings'], $templateRead['rows']);
check(
    'our own template maps every column back to its field',
    count($templateDetected['map']) === count($templateHeadings),
    'mapped ' . count($templateDetected['map']) . ' of ' . count($templateHeadings)
        . '; unmapped: ' . json_encode(array_values($templateDetected['unmapped']))
);
check('and nothing required is missing from it', $templateDetected['missing_required'] === []);

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
section('PDF image embedding');

// The writer carried no images at all until visit reports had to print the agent's
// photograph beside their signature, and each field photograph with the coordinates
// it was taken at. A report that says "Photos: 3" is not evidence of anything.
$imgDir = sys_get_temp_dir() . '/lrms_pdfimg_' . bin2hex(random_bytes(4));
@mkdir($imgDir, 0777, true);

// A photograph: large, RGB, baseline JPEG - the common case, and the one that must
// pass through untouched rather than being re-encoded.
$photo = imagecreatetruecolor(1600, 1200);
for ($x = 0; $x < 1600; $x += 8) {
    imagefilledrectangle($photo, $x, 0, $x + 7, 1199, imagecolorallocate($photo, $x % 255, 120, 200 - ($x % 200)));
}
imagejpeg($photo, $imgDir . '/photo.jpg', 90);
imagedestroy($photo);

// A signature: PNG line art on a TRANSPARENT background. Unflattened, the
// transparent pixels are zero in an RGB buffer, so the whole thing prints as a solid
// black rectangle over the signature block.
$sig = imagecreatetruecolor(600, 200);
imagesavealpha($sig, true);
imagefill($sig, 0, 0, imagecolorallocatealpha($sig, 0, 0, 0, 127));
imagesetthickness($sig, 6);
imagearc($sig, 300, 100, 400, 120, 20, 300, imagecolorallocate($sig, 5, 5, 5));
imagepng($sig, $imgDir . '/sign.png');
imagedestroy($sig);

// A progressive JPEG. Still DCT, but /DCTDecode cannot read it: embedded raw it
// opens without complaint and renders as a grey box, which is the kind of fault that
// reaches a printed report before anyone notices.
$prog = imagecreatetruecolor(400, 300);
imagefill($prog, 0, 0, imagecolorallocate($prog, 200, 30, 30));
imageinterlace($prog, true);
imagejpeg($prog, $imgDir . '/prog.jpg', 85);
imagedestroy($prog);

$imgPdf = new Pdf('Image embedding', 'photo, signature, progressive', false, 'test');
$imgPdf->heading('Agent');
$imgPdf->imageStrip([
    ['path' => $imgDir . '/photo.jpg', 'label' => 'Photograph', 'caption' => 'Lat 19.072835, Lng 72.882610'],
    ['path' => $imgDir . '/sign.png',  'label' => 'Signature',  'caption' => 'Ramesh Kumar'],
], 90.0);
$imgPdf->heading('Awkward inputs');
$imgPdf->imageStrip([
    ['path' => $imgDir . '/prog.jpg',    'label' => 'Progressive'],
    ['path' => $imgDir . '/missing.jpg', 'label' => 'Missing'],
    ['path' => $imgDir . '/sign.png',    'label' => 'Repeat'],
], 90.0);
$imgBytes = $imgPdf->output();

check('a pdf with images is still a pdf', str_starts_with($imgBytes, '%PDF-'));
check('three distinct images are embedded', substr_count($imgBytes, '/Subtype /Image') === 3,
    (string) substr_count($imgBytes, '/Subtype /Image'));
// Four draw operators for three images: the signature is used twice and must embed
// once. Without deduplication a report with the same signature on every page grows
// without bound.
check('a repeated file is embedded once but drawn twice', substr_count($imgBytes, ' Do') === 4,
    (string) substr_count($imgBytes, ' Do'));
check('a baseline photograph passes through as DCTDecode', substr_count($imgBytes, '/DCTDecode') === 2,
    (string) substr_count($imgBytes, '/DCTDecode'));
check('line art is carried as FlateDecode', substr_count($imgBytes, '/FlateDecode') === 1,
    (string) substr_count($imgBytes, '/FlateDecode'));
check('images are declared in the page resources', str_contains($imgBytes, '/XObject <<'));
check('a missing file degrades instead of aborting', str_contains($imgBytes, 'image unavailable'));

// The xref has to stay correct now that image objects sit between the fonts and the
// pages - a wrong offset table is a file that some viewers open and others reject.
$imgXrefOk = false;
if (preg_match('#startxref\s+(\d+)#', $imgBytes, $m) === 1) {
    $imgXrefOk = substr($imgBytes, (int) $m[1], 4) === 'xref';
}
check('the xref survives image objects being inserted', $imgXrefOk);

$declared = 0;
if (preg_match('#/Size (\d+)#', $imgBytes, $m) === 1) {
    $declared = (int) $m[1];
}
check('every object is accounted for in the trailer', $declared >= 5 + 3 + 1 + 1, (string) $declared);

// A progressive source must not still be progressive after embedding: GD strips the
// interlacing when it re-encodes, so no SOF2 marker may survive inside the streams.
check('no progressive JPEG survives into the file', !str_contains($imgBytes, "\xFF\xC2"));

check('canEmbed accepts a real image', $imgPdf->canEmbed($imgDir . '/photo.jpg'));
check('canEmbed rejects a missing file', !$imgPdf->canEmbed($imgDir . '/nope.jpg'));

// A text file with an image extension must be refused, not embedded and then served
// back later as an image.
file_put_contents($imgDir . '/fake.png', "#!/bin/sh\necho hello\n");
check('canEmbed rejects a non-image', !$imgPdf->canEmbed($imgDir . '/fake.png'));

// An empty strip must not draw an empty box or move the cursor.
$emptyPdf = new Pdf('Empty strip', '', false, '');
$before = strlen($emptyPdf->output());
$emptyPdf2 = new Pdf('Empty strip', '', false, '');
$emptyPdf2->imageStrip([]);
check('an empty image strip renders nothing', abs(strlen($emptyPdf2->output()) - $before) < 40);

array_map('unlink', glob($imgDir . '/*') ?: []);
@rmdir($imgDir);

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
