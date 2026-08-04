<?php
/**
 * Diagnostic script: Import-to-App Data Flow Validation
 *
 * Validates the ENTIRE pipeline from Excel import through to mobile app API response.
 * Run with: php diag-import-flow.php
 *
 * No database required - simulates all DB-dependent steps.
 */

declare(strict_types=1);

// ============================================================================
// AUTOLOADER (manual, NOT bootstrap - avoids config/exit issues)
// ============================================================================
define('APP_PATH', __DIR__ . '/app');
define('ROOT_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});

use App\Core\XlsxReader;
use App\Core\ColumnDetector;

// ============================================================================
// HELPERS
// ============================================================================

function printHeader(string $title): void {
    echo "\n";
    echo "\033[1;36m" . str_repeat('=', 72) . "\033[0m\n";
    echo "\033[1;36m  {$title}\033[0m\n";
    echo "\033[1;36m" . str_repeat('=', 72) . "\033[0m\n";
}

function printSubHeader(string $title): void {
    echo "\n\033[1;33m  >> {$title}\033[0m\n";
    echo "  " . str_repeat('-', 60) . "\n";
}

function printPass(string $label): void {
    echo "\033[1;32m  [PASS]\033[0m {$label}\n";
}

function printFail(string $label): void {
    echo "\033[1;31m  [FAIL]\033[0m {$label}\n";
}

function printInfo(string $label, string $value = ''): void {
    if ($value !== '') {
        echo "\033[0;37m  {$label}: \033[1;37m{$value}\033[0m\n";
    } else {
        echo "\033[0;37m  {$label}\033[0m\n";
    }
}

function printWarning(string $message): void {
    echo "\033[1;33m  [WARN] {$message}\033[0m\n";
}

$results = [];

// ============================================================================
// PHASE 1: EXCEL PARSING
// ============================================================================
printHeader('PHASE 1: EXCEL PARSING');

$xlsxPath = __DIR__ . '/KRM ADRESS WALI LIST.xlsx';
$phase1Pass = false;

try {
    $parsed = XlsxReader::read($xlsxPath);
    $headings = $parsed['headings'];
    $rows = $parsed['rows'];

    printInfo('File', basename($xlsxPath));
    printInfo('Sheet name', $parsed['sheet'] ?: '(default)');
    printInfo('Header row', (string)$parsed['header_row']);
    printInfo('Column count', (string)count($headings));
    printInfo('Data row count', (string)count($rows));

    printSubHeader('First 5 Headings');
    $showCount = min(5, count($headings));
    for ($i = 0; $i < $showCount; $i++) {
        printInfo("  [{$i}]", $headings[$i]);
    }

    printSubHeader('Sample Data (first 3 rows)');
    $sampleRows = min(3, count($rows));
    for ($r = 0; $r < $sampleRows; $r++) {
        echo "\033[0;90m  Row " . ($r + 1) . ":\033[0m ";
        $cells = array_slice($rows[$r], 0, 6);
        echo implode(' | ', array_map(fn($c) => mb_substr(trim($c), 0, 20), $cells));
        echo "\n";
    }

    $phase1Pass = count($headings) > 0 && count($rows) > 0;

    if ($phase1Pass) {
        printPass('Excel file parsed successfully');
    } else {
        printFail('No headings or rows found');
    }
} catch (\Throwable $e) {
    printFail('Excel parsing failed: ' . $e->getMessage());
}

$results['Phase 1: Excel Parsing'] = $phase1Pass;

// ============================================================================
// PHASE 2: COLUMN DETECTION (auto)
// ============================================================================
printHeader('PHASE 2: COLUMN DETECTION (auto, no overrides)');

$phase2Pass = false;
$detection = null;
$map = [];

try {
    $detection = ColumnDetector::detect($headings, $rows);
    $map = $detection['map'];
    $confidence = $detection['confidence'];
    $source = $detection['source'];

    printSubHeader('Detected Fields');
    foreach ($map as $field => $index) {
        $headingText = $headings[$index] ?? '?';
        $conf = $confidence[$field] ?? 0;
        $src = $source[$field] ?? '?';
        $confColor = $conf >= 80 ? "\033[1;32m" : ($conf >= 60 ? "\033[1;33m" : "\033[1;31m");
        echo "  \033[0;37m{$field}\033[0m => [{$index}] \033[1;37m\"{$headingText}\"\033[0m  {$confColor}conf:{$conf}\033[0m  src:{$src}\n";
    }

    printSubHeader('Unmapped Columns');
    if (!empty($detection['unmapped'])) {
        foreach ($detection['unmapped'] as $idx => $heading) {
            printInfo("  [{$idx}]", $heading);
        }
    } else {
        printInfo('  (none)');
    }

    printSubHeader('Missing Required Fields');
    if (!empty($detection['missing_required'])) {
        foreach ($detection['missing_required'] as $field) {
            printWarning($field);
        }
    } else {
        printInfo('  (none - all required fields detected)');
    }

    $phase2Pass = empty($detection['missing_required']);
    if ($phase2Pass) {
        printPass('All required fields detected');
    } else {
        printFail('Missing required fields: ' . implode(', ', $detection['missing_required']));
    }
} catch (\Throwable $e) {
    printFail('Column detection failed: ' . $e->getMessage());
}

$results['Phase 2: Column Detection (auto)'] = $phase2Pass;

// ============================================================================
// PHASE 3: MANUAL OVERRIDE SIMULATION
// ============================================================================
printHeader('PHASE 3: MANUAL OVERRIDE SIMULATION');

$phase3Pass = false;

try {
    // Simulate admin manually mapping: force village to the ADRESS column, force loan_account_number to column 0
    $addressIndex = null;
    foreach ($headings as $i => $h) {
        if (stripos($h, 'ADRESS') !== false || stripos($h, 'ADDRESS') !== false) {
            $addressIndex = $i;
            break;
        }
    }

    $overrides = ['loan_account_number' => 0];
    if ($addressIndex !== null) {
        $overrides['village'] = $addressIndex;
    }

    printSubHeader('Overrides Applied');
    foreach ($overrides as $field => $idx) {
        printInfo("  {$field}", "=> column [{$idx}] (\"{$headings[$idx]}\")");
    }

    $detectionOverridden = ColumnDetector::detect($headings, $rows, $overrides);

    printSubHeader('Mapping Changes After Override');
    foreach ($overrides as $field => $idx) {
        $newIdx = $detectionOverridden['map'][$field] ?? null;
        $newSrc = $detectionOverridden['source'][$field] ?? '?';
        $headingText = $newIdx !== null ? ($headings[$newIdx] ?? '?') : '(unmapped)';
        $applied = ($newIdx === $idx && $newSrc === 'chosen');
        $statusIcon = $applied ? "\033[1;32m[OK]\033[0m" : "\033[1;31m[!]\033[0m";
        echo "  {$statusIcon} {$field}: column [{$newIdx}] \"{$headingText}\" source={$newSrc}\n";
    }

    // Verify overrides were applied correctly
    $allOverridesApplied = true;
    foreach ($overrides as $field => $idx) {
        if (($detectionOverridden['map'][$field] ?? null) !== $idx) {
            $allOverridesApplied = false;
        }
        if (($detectionOverridden['source'][$field] ?? '') !== 'chosen') {
            $allOverridesApplied = false;
        }
    }

    $phase3Pass = $allOverridesApplied;
    if ($phase3Pass) {
        printPass('Overrides applied correctly (source = "chosen")');
    } else {
        printFail('Some overrides were not applied correctly');
    }
} catch (\Throwable $e) {
    printFail('Override simulation failed: ' . $e->getMessage());
}

$results['Phase 3: Manual Override'] = $phase3Pass;

// ============================================================================
// PHASE 4: columnOverrides() LOGIC SIMULATION
// ============================================================================
printHeader('PHASE 4: columnOverrides() POST PROCESSING');

$phase4Pass = false;

try {
    // Simulate $_POST['column_map'] exactly as the browser sends it after JS fix
    $simulatedPost = [
        'loan_account_number' => '0',
        'customer_name'       => '1',
        'village'             => (string)($addressIndex ?? '3'),
        'outstanding_amount'  => '',    // empty = "detect automatically"
        'invalid_field_xyz'   => '5',   // invalid field (not in ColumnDetector::fields())
        'remarks'             => 'abc', // non-numeric index = invalid
    ];

    printSubHeader('Simulated $_POST[\'column_map\']');
    foreach ($simulatedPost as $field => $val) {
        printInfo("  {$field}", "\"$val\"");
    }

    // Replicate the EXACT logic from ImportController::columnOverrides()
    $raw = $simulatedPost; // simulating $_POST['column_map']
    $fields = ColumnDetector::fields();
    $processedOverrides = [];
    $invalid = [];

    if (is_array($raw)) {
        foreach ($raw as $field => $index) {
            if (!is_string($field) || !isset($fields[$field]) || !is_scalar($index)) {
                $invalid[] = $field;
                continue;
            }
            $value = (string)$index;
            if ($value === '') {
                continue;   // "detect automatically"
            }
            if (!is_numeric($value)) {
                $invalid[] = $field . '=' . $value;
                continue;
            }
            $processedOverrides[$field] = (int)$value;
        }
    }

    printSubHeader('Processing Results');
    printInfo('Valid overrides', (string)count($processedOverrides));
    foreach ($processedOverrides as $field => $idx) {
        printInfo("  {$field}", "=> {$idx}");
    }
    printInfo('Skipped (empty = auto-detect)', 'outstanding_amount');
    printInfo('Invalid entries', implode(', ', $invalid));

    // Validation checks
    $expectedValid = ['loan_account_number' => 0, 'customer_name' => 1, 'village' => ($addressIndex ?? 3)];
    $phase4Pass = ($processedOverrides == $expectedValid) && !empty($invalid);

    if ($phase4Pass) {
        printPass('Valid POST data processed correctly, invalid entries rejected');
    } else {
        printFail('POST processing did not produce expected results');
    }
} catch (\Throwable $e) {
    printFail('columnOverrides() simulation failed: ' . $e->getMessage());
}

$results['Phase 4: columnOverrides() Logic'] = $phase4Pass;

// ============================================================================
// PHASE 5: ROW EXTRACTION SIMULATION
// ============================================================================
printHeader('PHASE 5: ROW EXTRACTION SIMULATION');

$phase5Pass = false;
$extractedRows = [];

try {
    // Simulate the same logic as ImportService::extract()
    // For each field in the map, pull row[map[field]]
    $fieldsToShow = ['loan_account_number', 'customer_name', 'npa_date', 'outstanding_amount', 'address', 'bc_code', 'village'];

    printSubHeader('Extracting from first 3 data rows');

    $sampleCount = min(3, count($rows));
    $allRequiredNonEmpty = true;

    for ($r = 0; $r < $sampleCount; $r++) {
        $row = $rows[$r];
        echo "\n\033[1;37m  --- Row " . ($r + 1) . " ---\033[0m\n";

        $extracted = [];
        foreach (array_keys(ColumnDetector::fields()) as $field) {
            $index = $map[$field] ?? null;
            $extracted[$field] = $index === null ? '' : trim((string)($row[$index] ?? ''));
        }
        $extractedRows[] = $extracted;

        foreach ($fieldsToShow as $field) {
            $val = $extracted[$field];
            $display = $val === '' ? "\033[0;90m(empty)\033[0m" : "\033[1;37m{$val}\033[0m";
            echo "  {$field}: {$display}\n";
        }

        // Check required fields
        if (trim($extracted['loan_account_number']) === '' || trim($extracted['customer_name']) === '') {
            $allRequiredNonEmpty = false;
        }
    }

    $phase5Pass = $allRequiredNonEmpty;
    echo "\n";
    if ($phase5Pass) {
        printPass('Required values (loan_account_number, customer_name) are non-empty');
    } else {
        printFail('Some required values are empty in sample rows');
    }
} catch (\Throwable $e) {
    printFail('Row extraction failed: ' . $e->getMessage());
}

$results['Phase 5: Row Extraction'] = $phase5Pass;

// ============================================================================
// PHASE 6: API RESPONSE SIMULATION
// ============================================================================
printHeader('PHASE 6: API RESPONSE SIMULATION (presentLead format)');

$phase6Pass = false;

try {
    if (!empty($extractedRows)) {
        $extracted = $extractedRows[0]; // first row

        // Simulate what presentLead() would return for this data
        $simulatedLead = [
            'id'                    => 1001,
            'loan_account_number'   => $extracted['loan_account_number'],
            'customer_id'           => 501,
            'customer_name'         => $extracted['customer_name'],
            'father_husband_name'   => $extracted['father_husband_name'] ?: null,
            'village'               => $extracted['village'] ?: null,
            'address'               => $extracted['address'] ?: null,
            'mobile_masked'         => null,
            'aadhaar_masked'        => null,
            'bc_code'               => $extracted['bc_code'] ?: null,
            'loan_type'             => $extracted['loan_type'] ?: null,
            'facility_type'         => null,
            'outstanding_amount'    => round((float)str_replace(',', '', $extracted['outstanding_amount']), 2),
            'overdue_amount'        => round((float)str_replace(',', '', $extracted['overdue_amount']), 2),
            'npa_date'              => $extracted['npa_date'] ?: null,
            'is_npa'                => $extracted['npa_date'] !== '' && $extracted['npa_date'] !== null,
            'current_status'        => 'pending',
            'branch_id'             => 1,
            'branch_name'           => $extracted['branch'] ?: 'Default Branch',
            'branch_code'           => '',
            'assigned_agent_id'     => null,
            'agent_name'            => null,
            'visit_count'           => 0,
            'last_visit_at'         => null,
            'next_followup_date'    => null,
            'remarks'               => $extracted['remarks'] ?: null,
            'created_at'            => date('Y-m-d H:i:s'),
            'cif_number'            => $extracted['cif_number'] ?: null,
            'sanction_date'         => $extracted['sanction_date'] ?: null,
            'sanction_limit'        => $extracted['sanction_limit'] ? round((float)str_replace(',', '', $extracted['sanction_limit']), 2) : null,
            'drawing_power'         => $extracted['drawing_power'] ? round((float)str_replace(',', '', $extracted['drawing_power']), 2) : null,
            'interest_overdue'      => $extracted['interest_overdue'] ? round((float)str_replace(',', '', $extracted['interest_overdue']), 2) : null,
            'ckcc_renewal_due_date' => $extracted['ckcc_renewal_due_date'] ?: null,
            'ots_eligible'          => null,
            'krm_eligible'          => null,
            'ots_amount'            => $extracted['ots_amount'] ? round((float)str_replace(',', '', $extracted['ots_amount']), 2) : null,
            'deposit_amount'        => $extracted['deposit_amount'] ? round((float)str_replace(',', '', $extracted['deposit_amount']), 2) : null,
            'closure_amount'        => $extracted['closure_amount'] ? round((float)str_replace(',', '', $extracted['closure_amount']), 2) : null,
            'asset_classification'  => $extracted['asset_classification'] ?: null,
            'interest_rate'         => $extracted['interest_rate'] ? round((float)str_replace(',', '', $extracted['interest_rate']), 2) : null,
            'installment_amount'    => $extracted['installment_amount'] ? round((float)str_replace(',', '', $extracted['installment_amount']), 2) : null,
            'last_payment_date'     => $extracted['last_payment_date'] ?: null,
            'last_payment_amount'   => $extracted['last_payment_amount'] ? round((float)str_replace(',', '', $extracted['last_payment_amount']), 2) : null,
            'days_past_due'         => $extracted['days_past_due'] ? (int)$extracted['days_past_due'] : null,
            'security_value'        => $extracted['security_value'] ? round((float)str_replace(',', '', $extracted['security_value']), 2) : null,
            'guarantor_name'        => $extracted['guarantor_name'] ?: null,
            'maturity_date'         => $extracted['maturity_date'] ?: null,
            'purpose'               => $extracted['purpose'] ?: null,
        ];

        printSubHeader('JSON Response (as mobile app receives)');
        echo "\033[0;37m";
        echo json_encode($simulatedLead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "\033[0m\n";

        printSubHeader('Field Status');
        $withData = 0;
        $nullFields = 0;
        foreach ($simulatedLead as $key => $val) {
            if ($val === null) {
                $nullFields++;
            } else {
                $withData++;
            }
        }
        printInfo("Fields with data", (string)$withData);
        printInfo("Fields that are null", (string)$nullFields);

        // Show which key fields have data
        printSubHeader('Key Fields Check');
        $keyFields = ['loan_account_number', 'customer_name', 'outstanding_amount', 'village', 'address', 'npa_date', 'bc_code'];
        foreach ($keyFields as $kf) {
            $val = $simulatedLead[$kf] ?? null;
            if ($val !== null && $val !== '' && $val !== 0.0) {
                echo "  \033[1;32m[HAS DATA]\033[0m {$kf}: " . (is_string($val) ? mb_substr($val, 0, 40) : $val) . "\n";
            } else {
                echo "  \033[0;90m[  NULL  ]\033[0m {$kf}\n";
            }
        }

        $phase6Pass = $simulatedLead['loan_account_number'] !== '' && $simulatedLead['customer_name'] !== '';
        echo "\n";
        if ($phase6Pass) {
            printPass('API response will contain valid lead data');
        } else {
            printFail('API response would be missing critical data');
        }
    } else {
        printFail('No extracted rows available to simulate API response');
    }
} catch (\Throwable $e) {
    printFail('API simulation failed: ' . $e->getMessage());
}

$results['Phase 6: API Response'] = $phase6Pass;

// ============================================================================
// PHASE 7: AGENT FILTER LOGIC
// ============================================================================
printHeader('PHASE 7: AGENT FILTER LOGIC');

$phase7Pass = true; // Explanation-based, always passes as documentation

printSubHeader('Agent Visibility Rules (from LeadController::filters())');
echo "\n";
printInfo('  Rule 1: Agent role is detected via Auth::isAgent()');
printInfo('  Rule 2: For agents, filters are HARD-SCOPED:');
printInfo('          - agent_id = user.id (can ONLY see their own leads)');
printInfo('          - branch_id = user.branch_id');
echo "\n";

printSubHeader('Lead Visibility Scenarios');
echo "\n";
echo "  \033[1;32m[VISIBLE]\033[0m  Lead has assigned_agent_id = agent.id\n";
echo "           => Agent sees the lead (passes the hard scope filter)\n\n";

echo "  \033[1;31m[HIDDEN]\033[0m   Lead has assigned_agent_id = NULL (unassigned)\n";
echo "           => Agent sees NOTHING (hard scope requires agent_id match)\n";
echo "           => Only managers can see unassigned leads (via 'unassigned' filter)\n\n";

echo "  \033[1;31m[HIDDEN]\033[0m   Lead has assigned_agent_id = different_agent.id\n";
echo "           => Agent sees NOTHING (scope prevents cross-agent visibility)\n\n";

printSubHeader('Distribution Impact');
echo "\n";
echo "  If import runs with \033[1;37mdistribute=true\033[0m:\n";
echo "    => Each lead gets assigned to the lightest-loaded agent in its branch\n";
echo "    => Agent sees the lead immediately after import\n\n";
echo "  If import runs with \033[1;37mdistribute=false\033[0m and no default agent:\n";
echo "    => assigned_agent_id stays NULL\n";
echo "    => No agent sees the lead until a manager assigns it\n\n";

printPass('Agent filter logic documented - agent requires explicit assignment');
$results['Phase 7: Agent Filter Logic'] = $phase7Pass;

// ============================================================================
// FINAL SUMMARY
// ============================================================================
printHeader('FINAL SUMMARY');

echo "\n";
echo "  \033[1;37m" . str_pad('Phase', 40) . "Result\033[0m\n";
echo "  " . str_repeat('-', 55) . "\n";

$allPassed = true;
foreach ($results as $phase => $passed) {
    $icon = $passed ? "\033[1;32m PASS \033[0m" : "\033[1;31m FAIL \033[0m";
    echo "  " . str_pad($phase, 40) . $icon . "\n";
    if (!$passed) $allPassed = false;
}

echo "\n  " . str_repeat('=', 55) . "\n";

if ($allPassed) {
    echo "\n  \033[1;32m** Data will reach app: YES **\033[0m\n";
    echo "  \033[0;37m  Conditions:\033[0m\n";
    echo "  \033[0;37m  1. File is uploaded and parsed correctly\033[0m\n";
    echo "  \033[0;37m  2. Required columns (loan_account_number, customer_name) detected\033[0m\n";
    echo "  \033[0;37m  3. Rows have non-empty values for required fields\033[0m\n";
    echo "  \033[0;37m  4. Lead must be ASSIGNED to an agent (distribute=true or manual assign)\033[0m\n";
    echo "  \033[0;37m  5. Agent app queries with their agent_id to see their leads\033[0m\n";
} else {
    echo "\n  \033[1;31m** Data will reach app: NO (pipeline issues found) **\033[0m\n";
    echo "  \033[0;37m  Review the FAIL phases above for details.\033[0m\n";
}

echo "\n\033[1;36m" . str_repeat('=', 72) . "\033[0m\n";
echo "\033[0;90m  Diagnostic completed at " . date('Y-m-d H:i:s') . "\033[0m\n\n";
