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
use App\Core\Geo;
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

// A PAN has letters in it, which is exactly why it cannot go through the mobile
// helpers. normalise() strips everything that is not a digit, so "ABCDE1234F" would
// become "1234" - two unrelated people would then share a search hash and a masked
// value, and the bug would only ever surface as a lookup returning the wrong borrower.
check('mask PAN keeps the last four characters',
    Crypto::maskPan('ABCDE1234F') === 'XXXXXX234F', (string) Crypto::maskPan('ABCDE1234F'));
check('a PAN normalises to upper case, punctuation removed',
    Crypto::normalisePan(' abcde-1234 f ') === 'ABCDE1234F', (string) Crypto::normalisePan(' abcde-1234 f '));
check('a PAN hashes the same however it is typed',
    Crypto::panHash('abcde 1234 f') === Crypto::panHash('ABCDE1234F'));
check('two PANs sharing a digit block hash differently',
    Crypto::panHash('ABCDE1234F') !== Crypto::panHash('ZZZZZ1234Q'));
check('and neither collapses to the digits alone',
    Crypto::panHash('ABCDE1234F') !== Crypto::searchHash('ABCDE1234F'));
check('a blank PAN yields nothing rather than an empty mask',
    Crypto::maskPan('') === null && Crypto::panHash(null) === null);

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
    'D2 Recovery Solutions & Services Lead Import Template',
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

// A report with no Hindi in it must not carry the embedded Devanagari font at
// all - it is a large object relative to everything else this class writes,
// and every English-only report still has to stay the small file it always
// has been.
check('an all-Latin PDF carries no embedded font', !str_contains($pdfBytes, '/FontFile2'));
check('and no Type0/CID font either', !str_contains($pdfBytes, '/Subtype /Type0'));

// ---------------------------------------------------------------------------
section('PDF writer: Devanagari (Hindi)');

// The 78 bugs this app has already caught taught the same lesson twice: a
// feature that only prints ASCII text is untested by an ASCII-only fixture.
// So this section prints a full mix - Hindi headings, Hindi values, a Hindi
// value next to a Latin one on the same line, a Hindi paragraph long enough
// to wrap, and a vowel sign that must be reordered before its consonant.
$hindiPdf = new Pdf('हिन्दी रिपोर्ट', 'सहायक विवरण', false, 'गोपनीय');
$hindiPdf->heading('उधारकर्ता की जानकारी');
$hindiPdf->keyValueBlock([
    'नाम'         => 'राम लाल',
    'गांव'        => 'कोटरी',
    'बकाया राशि'  => '45,000',
    'खाता संख्या Mixed' => 'LN00100 सिता',
]);
$hindiPdf->paragraph(
    'यह एक लंबा हिन्दी अनुच्छेद है जो कई पंक्तियों में लपेटा जाना चाहिए, ताकि यह '
    . 'साबित हो सके कि रैपिंग तर्क देवनागरी अक्षरों के साथ भी उतनी ही अच्छी तरह '
    . 'काम करता है जितना लैटिन अक्षरों के साथ करता है।'
);
$hindiPdf->setColumns([
    ['label' => 'खाता संख्या', 'width' => 1.2],
    ['label' => 'नाम', 'width' => 1.5],
    ['label' => 'बकाया राशि', 'width' => 1.0, 'align' => 'right'],
]);
$hindiPdf->tableHeader();
$hindiPdf->row(['LN551', 'सीता देवी', '45,000']);
$hindiBytes = $hindiPdf->output();

