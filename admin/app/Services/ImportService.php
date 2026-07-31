<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\XlsxReader;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Timeline;

/**
 * Excel/CSV lead import.
 *
 * Pipeline: upload -> parse -> map headers -> validate row -> resolve branch ->
 * detect duplicate loan account number -> update existing or insert new ->
 * optional bulk agent assignment -> summary + downloadable error log.
 *
 * The whole file runs inside one transaction: a file either imports cleanly
 * (rejected rows reported) or leaves the database untouched.
 */
final class ImportService
{
    /**
     * Canonical column => accepted header spellings (lowercased, non-alphanumerics stripped).
     * Real branch files vary wildly, so each field accepts several aliases.
     *
     * @var array<string,list<string>>
     */
    private const COLUMN_ALIASES = [
        'branch' => ['branch', 'branchname', 'branchcode', 'brcode', 'br', 'branchcd'],
        'bc_code' => ['bccode', 'bc', 'dccode', 'bcdccode', 'bcid', 'agentcode'],
        'loan_account_number' => [
            'loanaccountnumber', 'loanaccountno', 'loanacno', 'accountnumber', 'accountno',
            'acno', 'loanac', 'loanno', 'accno', 'loanaccount',
        ],
        'customer_name' => ['customername', 'name', 'borrowername', 'customer', 'borrower'],
        'father_husband_name' => [
            'fatherhusbandname', 'fathername', 'husbandname', 'fatherhusband',
            'fathersname', 'guardianname', 'fatherorhusbandname',
        ],
        'mobile' => ['mobile', 'mobileno', 'mobilenumber', 'phone', 'phoneno', 'contact', 'contactno', 'cellno'],
        'aadhaar' => ['aadhaar', 'aadhar', 'aadhaarno', 'aadharno', 'aadhaarnumber', 'uid', 'uidno'],
        'village' => ['village', 'villagename', 'place', 'city', 'town'],
        'address' => ['address', 'fulladdress', 'residentialaddress', 'addressline'],
        'loan_type' => ['loantype', 'producttype', 'product', 'schemename', 'scheme', 'facilitytype'],
        'outstanding_amount' => [
            'outstandingamount', 'outstanding', 'principaloutstanding', 'balance',
            'outstandingbalance', 'osamount', 'os', 'totaloutstanding',
        ],
        'overdue_amount' => ['overdueamount', 'overdue', 'odamount', 'arrears', 'overdueamt', 'dueamount'],
        'npa_date' => ['npadate', 'npadt', 'dateofnpa', 'npasince', 'npaclassificationdate'],
        'remarks' => ['remarks', 'remark', 'comments', 'notes', 'observation'],
    ];

    /** Columns without which a row cannot be imported. */
    private const REQUIRED = ['loan_account_number', 'customer_name'];