check('a PDF with Hindi text still opens as valid PDF 1.4', str_starts_with($hindiBytes, '%PDF-1.4'));
check('and closes with EOF', str_contains($hindiBytes, '%%EOF'));
check('the Devanagari font is embedded as a Type0/CIDFontType2 composite', str_contains($hindiBytes, '/Subtype /Type0') && str_contains($hindiBytes, '/Subtype /CIDFontType2'));
check('with Identity-H encoding, since Tj codes are already glyph IDs', str_contains($hindiBytes, '/Encoding /Identity-H'));
check('and an Identity CIDToGIDMap for the same reason', str_contains($hindiBytes, '/CIDToGIDMap /Identity'));
check('the actual TrueType outline data is embedded (FontFile2)', str_contains($hindiBytes, '/FontFile2'));
check('both weights are embedded, since headings and values differ in boldness', substr_count($hindiBytes, '/Subtype /Type0') === 2);
check('Hindi glyphs are drawn as hex CID strings, not literal text', preg_match('/<[0-9A-Fa-f]{4,}>\s*Tj/', $hindiBytes) === 1);
check('the rupee sign is still written as Rs., not left as a Devanagari-adjacent symbol', !str_contains($hindiBytes, "\xE2\x82\xB9"));

// The glyph-ID sequence for "राम" is deterministic given the fixed subset built by
// tools/build-devanagari-font-subset.py, so it can be asserted directly rather than
// merely asserting that "some hex string" exists - a wrong cmap lookup would still
// pass a test that only checked for the presence of *a* CID run.
check(
    'राम (Ram) encodes to its exact glyph IDs from the subset cmap',
    str_contains($hindiBytes, sprintf('<%04X%04X%04X>', 50, 64, 48))
);

// राशि (rāśi) carries the one reordering this writer implements: the vowel
// sign ि (U+093F) is encoded in Unicode AFTER श, but must be DRAWN before it.
check(
    'the ि vowel sign in राशि is reordered before its consonant, not left in encoded order',
    str_contains($hindiBytes, sprintf('%04X%04X', 65, 56))
        && !str_contains($hindiBytes, sprintf('%04X%04X', 56, 65))
);

// A line mixing scripts - "खाता संख्या Mixed" as a label, "LN00100 सिता" as its
// value - must draw the Latin words with Helvetica/WinAnsi and the Devanagari
// words with the embedded font on the SAME line, which is what makes this
// different from simply detecting "the string contains Devanagari" and
// switching the whole line to one font or the other.
check(
    'a mixed Hindi/Latin line uses both the embedded font and Helvetica',
    str_contains($hindiBytes, '/FH1') && str_contains($hindiBytes, '/FH2')
        && str_contains($hindiBytes, 'Mixed') && str_contains($hindiBytes, 'LN00100')
);

check('the Hindi PDF is larger than an equivalent-content Latin one (font data)', strlen($hindiBytes) > 4000);

// selftest-core.php's own OTHER checks already prove Latin text still renders
// correctly on a page carrying Hindi (the F1/F2 fonts are still declared and
// used above) - this just confirms neither declaration was dropped.
check('the standard Helvetica fonts are still declared alongside the embedded ones', str_contains($hindiBytes, '/BaseFont /Helvetica') && str_contains($hindiBytes, '/BaseFont /Helvetica-Bold'));

// ---------------------------------------------------------------------------
section('Devanagari: script detection and matra reordering');

use App\Core\Fonts\Devanagari;

check('a plain Latin string has no Devanagari', !Devanagari::containsDevanagari('LN00100 Ram Lal'));
check('a Hindi string is detected', Devanagari::containsDevanagari('राम लाल'));
check('a mixed string is detected too', Devanagari::containsDevanagari('LN00100 सिता'));

$reordered = Devanagari::reorderMatraI(Devanagari::codepoints('सिता'));
check('सिता (Sita) reorders ि before स', Devanagari::fromCodepoints($reordered) === 'िसता');

// A conjunct (क् + ष, joined by a visible virama) followed by the same vowel
// sign must move the WHOLE cluster's matra before the cluster, not just
// before the last consonant in it - reordering only ष would separate the
// vowel sign from the syllable it actually belongs to.
$conjunctReordered = Devanagari::reorderMatraI(Devanagari::codepoints("क्षि"));
check(
    'the vowel sign moves before a whole consonant cluster, not just its last letter',
    Devanagari::fromCodepoints($conjunctReordered) === "िक्ष"
);

check('a word with no matching vowel sign is left unchanged', Devanagari::reorderMatraI(Devanagari::codepoints('राम')) === Devanagari::codepoints('राम'));

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
// own photograph and each field photograph with the coordinates it was taken at. A
// report that says "Photos: 3" is not evidence of anything.
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

// Line art on a TRANSPARENT background - a logo or a stamp. Unflattened, the
// transparent pixels are zero in an RGB buffer, so the whole thing prints as a solid
// black rectangle over whatever it sits on.
$art = imagecreatetruecolor(600, 200);
imagesavealpha($art, true);
imagefill($art, 0, 0, imagecolorallocatealpha($art, 0, 0, 0, 127));
imagesetthickness($art, 6);
imagearc($art, 300, 100, 400, 120, 20, 300, imagecolorallocate($art, 5, 5, 5));
imagepng($art, $imgDir . '/lineart.png');
imagedestroy($art);

// A progressive JPEG. Still DCT, but /DCTDecode cannot read it: embedded raw it
// opens without complaint and renders as a grey box, which is the kind of fault that
// reaches a printed report before anyone notices.
$prog = imagecreatetruecolor(400, 300);
imagefill($prog, 0, 0, imagecolorallocate($prog, 200, 30, 30));
imageinterlace($prog, true);
imagejpeg($prog, $imgDir . '/prog.jpg', 85);
imagedestroy($prog);

$imgPdf = new Pdf('Image embedding', 'photo, line art, progressive', false, 'test');
$imgPdf->heading('Agent');
$imgPdf->imageStrip([
    ['path' => $imgDir . '/photo.jpg', 'label' => 'Photograph', 'caption' => 'Lat 19.072835, Lng 72.882610'],
    ['path' => $imgDir . '/lineart.png', 'label' => 'Line art', 'caption' => 'Ramesh Kumar'],
], 90.0);
$imgPdf->heading('Awkward inputs');
$imgPdf->imageStrip([
    ['path' => $imgDir . '/prog.jpg',    'label' => 'Progressive'],
    ['path' => $imgDir . '/missing.jpg', 'label' => 'Missing'],
    ['path' => $imgDir . '/lineart.png', 'label' => 'Repeat'],
], 90.0);
$imgBytes = $imgPdf->output();

check('a pdf with images is still a pdf', str_starts_with($imgBytes, '%PDF-'));
check('three distinct images are embedded', substr_count($imgBytes, '/Subtype /Image') === 3,
    (string) substr_count($imgBytes, '/Subtype /Image'));
// Four draw operators for three images: the line art is used twice and must embed
// once. Without deduplication a report carrying the same logo on every page grows
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

// ---------------------------------------------------------------------------
section('Blank signature boxes');

// Nothing captures a signature any more: the printed report carries empty ruled
// boxes and the paper is signed by hand. Which makes the box itself the deliverable,
// so it is tested like one. The failure mode this guards against is the opposite of
// imageStrip's - a block that renders NOTHING when there is nothing to draw would
// leave a form nobody can sign, and nothing else on the page would look wrong.
$sigPdf = new Pdf('Signature space', 'blank boxes', false, 'test');
$sigPdf->heading('Signatures');
$yBefore = $sigPdf->cursorY();
$sigPdf->signatureBlock([
    ['label' => 'Borrower Signature / Thumb Impression', 'caption' => "Ram Lal\nDate:"],
    ['label' => 'BC Agent Signature', 'caption' => "Suresh Yadav\nBC0007\nDate:"],
], 60.0);
$yAfter = $sigPdf->cursorY();
$sigBytes = $sigPdf->output();

check('a signature block draws a box for every signatory', substr_count($sigBytes, ' re') >= 2,
    (string) substr_count($sigBytes, ' re'));
// One ruled line per box, and it has to be INSIDE the box: people sign across a plain
// border and the scan clips the descenders. Counted against a page carrying only the
// heading, because the document furniture draws rules of its own.
$rulePdf = new Pdf('Signature space', 'blank boxes', false, 'test');
$rulePdf->heading('Signatures');
$ruleBaseline = substr_count($rulePdf->output(), ' l S');
check('and one rule inside each box to sign above',
    substr_count($sigBytes, ' l S') - $ruleBaseline === 2,
    (string) (substr_count($sigBytes, ' l S') - $ruleBaseline));