    /**
     * Runs a full import.
     *
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     *
     * @return array{
     *   import_id:int, total:int, inserted:int, updated:int, skipped:int,
     *   errors:list<array{row:int,account:string,message:string}>,
     *   error_log:string|null, unmatched_branches:list<string>
     * }
     */
    public static function run(
        array $file,
        ?int $defaultBranchId,
        ?int $defaultAgentId,
        int $actorId,
        string $actorName
    ): array {
        $db = Database::instance();

        $storedPath = self::persistUpload($file);

        $parsed = XlsxReader::read($storedPath);
        $headings = $parsed['headings'];
        $rows = $parsed['rows'];

        $map = self::mapHeadings($headings);

        // Fail fast with a precise message rather than importing nonsense.
        $missing = array_diff(self::REQUIRED, array_keys($map));
        if ($missing !== []) {
            throw new \RuntimeException(
                'The file is missing required column(s): ' . implode(', ', array_map(
                    static fn (string $c): string => str_replace('_', ' ', $c),
                    $missing
                )) . '. Found headers: ' . (
                    $headings === [] ? '(none)' : implode(', ', array_slice($headings, 0, 20))
                )
            );
        }

        $importId = $db->insert('lead_imports', [
            'original_name'    => mb_substr((string) ($file['name'] ?? 'upload'), 0, 255),
            'stored_path'      => $storedPath,
            'total_rows'       => count($rows),
            'status'           => 'processing',
            'default_agent_id' => $defaultAgentId,
            'uploaded_by'      => $actorId,
            'branch_id'        => $defaultBranchId,
            'started_at'       => date('Y-m-d H:i:s'),
        ]);

        $branchCache = self::loadBranchCache();
        $agentBranchId = null;
        if ($defaultAgentId !== null) {
            $agent = $db->first('SELECT branch_id FROM users WHERE id = ? LIMIT 1', [$defaultAgentId]);
            $agentBranchId = $agent === null ? null : (int) $agent['branch_id'];
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $unmatchedBranches = [];
        $assignedLeadIds = [];

        try {
            $db->transaction(static function () use (
                $db,
                $rows,
                $map,
                $importId,
                $defaultBranchId,
                $defaultAgentId,
                $agentBranchId,
                $actorId,
                $actorName,
                &$branchCache,
                &$inserted,
                &$updated,
                &$skipped,
                &$errors,
                &$unmatchedBranches,
                &$assignedLeadIds
            ): void {
                foreach ($rows as $index => $row) {
                    // +2: 1-based, plus the header row.
                    $lineNumber = $index + 2;

                    try {
                        $values = self::extract($row, $map);

                        $account = trim($values['loan_account_number']);
                        if ($account === '') {
                            $skipped++;
                            $errors[] = ['row' => $lineNumber, 'account' => '', 'message' => 'Loan account number is blank.'];
                            continue;
                        }

                        if (trim($values['customer_name']) === '') {
                            $skipped++;
                            $errors[] = ['row' => $lineNumber, 'account' => $account, 'message' => 'Customer name is blank.'];
                            continue;
                        }

                        // ---- Branch resolution (auto mapping) --------------
                        $branchId = self::resolveBranch($values['branch'], $branchCache, $defaultBranchId);
                        if ($branchId === null) {
                            $skipped++;
                            $label = trim($values['branch']);
                            if ($label !== '' && !in_array($label, $unmatchedBranches, true)) {
                                $unmatchedBranches[] = $label;
                            }
                            $errors[] = [
                                'row'     => $lineNumber,
                                'account' => $account,
                                'message' => $label === ''
                                    ? 'No branch in the row and no default branch selected.'
                                    : sprintf('Branch "%s" does not exist. Create it or pick a default branch.', $label),
                            ];
                            continue;
                        }

                        // A bulk-assigned agent must belong to the row's branch.
                        $agentForRow = $defaultAgentId;
                        if ($agentForRow !== null && $agentBranchId !== null && $agentBranchId !== $branchId) {
                            $agentForRow = null;
                            $errors[] = [
                                'row'     => $lineNumber,
                                'account' => $account,
                                'message' => 'Imported without assignment: the selected agent belongs to a different branch.',
                            ];
                        }

                        $mobile = self::cleanDigits($values['mobile'], 10, 13);
                        $aadhaar = self::cleanDigits($values['aadhaar'], 12, 12);
                        $npaDate = self::parseDate($values['npa_date']);
                        $outstanding = self::parseAmount($values['outstanding_amount']);
                        $overdue = self::parseAmount($values['overdue_amount']);

                        $existing = $db->first(
                            'SELECT id, customer_id, branch_id, assigned_agent_id, current_status,
                                    outstanding_amount, overdue_amount
                               FROM loan_accounts WHERE loan_account_number = ? LIMIT 1',
                            [$account]
                        );

                        if ($existing !== null) {
                            // ---- UPDATE the existing customer + loan -------
                            $customerId = (int) $existing['customer_id'];

                            $customerData = [
                                'name'                => mb_substr($values['customer_name'], 0, 150),
                                'father_husband_name' => self::nullable($values['father_husband_name'], 150),
                                'village'             => self::nullable($values['village'], 150),
                                'address'             => self::nullable($values['address'], 500),
                                'branch_id'           => $branchId,
                            ];

                            // Only overwrite PII when the file actually supplies it.
                            if ($mobile !== null || $aadhaar !== null) {
                                $pii = Customer::piiColumns($mobile, $aadhaar);
                                if ($mobile === null) {
                                    unset($pii['mobile_enc'], $pii['mobile_hash'], $pii['mobile_masked']);
                                }
                                if ($aadhaar === null) {
                                    unset($pii['aadhaar_enc'], $pii['aadhaar_hash'], $pii['aadhaar_masked']);
                                }
                                $customerData += $pii;
                            }

                            $db->update('customers', $customerData, ['id' => $customerId]);

                            $loanData = [
                                'branch_id'          => $branchId,
                                'bc_code'            => self::nullable($values['bc_code'], 40),
                                'loan_type'          => self::nullable($values['loan_type'], 80),
                                'outstanding_amount' => $outstanding,
                                'overdue_amount'     => $overdue,
                                'npa_date'           => $npaDate,
                                'is_npa'             => $npaDate === null ? 0 : 1,
                                'remarks'            => self::nullable($values['remarks'], 1000),
                                'import_id'          => $importId,
                            ];

                            // Never steal a lead that is already being worked.
                            if ($agentForRow !== null && $existing['assigned_agent_id'] === null) {
                                $loanData['assigned_agent_id'] = $agentForRow;
                                $loanData['assigned_at'] = date('Y-m-d H:i:s');
                                $loanData['assigned_by'] = $actorId;
                                $assignedLeadIds[] = ['id' => (int) $existing['id'], 'agent_id' => $agentForRow, 'account' => $account];
                            }

                            $db->update('loan_accounts', $loanData, ['id' => (int) $existing['id']]);

                            Timeline::record(
                                (int) $existing['id'],
                                'lead_updated',
                                'Lead refreshed from import',
                                sprintf(
                                    'Outstanding %s -> %s, overdue %s -> %s.',
                                    number_format((float) $existing['outstanding_amount'], 2),
                                    number_format($outstanding, 2),
                                    number_format((float) $existing['overdue_amount'], 2),
                                    number_format($overdue, 2)
                                ),
                                $actorId,
                                $actorName,
                                null,
                                null,
                                ['import_id' => $importId, 'row' => $lineNumber]
                            );

                            $updated++;
                            continue;
                        }

                        // ---- INSERT a new customer + loan ------------------
                        $customerId = Customer::create([
                            'branch_id'           => $branchId,
                            'name'                => mb_substr($values['customer_name'], 0, 150),
                            'father_husband_name' => self::nullable($values['father_husband_name'], 150),
                            'village'             => self::nullable($values['village'], 150),
                            'address'             => self::nullable($values['address'], 500),
                        ], $mobile, $aadhaar);

                        $loanId = $db->insert('loan_accounts', [
                            'loan_account_number' => mb_substr($account, 0, 60),
                            'customer_id'         => $customerId,
                            'branch_id'           => $branchId,
                            'bc_code'             => self::nullable($values['bc_code'], 40),
                            'loan_type'           => self::nullable($values['loan_type'], 80),
                            'outstanding_amount'  => $outstanding,
                            'overdue_amount'      => $overdue,
                            'npa_date'            => $npaDate,
                            'is_npa'              => $npaDate === null ? 0 : 1,
                            'current_status'      => 'pending',
                            'assigned_agent_id'   => $agentForRow,
                            'assigned_at'         => $agentForRow === null ? null : date('Y-m-d H:i:s'),
                            'assigned_by'         => $agentForRow === null ? null : $actorId,
                            'remarks'             => self::nullable($values['remarks'], 1000),
                            'import_id'           => $importId,
                        ]);

                        Timeline::record(
                            $loanId,
                            'lead_imported',
                            'Lead imported',
                            sprintf('Imported from file row %d. Outstanding %s.', $lineNumber, number_format($outstanding, 2)),
                            $actorId,
                            $actorName,
                            null,
                            null,
                            ['import_id' => $importId, 'row' => $lineNumber]
                        );

                        if ($agentForRow !== null) {
                            Timeline::record(
                                $loanId,
                                'assigned',
                                'Assigned to agent',
                                'Bulk assignment during import.',
                                $actorId,
                                $actorName,
                                null,
                                null,
                                ['agent_id' => $agentForRow]
                            );
                            $assignedLeadIds[] = ['id' => $loanId, 'agent_id' => $agentForRow, 'account' => $account];
                        }

                        $inserted++;
                    } catch (\Throwable $rowError) {
                        $skipped++;
                        $errors[] = [
                            'row'     => $lineNumber,
                            'account' => (string) ($row[$map['loan_account_number']] ?? ''),
                            'message' => $rowError->getMessage(),
                        ];
                    }
                }
            });
        } catch (\Throwable $e) {
            $db->update('lead_imports', [
                'status'          => 'failed',
                'failure_message' => mb_substr($e->getMessage(), 0, 500),
                'finished_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $importId]);

            throw $e;
        }

        $errorLogPath = $errors === [] ? null : self::writeErrorLog($importId, $errors);

        $db->update('lead_imports', [
            'inserted_count' => $inserted,
            'updated_count'  => $updated,
            'skipped_count'  => $skipped,
            'error_count'    => count($errors),
            'status'         => 'completed',
            'error_log_path' => $errorLogPath,
            'finished_at'    => date('Y-m-d H:i:s'),
        ], ['id' => $importId]);

        // Notify agents about their new workload, one summary per agent.
        self::notifyAssignedAgents($assignedLeadIds, $actorId);

        Logger::audit(
            'import',
            'lead_import',
            $importId,
            null,
            [
                'file'     => (string) ($file['name'] ?? ''),
                'total'    => count($rows),
                'inserted' => $inserted,
                'updated'  => $updated,
                'skipped'  => $skipped,
            ],
            sprintf('Imported %s: %d new, %d updated, %d skipped', (string) ($file['name'] ?? ''), $inserted, $updated, $skipped)
        );

        return [
            'import_id'          => $importId,
            'total'              => count($rows),
            'inserted'           => $inserted,
            'updated'            => $updated,
            'skipped'            => $skipped,
            'errors'             => $errors,
            'error_log'          => $errorLogPath,
            'unmatched_branches' => $unmatchedBranches,
        ];
    }

    /**
     * Dry-run preview: parses the file and reports what WOULD happen, without
     * writing anything. Backs the "validate before import" step.
     *
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     *
     * @return array{
     *   headings:list<string>, mapped:array<string,int>, unmapped:list<string>,
     *   missing_required:list<string>, total:int, new_count:int, update_count:int,
     *   issues:list<array{row:int,account:string,message:string}>,
     *   sample:list<array<string,string>>
     * }
     */
    public static function preview(array $file, ?int $defaultBranchId): array
    {
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Uploaded file could not be read.');
        }

        $parsed = XlsxReader::read($path);
        $headings = $parsed['headings'];
        $rows = $parsed['rows'];
        $map = self::mapHeadings($headings);

        $mappedHeaderIndexes = array_values($map);
        $unmapped = [];
        foreach ($headings as $index => $heading) {
            if (trim($heading) !== '' && !in_array($index, $mappedHeaderIndexes, true)) {
                $unmapped[] = $heading;
            }
        }

        $missingRequired = array_values(array_diff(self::REQUIRED, array_keys($map)));

        $db = Database::instance();
        $branchCache = self::loadBranchCache();

        $newCount = 0;
        $updateCount = 0;
        $issues = [];
        $sample = [];

        if ($missingRequired === []) {
            foreach ($rows as $index => $row) {
                $lineNumber = $index + 2;
                $values = self::extract($row, $map);
                $account = trim($values['loan_account_number']);

                if ($account === '') {
                    $issues[] = ['row' => $lineNumber, 'account' => '', 'message' => 'Loan account number is blank.'];
                    continue;
                }
                if (trim($values['customer_name']) === '') {
                    $issues[] = ['row' => $lineNumber, 'account' => $account, 'message' => 'Customer name is blank.'];
                    continue;
                }

                $branchId = self::resolveBranch($values['branch'], $branchCache, $defaultBranchId);
                if ($branchId === null) {
                    $label = trim($values['branch']);
                    $issues[] = [
                        'row'     => $lineNumber,
                        'account' => $account,
                        'message' => $label === ''
                            ? 'No branch in the row and no default branch selected.'
                            : sprintf('Branch "%s" does not exist.', $label),
                    ];
                    continue;
                }

                $exists = $db->scalar('SELECT 1 FROM loan_accounts WHERE loan_account_number = ? LIMIT 1', [$account]) !== null;
                if ($exists) {
                    $updateCount++;
                } else {
                    $newCount++;
                }

                if (count($sample) < 10) {
                    $sample[] = [
                        'row'         => (string) $lineNumber,
                        'account'     => $account,
                        'name'        => $values['customer_name'],
                        'village'     => $values['village'],
                        'outstanding' => number_format(self::parseAmount($values['outstanding_amount']), 2),
                        'action'      => $exists ? 'Update' : 'New',
                    ];
                }
            }
        }

        return [
            'headings'         => $headings,
            'mapped'           => $map,
            'unmapped'         => $unmapped,
            'missing_required' => $missingRequired,
            'total'            => count($rows),
            'new_count'        => $newCount,
            'update_count'     => $updateCount,
            'issues'           => $issues,
            'sample'           => $sample,
        ];
    }

    // -----------------------------------------------------------------------
    // Header mapping
    // -----------------------------------------------------------------------

    /**
     * Maps canonical field => column index in the sheet.
     *
     * @param list<string> $headings
     * @return array<string,int>
     */
    public static function mapHeadings(array $headings): array
    {
        $normalised = [];
        foreach ($headings as $index => $heading) {
            $normalised[$index] = self::normaliseHeader($heading);
        }

        $map = [];

        // Exact alias match first, so "Loan Account Number" never loses to a
        // fuzzy hit on some other column.
        foreach (self::COLUMN_ALIASES as $field => $aliases) {
            foreach ($normalised as $index => $candidate) {
                if ($candidate !== '' && in_array($candidate, $aliases, true) && !in_array($index, $map, true)) {
                    $map[$field] = $index;
                    continue 2;
                }
            }
        }

        // Then a contains-based pass for the fields still unmapped.
        foreach (self::COLUMN_ALIASES as $field => $aliases) {
            if (isset($map[$field])) {
                continue;
            }
            foreach ($normalised as $index => $candidate) {
                if ($candidate === '' || in_array($index, $map, true)) {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if (strlen($alias) >= 5 && str_contains($candidate, $alias)) {
                        $map[$field] = $index;
                        continue 3;
                    }
                }
            }
        }

        return $map;
    }

    private static function normaliseHeader(string $heading): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $heading) ?? '');
    }

    /**
     * @param list<string>      $row
     * @param array<string,int> $map
     * @return array<string,string>
     */
    private static function extract(array $row, array $map): array
    {
        $values = [];
        foreach (array_keys(self::COLUMN_ALIASES) as $field) {
            $index = $map[$field] ?? null;
            $values[$field] = $index === null ? '' : trim((string) ($row[$index] ?? ''));
        }
        return $values;
    }

    // -----------------------------------------------------------------------
    // Branch resolution
    // -----------------------------------------------------------------------

    /**
     * Branch lookup keyed by normalised code AND normalised name, so files that
     * carry either one map without manual intervention.
     *
     * @return array<string,int>
     */
    private static function loadBranchCache(): array
    {
        $cache = [];
        foreach (Database::instance()->all('SELECT id, branch_code, name FROM branches') as $branch) {
            $id = (int) $branch['id'];
            $code = self::normaliseHeader((string) $branch['branch_code']);
            $name = self::normaliseHeader((string) $branch['name']);
            if ($code !== '') {
                $cache[$code] = $id;
            }
            if ($name !== '' && !isset($cache[$name])) {
                $cache[$name] = $id;
            }
        }
        return $cache;
    }

    /** @param array<string,int> $cache */
    private static function resolveBranch(string $raw, array $cache, ?int $fallback): ?int
    {
        $key = self::normaliseHeader($raw);
        if ($key !== '' && isset($cache[$key])) {
            return $cache[$key];
        }
        return $fallback;
    }

    // -----------------------------------------------------------------------
    // Value coercion
    // -----------------------------------------------------------------------

    private static function nullable(string $value, int $maxLength): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }

    /** Keeps digits only and enforces a plausible length, else returns null. */
    private static function cleanDigits(string $value, int $min, int $max): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        // Trailing ".0" from a numeric Excel cell.
        $digits = rtrim($digits, '0') === '' && strlen($digits) > $max ? $digits : $digits;
        if (strlen($digits) < $min || strlen($digits) > $max) {
            return null;
        }
        return $digits;
    }

    /** Handles "1,25,000.50", "125000", "Rs. 1200", "(500)" and blanks. */
    public static function parseAmount(string $value): float
    {
        $clean = trim($value);
        if ($clean === '' || $clean === '-') {
            return 0.0;
        }

        $negative = str_starts_with($clean, '(') && str_ends_with($clean, ')');
        $clean = preg_replace('/[^0-9.\-]/', '', $clean) ?? '';

        if ($clean === '' || $clean === '-' || $clean === '.') {
            return 0.0;
        }

        $amount = (float) $clean;
        return $negative ? -abs($amount) : $amount;
    }

    /**
     * Accepts Y-m-d, d/m/Y, d-m-Y, d.m.Y, m/d/Y (when unambiguous) and Excel
     * serial numbers. Returns Y-m-d or null.
     */
    public static function parseDate(string $value): ?string
    {
        $raw = trim($value);
        if ($raw === '' || $raw === '-' || strtolower($raw) === 'n/a' || str_starts_with($raw, '0000')) {
            return null;
        }

        // Excel serial number.
        if (ctype_digit($raw) && strlen($raw) <= 5) {
            $converted = XlsxReader::excelSerialToDate((float) $raw);
            if ($converted !== null) {
                return $converted;
            }
        }

        // Already ISO.
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m) === 1) {
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
            }
            return null;
        }

        // d/m/Y, d-m-Y, d.m.Y - day-first, which is the Indian convention.
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})#', $raw, $m) === 1) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            if ($year < 100) {
                $year += $year < 70 ? 2000 : 1900;
            }

            // Fall back to month-first only when day-first is impossible.
            if ($month > 12 && $day <= 12) {
                [$day, $month] = [$month, $day];
            }

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return null;
        }

        // Textual dates such as "31 Mar 2024".
        $timestamp = strtotime($raw);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Storage / logs / notifications
    // -----------------------------------------------------------------------

    /** @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file */
    private static function persistUpload(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                default => 'The upload failed (code ' . $error . ').',
            });
        }

        $maxBytes = (int) Config::get('uploads.max_import_bytes', 25 * 1024 * 1024);
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new \RuntimeException('The file is larger than the ' . round($maxBytes / 1048576) . ' MB import limit.');
        }

        $dir = rtrim((string) Config::get('paths.storage', ROOT_PATH . '/storage'), '/') . '/imports/' . date('Y/m');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create the import storage directory.');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? 'upload.xlsx'), PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            throw new \RuntimeException('Only .xlsx, .xls and .csv files are accepted.');
        }

        $target = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $tmpPath = (string) ($file['tmp_name'] ?? '');

        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $target)
            : copy($tmpPath, $target);

        if ($moved === false) {
            throw new \RuntimeException('Unable to store the uploaded file.');
        }

        return $target;
    }

    /**
     * @param list<array{row:int,account:string,message:string}> $errors
     * @return string|null Absolute path of the CSV error log.
     */
    private static function writeErrorLog(int $importId, array $errors): ?string
    {
        $dir = rtrim((string) Config::get('paths.storage', ROOT_PATH . '/storage'), '/') . '/imports/errors';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $path = $dir . '/import_' . $importId . '_errors.csv';
        $handle = fopen($path, 'w');
        if ($handle === false) {
            return null;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Row', 'Loan Account Number', 'Reason'], ',', '"', '');
        foreach ($errors as $error) {
            fputcsv($handle, [$error['row'], $error['account'], $error['message']], ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    /**
     * @param list<array{id:int,agent_id:int,account:string}> $assigned
     */
    private static function notifyAssignedAgents(array $assigned, int $actorId): void
    {
        if ($assigned === []) {
            return;
        }

        $byAgent = [];
        foreach ($assigned as $entry) {
            $byAgent[$entry['agent_id']][] = $entry;
        }

        foreach ($byAgent as $agentId => $entries) {
            $count = count($entries);
            Notification::send(
                (int) $agentId,
                'new_lead_assigned',
                $count === 1 ? 'New lead assigned' : "{$count} new leads assigned",
                $count === 1
                    ? sprintf('Loan account %s has been assigned to you.', $entries[0]['account'])
                    : sprintf('%d new leads have been assigned to you from a lead import.', $count),
                $count === 1 ? $entries[0]['id'] : null,
                ['count' => $count],
                $actorId
            );
        }
    }
}