check('the labels are printed', str_contains($sigBytes, 'Borrower Signature') && str_contains($sigBytes, 'Agent Signature'));
check('the caption names whoever signs, and asks for a date', substr_count($sigBytes, 'Date:') === 2,
    (string) substr_count($sigBytes, 'Date:'));
// The cursor has to clear the boxes, or the next section prints on top of them. Measured
// exactly, not loosely: label 12 + box 60 + the TALLER caption's three lines at 9.6 + 10
// of breathing room. A ">=" here passed while captions were being counted as one line,
// which is how the newline bug survived - the block was the right size for the wrong
// reason and there was slack to hide in.
check('the cursor advances past the tallest cell',
    abs(($yBefore - $yAfter) - (12.0 + 60.0 + (3 * 9.6) + 10.0)) < 0.01,
    (string) round($yBefore - $yAfter, 1));
// No image is embedded, which is the whole point: an empty box cannot be mistaken
// for a captured mark.
check('no image is embedded for a blank signature', !str_contains($sigBytes, '/Subtype /Image'));

$noSigPdf = new Pdf('Signature space', '', false, '');
$noSigBefore = $noSigPdf->cursorY();
$noSigPdf->signatureBlock([]);
check('but an empty list still draws nothing', $noSigPdf->cursorY() === $noSigBefore);

// The approver signs alone, and a lone box stretched across the whole page reads as a
// different kind of field from the two half-width boxes above it. On a form, boxes that
// mean the same thing have to be the same size, so a column count can be forced.
$boxWidth = static function (string $bytes): float {
    preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+) re/', $bytes, $m);
    return (float) ($m[3][count($m[3]) - 1] ?? 0.0);
};

$wide = new Pdf('Signature space', '', false, '');
$wide->signatureBlock([['label' => 'Approver Signature', 'caption' => "Name\nDate:"]], 60.0);
$wideWidth = $boxWidth($wide->output());

$halved = new Pdf('Signature space', '', false, '');
$halved->signatureBlock([['label' => 'Approver Signature', 'caption' => "Name\nDate:"]], 60.0, 16.0, 2);
$halvedWidth = $boxWidth($halved->output());

check('a lone box fills the page by default', $wideWidth > 400.0, (string) round($wideWidth, 1));
check('and can be sized to a column instead, to match the boxes above it',
    $halvedWidth > 0 && abs($halvedWidth - (($wideWidth - 16.0) / 2)) < 0.5,
    (string) round($halvedWidth, 1));

// ---------------------------------------------------------------------------
section('The printed form: numbered bands and tick boxes');

// The visit report is now laid out as the paper form is, which means two new
// primitives carry it: a numbered section band, and a grid that prints EVERY option
// rather than only the ticked ones.
//
// That last part is a reversal of an earlier decision and the reason it is tested is
// the reason it was reversed: printing only what was true made an unticked box and a
// question the form never asked look identical, so "neighbours were not asked" and
// "this version had no such field" read the same on paper.
$formPdf = new Pdf('Field Visit Verification Report', 'form layout', false, 'test');
$formPdf->sectionBand(7, 'Documents Verified');
$formPdf->groupLabel('Case Type');
$formPdf->checkboxGrid([
    ['label' => 'Aadhaar Card', 'checked' => true],
    ['label' => 'PAN Card', 'checked' => false],
    ['label' => 'Electricity Bill', 'checked' => false],
], 3);
$formBytes = $formPdf->output();

check('a section band prints its number', str_contains($formBytes, '(7.)'));
check('and its title, in upper case', str_contains($formBytes, 'DOCUMENTS VERIFIED'));
check('a group label names the row of boxes', str_contains($formBytes, '(Case Type)'));
check('every option prints, ticked or not',
    str_contains($formBytes, '(Aadhaar Card)')
    && str_contains($formBytes, '(PAN Card)')
    && str_contains($formBytes, '(Electricity Bill)'));

// One square per option; the two extra strokes belong to the single ticked one.
$emptyGrid = new Pdf('Field Visit Verification Report', 'form layout', false, 'test');
$gridBaseline = substr_count($emptyGrid->output(), ' l S');

$oneTick = new Pdf('Field Visit Verification Report', 'form layout', false, 'test');
$oneTick->checkboxGrid([
    ['label' => 'Aadhaar Card', 'checked' => true],
    ['label' => 'PAN Card', 'checked' => false],
], 3);
$oneTickBytes = $oneTick->output();
check('a box is drawn for each option', substr_count($oneTickBytes, ' re') >= 2,
    (string) substr_count($oneTickBytes, ' re'));
// A tick, not a filled square: a solid block reads as a redaction on a photocopy.
check('a tick is two strokes, and only the ticked box gets them',
    substr_count($oneTickBytes, ' l S') - $gridBaseline === 2,
    (string) (substr_count($oneTickBytes, ' l S') - $gridBaseline));

$noneTicked = new Pdf('Field Visit Verification Report', 'form layout', false, 'test');
$noneTicked->checkboxGrid([
    ['label' => 'Aadhaar Card', 'checked' => false],
    ['label' => 'PAN Card', 'checked' => false],
], 3);
check('and an all-unticked row still prints its boxes',
    substr_count($noneTicked->output(), ' l S') - $gridBaseline === 0
    && str_contains($noneTicked->output(), '(Aadhaar Card)'));

$emptyList = new Pdf('Field Visit Verification Report', '', false, '');
$emptyListBefore = $emptyList->cursorY();
$emptyList->checkboxGrid([]);
check('an empty option list draws nothing', $emptyList->cursorY() === $emptyListBefore);

// A blank ruled line means "write here after printing", which a key/value pair
// showing "-" does not: that says the field is empty, not that it is meant to be
// filled in by hand.
$ruled = new Pdf('Field Visit Verification Report', '', false, '');
$ruledBaseline = substr_count((new Pdf('Field Visit Verification Report', '', false, ''))->output(), ' l S');
$ruled->ruledFields(['Verified On' => '12 Aug 2026', 'Date' => ''], 2);
$ruledBytes = $ruled->output();
check('a filled ruled field prints its value', str_contains($ruledBytes, '(12 Aug 2026)'));
check('and a blank one draws a line to write on',
    substr_count($ruledBytes, ' l S') - $ruledBaseline === 1,
    (string) (substr_count($ruledBytes, ' l S') - $ruledBaseline));

// ---------------------------------------------------------------------------
section('The printed form: masthead, ruled fields and page totals');

// The form is recognised by its head before it is read. A page that opens with a thin
// blue rule and a left-aligned heading is a printout; the masthead makes it the form.
$mast = new Pdf('Field Visit Verification Report', 'LN1 . Ram Lal', false, 'confidential');
$mast->useRunningHeader('D2 Recovery Solutions & Services  |  Field Visit Verification Report');
$mast->titleBlock('D2 Recovery Solutions & Services', 'Field Visit Verification Report', [
    '(KRM OTS / CKCC OD-2 Renewal / Recovery Verification Report)',
    "RBI Guidelines & Bank's Code of Conduct Compliant Format",
]);
$mastBytes = $mast->output();

check('the masthead carries the organisation in upper case',
    str_contains($mastBytes, 'D2 RECOVERY SOLUTIONS & SERVICES'));
check('and the document name under it',
    str_contains($mastBytes, 'FIELD VISIT VERIFICATION REPORT'));
check('and both strap lines',
    str_contains($mastBytes, 'Recovery Verification Report')
    && str_contains($mastBytes, 'Code of Conduct Compliant Format'));
// The running header replaces the tall branded band rather than being drawn under it -
// otherwise page one is laid out differently from every other page.
check('the running header replaces the report band',
    substr_count($mastBytes, 'Field Visit Verification Report') >= 1
    && !str_contains($mastBytes, 'Generated '));

// "Page 1 of 8" cannot be written while page 1 is being drawn, so it is stamped on at
// the end. A page count that says "Page 1" on an eight-page form is the thing somebody
// notices when two pages have gone missing in a fax.
check('every page says which of how many it is', str_contains($mastBytes, 'Page 1 of 1'));

$long = new Pdf('Field Visit Verification Report', '', false, '');
$long->useRunningHeader('running');
for ($i = 0; $i < 12; $i++) {
    $long->sectionBand($i + 1, 'Section ' . ($i + 1));
    $long->formFields(['Visit Date' => '02 Aug 2026', 'Visit Time' => '11:15 AM'], 2);
}
$longBytes = $long->output();
preg_match('/Page 1 of (\d+)/', $longBytes, $totalMatch);
$total = (int) ($totalMatch[1] ?? 0);
check('a multi-page form counts its own pages', $total >= 2, 'total=' . $total);
check('and every page carries the same total',
    substr_count($longBytes, 'of ' . $total) === $total,
    substr_count($longBytes, 'of ' . $total) . ' of ' . $total);

// A label beside a rule reads as a form; a label above a value reads as a report of
// what was recorded. The rule is what tells somebody holding a blank one where to write.
$ruledForm = new Pdf('f', '', false, '');
$ruledBase = substr_count((new Pdf('f', '', false, ''))->output(), ' l S');
$ruledForm->formFields(['Visit Date' => '02 Aug 2026', 'Visit Time' => ''], 2);
$ruledFormBytes = $ruledForm->output();

check('a form field prints its label with a colon', str_contains($ruledFormBytes, '(Visit Date :)'));
check('and its value on the rule', str_contains($ruledFormBytes, '(02 Aug 2026)'));
// Two rules: one under each field, filled or not. The rule is the form.
check('every field gets a rule, filled or blank',
    substr_count($ruledFormBytes, ' l S') - $ruledBase === 2,
    (string) (substr_count($ruledFormBytes, ' l S') - $ruledBase));

// A dash means "nothing recorded". On a form the way to say that is a blank rule -
// printing "-" on the line reads as a value somebody wrote there.
$dashed = new Pdf('f', '', false, '');
$dashed->formFields(['Sanction Limit' => '-'], 1);
check('a dash is printed as a blank rule, not as a value',
    !str_contains($dashed->output(), '(-)'));

// The label column is sized off the longest label actually passed in. A flat fraction
// truncated "Aadhaar (Last 4 Digits)" to "Aadhaar (Last 4 Di..." at three columns, and a
// form that abbreviates its own questions is not the form.
$wideLabels = new Pdf('f', '', false, '');
$wideLabels->formFields([
    'Aadhaar (Last 4 Digits)' => 'XXXX XXXX 0002',
    'PAN Number (Optional)'   => '',
    'Mobile Number'           => 'XXXXXX0002',
], 2);
check('a long label is not abbreviated', str_contains($wideLabels->output(), '(Aadhaar (Last 4 Digits) :)')
    || str_contains($wideLabels->output(), 'Aadhaar \\(Last 4 Digits\\) :'));

// The declaration is the one paragraph on the page somebody is agreeing to. Running it
// in the same grey as a helper line makes a certification look like guidance.
$callout = new Pdf('f', '', false, '');
$calloutBase = substr_count((new Pdf('f', '', false, ''))->output(), ' re');
$callout->calloutBox(['I hereby certify that this is true.'], '#fdf6e3', '#e3a008', '#3f3f46', 'Important Note');
$calloutBytes = $callout->output();
check('a callout prints its heading and its text',
    str_contains($calloutBytes, '(Important Note)')
    && str_contains($calloutBytes, 'I hereby certify'));
// Fill, border and the heavier leading bar: three rectangles, which is what makes it
// read as a callout rather than as another table cell.
check('and is drawn as a filled, bordered box with a leading bar',
    substr_count($calloutBytes, ' re') - $calloutBase === 3,
    (string) (substr_count($calloutBytes, ' re') - $calloutBase));
$emptyCallout = new Pdf('f', '', false, '');
$emptyCalloutBefore = $emptyCallout->cursorY();
$emptyCallout->calloutBox(['', '   ']);
check('but a box with nothing to say is not drawn at all',
    $emptyCallout->cursorY() === $emptyCalloutBefore);

// ---------------------------------------------------------------------------
section('Multi-line captions');

// A caption is "name\ncode\nposition", and text() used to strip control characters
// before wrap() ever saw the newlines - so every one of those printed as a single
// unbroken run: "Suresh YadavBC000726.912400, 75.787300". Every test still passed,
// because each phrase was individually present in the file.
check('text() keeps a newline, which is the one control character that means something',
    Pdf::text("Suresh Yadav\nBC0007") === "Suresh Yadav\nBC0007",
    str_replace("\n", '\\n', Pdf::text("Suresh Yadav\nBC0007")));
check('and still drops the ones that do not', Pdf::text("a\tb\x07c") === 'abc',
    Pdf::text("a\tb\x07c"));
check('wrap() breaks on it rather than running the lines together',
    Pdf::wrap(Pdf::text("Suresh Yadav\nBC0007\nDate:"), 400.0, 7.2, false)
        === ['Suresh Yadav', 'BC0007', 'Date:'],
    implode(' | ', Pdf::wrap(Pdf::text("Suresh Yadav\nBC0007\nDate:"), 400.0, 7.2, false)));

// And the other half of the fix: a newline that reaches a single-line draw must not go
// into the file as a control byte. It is a space there, because one text operator draws
// one line wherever the cursor already is.
$oneLine = new Pdf('Caption', "Ramesh\nKumar", false, '');
$oneLine->heading("Two\nWords");
$oneLineBytes = $oneLine->output();
check('a stray newline reaching a single line becomes a space, not a control byte',
    preg_match('/\([^()]*[\x00-\x09\x0B-\x1F][^()]*\)\s*Tj/', $oneLineBytes) !== 1);
check('and the words survive it', str_contains($oneLineBytes, 'Two Words'));

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
section('Geo: how a recorded position is put into words');
// ---------------------------------------------------------------------------

// This wording used to be private to VisitController::pdf(), so the panel could not
// reuse it and showed nothing at all - every photograph on screen was a bare
// thumbnail while the printed copy of the same photograph carried its coordinates.
check('coordinates print to six decimals',
    Geo::coordinates(26.9124, 75.7873) === '26.912400, 75.787300',
    Geo::coordinates(26.9124, 75.7873));
check('a string coordinate is formatted the same way',
    Geo::coordinates('26.9124', '75.7873') === '26.912400, 75.787300');
check('a negative coordinate keeps its sign',
    Geo::coordinates(-33.8688, 151.2093) === '-33.868800, 151.209300',
    Geo::coordinates(-33.8688, 151.2093));

check('accuracy reads as a tolerance', Geo::accuracy(12) === '+/-12 m', Geo::accuracy(12));
check('a missing accuracy prints nothing at all', Geo::accuracy(null) === '');
check('and so does an empty one', Geo::accuracy('') === '');

// A 2 km fix reads exactly like an 8 m one unless something says otherwise, and only
// one of them places somebody at a particular door.
check('8 m places a doorstep', Geo::isPrecise(8));
check('50 m is the limit and still counts', Geo::isPrecise(50));
check('51 m does not', !Geo::isPrecise(51));
check('a cell-tower fix does not', !Geo::isPrecise(2000));
check('an unreported accuracy is not precise', !Geo::isPrecise(null));
check('and neither is a zero', !Geo::isPrecise(0));

// The central rule: a missing coordinate is never silently missing.
check('a gallery pick says it was never going to have a position',
    Geo::caption(null, null, null, null, 'gallery') === 'Chosen from the gallery - no location recorded.',
    Geo::caption(null, null, null, null, 'gallery'));
check('a camera photograph with no fix says that instead',
    Geo::caption(null, null, null, null, 'camera') === 'Camera photograph, no location fix.');
check('a refusal is reported as a refusal',
    Geo::caption(null, null, null, null, 'denied') === 'Location recording was declined.');
check('and "no signal" stays a different sentence',
    Geo::caption(null, null, null, null, 'unavailable') === 'No location fix was available.');
check('an unknown source falls back to the caller\'s wording',
    Geo::caption(null, null, null, null, 'unknown', 'Nothing recorded.') === 'Nothing recorded.');
check('a time is appended to an absent position',
    Geo::caption(null, null, null, '02 Aug 2026', 'camera')
        === 'Camera photograph, no location fix. 02 Aug 2026');

check('a full caption carries position, accuracy and time',
    Geo::caption(26.9124, 75.7873, 12, '02 Aug 2026, 10:31 AM')
        === '26.912400, 75.787300 (+/-12 m) - 02 Aug 2026, 10:31 AM',
    Geo::caption(26.9124, 75.7873, 12, '02 Aug 2026, 10:31 AM'));
check('accuracy is omitted when the device did not report it',
    Geo::caption(26.9124, 75.7873, null, null) === '26.912400, 75.787300');

// An empty string is what a form post produces for a blank number, and it must not
// be read as a coordinate of zero.
check('a blank latitude is absent, not zero',
    Geo::caption('', '', null, null, 'camera') === 'Camera photograph, no location fix.');

check('a photo row is captioned from its own columns',
    Geo::photo(['gps_latitude' => 26.9124, 'gps_longitude' => 75.7873,
                'gps_accuracy_m' => 9, 'captured_at' => null, 'capture_source' => 'camera'])
        === '26.912400, 75.787300 (+/-9 m)');
check('a gallery photo row never borrows a position',
    Geo::photo(['gps_latitude' => null, 'gps_longitude' => null, 'gps_accuracy_m' => null,
                'captured_at' => null, 'capture_source' => 'gallery'])
        === 'Chosen from the gallery - no location recorded.');
check('an approval away from a fix is reported',
    Geo::approval(['approval_gps_source' => 'denied', 'approval_gps_latitude' => null])
        === 'Location declined by the approver');
check('an approval with a fix prints it',
    Geo::approval(['approval_gps_source' => 'device', 'approval_gps_latitude' => 19.0728,
                   'approval_gps_longitude' => 72.8826, 'approval_gps_accuracy_m' => 14])
        === '19.072800, 72.882600 (+/-14 m)');
check('a visit that declined location is not confused with one that had no signal',
    Geo::visit(['gps_source' => 'denied', 'gps_latitude' => null])
        !== Geo::visit(['gps_source' => 'unavailable', 'gps_latitude' => null]));

check('has() is false for a half-recorded position',
    !Geo::has(['gps_latitude' => 26.9124, 'gps_longitude' => null]));
check('has() is true only with both', Geo::has(['gps_latitude' => 1.0, 'gps_longitude' => 2.0]));

// The map link is a plain OpenStreetMap link: nothing about a borrower's location
// reaches a third party until a human clicks it, and no Google account or API key
// is needed to open it.
$mapUrl = Geo::mapUrl(26.9124, 75.7873);
check('the map link carries the coordinates', str_contains($mapUrl, 'mlat=26.912400&mlon=75.787300'), $mapUrl);
check('and needs no API key', !str_contains($mapUrl, 'key='));
check('and is OpenStreetMap, not Google Maps', str_contains($mapUrl, 'openstreetmap.org') && !str_contains($mapUrl, 'google'));

// Distance is what answers "was this photograph taken anywhere near the village it
// claims", so it has to be right rather than approximately right.
check('a point is zero metres from itself',
    Geo::distanceMetres(26.9124, 75.7873, 26.9124, 75.7873) === 0);
$oneMinuteNorth = Geo::distanceMetres(26.9124, 75.7873, 26.9124 + (1 / 60), 75.7873);
check('one minute of latitude is about 1852 m',
    $oneMinuteNorth !== null && abs($oneMinuteNorth - 1852) < 10, (string) $oneMinuteNorth);
check('a missing coordinate gives no distance',
    Geo::distanceMetres(26.9124, null, 26.9124, 75.7873) === null);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 52) . "\n";
printf("  %d passed, %d failed\n", $passed, $failed);
echo str_repeat('-', 52) . "\n";

exit($failed === 0 ? 0 : 1);
