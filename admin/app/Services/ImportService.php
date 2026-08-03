<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ColumnDetector;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\XlsxReader;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomField;
use App\Models\Notification;
use App\Models\Timeline;
use App\Services\AssignmentService;

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
    // The vocabulary and the matching live in App\Core\ColumnDetector, which is
    // also what the reader uses to find the header row and the right worksheet.
    // Keeping one copy means the guide on the import screen, the generated
    // template and the actual mapping can never disagree.

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
        string $actorName,
        array $overrides = [],
        bool $mayCreateBranches = false,
        ?string $storedPath = null,
        // Spread the file across each branch's agents instead of giving all of it to
        // one. The sensible choice for any branch with more than one BC, and the reason
        // it exists: naming a single agent hands them every lead in the file, and the
        // reassignment that would fix it afterwards is work nobody does.
        bool $distribute = false,
    ): array {
        $db = Database::instance();

        // A dry run has already stored the file; re-use it so the mapping the
        // operator confirmed is applied to the upload they looked at.
        if ($storedPath === null || !is_file($storedPath)) {
            $storedPath = self::persistUpload($file);
        }

        $parsed = XlsxReader::read($storedPath);
        $headings = $parsed['headings'];
        $rows = $parsed['rows'];

        // Spreadsheet line number of the first data row. Reporting $index + 2
        // assumed the header was physically row 1, so every row number in the
        // error CSV was wrong by however many title rows had been skipped - and
        // those numbers are what someone uses to find the bad row in Excel.
        $firstDataLine = ($parsed['header_row'] ?? 0) + 2;

        $detection = ColumnDetector::detect($headings, $rows, $overrides);
        $map = $detection['map'];

        // Any column ColumnDetector could not place against the fixed vocabulary
        // becomes a custom field instead of being dropped on the floor. See
        // provisionUnmappedColumns() for why this makes "upload any bank's export,
        // nothing is lost" true rather than aspirational.
        $unmappedDefinitions = self::provisionUnmappedColumns($detection['unmapped'], $actorId);

        // Fail fast with a precise message rather than importing nonsense.
        if ($detection['missing_required'] !== []) {
            throw new \RuntimeException(
                'The file is missing required column(s): ' . implode(', ', array_map(
                    static fn (string $c): string => str_replace('_', ' ', $c),
                    $detection['missing_required']
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

        // Loaded per branch on first use and then carried through the whole file, so the
        // running tally reflects rows this import has already placed. Seeded from what
        // each agent is ALREADY carrying, which is what makes a second import continue
        // the spread instead of restarting it at the same agent.
        /** @var array<int,array<int,array{name:string,open:int}>> $workloadByBranch */
        $workloadByBranch = [];
        $distributedCounts = [];

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $unmatchedBranches = [];
        $createdBranches = [];
        $assignedLeadIds = [];

        // Columns the import declined to overwrite because a human had corrected them.
        // Reported rather than silently skipped: a stale override quietly freezing a
        // figure forever is exactly as bad as the import quietly clobbering it.
        $skippedOverrides = [];

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
                $firstDataLine,
                $mayCreateBranches,
                &$branchCache,
                &$inserted,
                &$updated,
                &$skipped,
                &$errors,
                &$unmatchedBranches,
                &$createdBranches,
                &$assignedLeadIds,
                &$workloadByBranch,
                &$distributedCounts,
                $distribute,
                // By reference, or the appends below land in a local array that dies
                // with the closure and every import reports "nothing skipped" while
                // silently declining to update overridden columns - the worst of both.
                &$skippedOverrides
            ): void {
                foreach ($rows as $index => $row) {
                    $lineNumber = $firstDataLine + $index;

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

                        // ---- Branch comes from the sheet -------------------
                        $branchId = self::resolveBranch(
                            $values['branch'],
                            $branchCache,
                            $defaultBranchId,
                            $mayCreateBranches,
                            $createdBranches,
                        );
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
                                    : sprintf(
                                        'Branch "%s" does not exist and could not be created from this upload. '
                                        . 'Pick a default branch, or ask a super admin to run the import.',
                                        $label
                                    ),
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

                        // Even distribution: whoever in this row's branch is carrying
                        // the least takes it, and the tally moves so the next row goes
                        // to somebody else.
                        if ($distribute) {
                            if (!isset($workloadByBranch[$branchId])) {
                                $workloadByBranch[$branchId] = AssignmentService::agentWorkload($branchId);
                            }

                            $agentForRow = AssignmentService::lightestAgent($workloadByBranch[$branchId]);

                            if ($agentForRow === null) {
                                $errors[] = [
                                    'row'     => $lineNumber,
                                    'account' => $account,
                                    'message' => 'Imported without assignment: this branch has no active BC agent.',
                                ];
                            } else {
                                $workloadByBranch[$branchId][$agentForRow]['open']++;
                                $distributedCounts[$agentForRow] =
                                    ($distributedCounts[$agentForRow] ?? 0) + 1;
                            }
                        }

                        $mobile = self::cleanDigits($values['mobile'], 10, 13);
                        $aadhaar = self::cleanDigits($values['aadhaar'], 12, 12);
                        $npaDate = self::parseDate($values['npa_date']);
                        $outstanding = self::parseAmount($values['outstanding_amount']);
                        $overdue = self::parseAmount($values['overdue_amount']);

                        // Everything else the file may carry.
                        //
                        // Filtered so a blank cell is omitted from the write entirely,
                        // and merged with `+` so it can never overwrite the columns
                        // above. That combination is what makes a partial file safe: a
                        // plain NPA statement with six columns leaves the CKCC and
                        // settlement figures from last month's fuller export alone,
                        // instead of nulling them out.
                        $fileColumns = array_filter([
                            'cif_number'            => self::nullable($values['cif_number'], 40),
                            'sanction_date'         => self::parseDate($values['sanction_date']),
                            'ckcc_renewal_due_date' => self::parseDate($values['ckcc_renewal_due_date']),
                            'sanction_limit'        => self::optionalAmount($values['sanction_limit']),
                            'drawing_power'         => self::optionalAmount($values['drawing_power']),
                            'interest_overdue'      => self::optionalAmount($values['interest_overdue']),
                            // The branch's settlement position. A blank cell stays
                            // NULL rather than becoming "No": not stated and refused
                            // are different answers, and only one of them should
                            // stop an agent offering a settlement.
                            'ots_eligible'          => self::parseBoolean($values['ots_eligible']),
                            'krm_eligible'          => self::parseBoolean($values['krm_eligible']),
                            'ots_amount'            => self::optionalAmount($values['ots_amount']),
                            'deposit_amount'        => self::optionalAmount($values['deposit_amount']),

                            // What it costs to close the account outright. Distinct
                            // from ots_amount, which is a discount the branch agreed
                            // to accept - quoting one for the other at a doorstep is
                            // the mistake this pair of columns exists to prevent.
                            'closure_amount'        => self::optionalAmount($values['closure_amount']),

                            // The rest of a core banking recovery statement.
                            'asset_classification'  => self::parseClassification($values['asset_classification']),
                            'interest_rate'         => self::optionalAmount($values['interest_rate']),
                            'installment_amount'    => self::optionalAmount($values['installment_amount']),
                            'last_payment_date'     => self::parseDate($values['last_payment_date']),
                            'last_payment_amount'   => self::optionalAmount($values['last_payment_amount']),
                            'days_past_due'         => self::optionalInt($values['days_past_due'], 0, 65535),
                            'security_value'        => self::optionalAmount($values['security_value']),
                            'guarantor_name'        => self::nullable($values['guarantor_name'], 150),
                            'maturity_date'         => self::parseDate($values['maturity_date']),
                            'purpose'               => self::nullable($values['purpose'], 150),

                            // Read off the loan type the sheet already carries, so KCC and
                            // OD-2 renewals become two queues instead of one list.
                            'facility_type'         => self::parseFacility(
                                self::nullable($values['loan_type'], 80)
                            ),
                        ], static fn ($value): bool => $value !== null);

                        $existing = $db->first(
                            'SELECT id, customer_id, branch_id, assigned_agent_id, current_status,
                                    outstanding_amount, overdue_amount, manual_overrides
                               FROM loan_accounts WHERE loan_account_number = ? LIMIT 1',
                            [$account]
                        );

                        // Values for the columns nobody recognised, one per unmapped
                        // column, written against whichever loan account this row ends
                        // up as. Done after INSERT/UPDATE below so a brand-new row
                        // already has an id to attach a value to.
                        $unmappedValues = [];
                        foreach ($unmappedDefinitions as $columnIndex => $definition) {
                            $cell = trim((string) ($row[$columnIndex] ?? ''));
                            $unmappedValues[] = [
                                'definition' => $definition,
                                'value'      => $cell === '' ? null : $cell,
                            ];
                        }

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
                            ] + $fileColumns;

                            // Never steal a lead that is already being worked.
                            if ($agentForRow !== null && $existing['assigned_agent_id'] === null) {
                                $loanData['assigned_agent_id'] = $agentForRow;
                                $loanData['assigned_at'] = date('Y-m-d H:i:s');
                                $loanData['assigned_by'] = $actorId;
                                $assignedLeadIds[] = ['id' => (int) $existing['id'], 'agent_id' => $agentForRow, 'account' => $account];
                            }

                            // Columns a human corrected in the panel are left alone.
                            //
                            // Without this the whole point of making loan figures
                            // editable collapses: somebody fixes an outstanding balance,
                            // the next nightly import silently puts the wrong number
                            // back, and nobody finds out until an agent quotes it at a
                            // doorstep. Skipped columns are reported rather than dropped
                            // quietly, so a stale override is visible and can be cleared.
                            $overridden = \App\Models\LoanAccount::overriddenColumns(
                                $existing['manual_overrides'] ?? null
                            );

                            foreach ($overridden as $protectedColumn) {
                                if (array_key_exists($protectedColumn, $loanData)) {
                                    unset($loanData[$protectedColumn]);
                                    $skippedOverrides[$account][] = $protectedColumn;
                                }
                            }

                            $db->update('loan_accounts', $loanData, ['id' => (int) $existing['id']]);

                            self::writeUnmappedValues((int) $existing['id'], $unmappedValues);

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
                        ] + $fileColumns);

                        self::writeUnmappedValues($loanId, $unmappedValues);

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
                'file'             => (string) ($file['name'] ?? ''),
                'sheet'            => (string) ($parsed['sheet'] ?? ''),
                'total'            => count($rows),
                'inserted'         => $inserted,
                'updated'          => $updated,
                'skipped'          => $skipped,
                // Branches created from the sheet are a side effect of the import,
                // so they belong in the audit trail of the import itself.
                'created_branches' => array_column($createdBranches, 'code'),
                'mapping'          => self::describeMapping($headings, $detection),
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
            'created_branches'   => $createdBranches,
            'skipped_overrides'  => $skippedOverrides,
            // Who ended up with what. Reported rather than assumed: "distributed
            // equally" is a claim, and this is the evidence for it.
            'distribution'       => $distributedCounts,
            'sheet'              => (string) ($parsed['sheet'] ?? ''),
            'mapping'            => self::describeMapping($headings, $detection),
            // Labels of every column this run could not match to the fixed vocabulary
            // and turned into a loan_account custom field instead - new ones AND ones
            // reused from an earlier import. Reported so an operator sees where forty
            // stray columns from an unfamiliar export actually went, rather than
            // discovering them one at a time on the Custom Fields screen.
            'unmapped_fields'    => array_map(
                static fn (array $definition): string => (string) $definition['label'],
                $unmappedDefinitions
            ),
        ];
    }

    /**
     * Turns every column ColumnDetector could not place into a `loan_account` custom
     * field, creating the definition on first sight and reusing it on every import
     * after that.
     *
     * WHY loan_account AND NOT customer. An unmapped column sits on the SAME row as
     * every mapped one, one per loan account - a person can hold two accounts with two
     * different answers to whatever that column is asking, and a `customer` field
     * would force them to share one. Reusing an existing `customer` definition of the
     * same key is deliberately not attempted for the same reason.
     *
     * WHY THE KEY IS DERIVED FROM THE HEADING AND REUSED, NOT RECREATED EVERY IMPORT.
     * A field created on day one has to be the SAME field on day sixty, or "what an
     * agent corrected last month" and "what this month's file calls the same column"
     * would silently become two different answers to the same question the moment
     * anybody's spelling of the header drifted by a character. Reuse is keyed on
     * CustomField::keyFrom($heading) - the same normalisation the label went through
     * when the field was first created - so "Crop Insurance No." and "crop insurance
     * no" (same heading, re-typed) land on the same definition, while "Crop Insurance
     * No." and "Insurance No." (genuinely different headings) do not collide into one.
     *
     * @param  array<int,string> $unmapped   column index => heading text
     * @return array<int,array<string,mixed>> column index => the definition row
     */
    private static function provisionUnmappedColumns(array $unmapped, ?int $actorId): array
    {
        $definitions = [];

        foreach ($unmapped as $columnIndex => $heading) {
            $label = trim($heading);
            if ($label === '') {
                continue;
            }

            $key = CustomField::keyFrom($label);
            $definition = CustomField::findByKey('loan_account', $key);

            if ($definition === null) {
                $id = CustomField::create([
                    'entity'         => 'loan_account',
                    'field_key'      => $key,
                    // Kept exactly as the file wrote it, not title-cased or otherwise
                    // reworded - the label is how an agent recognises it as "the same
                    // column the bank's statement has", and a reworded label is a
                    // column that LOOKS like it might be something else.
                    'label'          => mb_substr($label, 0, 120),
                    'field_type'     => 'text',
                    'hint'           => 'Added automatically from a column in an imported file.',
                    'is_required'    => 0,
                    // Off by default, matching every other auto-provisioned field: an
                    // agency importing its own internal tracking sheet should not have
                    // fifteen new fields appear on the printed report the moment the
                    // file lands, before anybody has looked at what they actually are.
                    'show_in_report' => 0,
                    // After every fixed field and every hand-added custom field, so a
                    // spreadsheet with forty stray columns cannot bury the fields
                    // somebody deliberately curated underneath them.
                    'sort_order'     => 1000 + $columnIndex,
                    'status'         => 'active',
                    'created_by'     => $actorId,
                ]);
                $definition = CustomField::find($id);
            }

            if ($definition !== null) {
                $definitions[$columnIndex] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Writes the unmapped-column values collected for one row against the loan
     * account it ended up as - a fresh insert or an existing account being updated.
     *
     * @param list<array{definition:array<string,mixed>,value:?string}> $values
     */
    private static function writeUnmappedValues(int $loanAccountId, array $values): void
    {
        foreach ($values as $entry) {
            CustomField::saveImportValue(
                (int) $entry['definition']['id'],
                'loan_account',
                $loanAccountId,
                (string) $entry['definition']['field_type'],
                $entry['value']
            );
        }
    }

    /**
     * Human-readable record of which column fed which field.
     *
     * Stored on the import and shown in the summary. With detection doing the
     * mapping, "where did this outstanding figure come from?" has to be answerable
     * months later from the import record alone.
     *
     * @param list<string> $headings
     * @param array{map:array<string,int>,confidence:array<string,int>,source:array<string,string>} $detection
     *
     * @return array<string,array{column:string,index:int,confidence:int,source:string}>
     */
    private static function describeMapping(array $headings, array $detection): array
    {
        $described = [];
        foreach ($detection['map'] as $field => $index) {
            $described[$field] = [
                'column'     => trim((string) ($headings[$index] ?? '')),
                'index'      => $index,
                'confidence' => $detection['confidence'][$field] ?? 0,
                'source'     => $detection['source'][$field] ?? 'header',
            ];
        }
        return $described;
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
    public static function preview(array $file, ?int $defaultBranchId, array $overrides = []): array
    {
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Uploaded file could not be read.');
        }

        // Store the upload before reading it, for two reasons.
        //
        // First, correctness: a real HTTP upload lands at /tmp/phpXXXXXX with NO
        // extension, so the reader could not take its CSV branch and fell through
        // to the ZIP magic check - every CSV dry run from the panel failed with
        // "Unsupported file type" while the same file imported fine. persistUpload
        // gives the file its real extension.
        //
        // Second, the mapping screen: the operator confirms the detected columns
        // and then imports. A browser cannot re-populate a file input, so without
        // the file already on disk they would have to choose it a second time and
        // the mapping would be applied to a possibly different upload.
        $storedPath = self::persistUpload($file);
        $parsed = XlsxReader::read($storedPath);

        $headings = $parsed['headings'];
        $rows = $parsed['rows'];
        $firstDataLine = ($parsed['header_row'] ?? 0) + 2;

        $detection = ColumnDetector::detect($headings, $rows, $overrides);
        $map = $detection['map'];
        $unmapped = array_values($detection['unmapped']);
        $missingRequired = $detection['missing_required'];

        $db = Database::instance();
        $branchCache = self::loadBranchCache();

        $newCount = 0;
        $updateCount = 0;
        $issues = [];
        $sample = [];
        $branchesToCreate = [];

        if ($missingRequired === []) {
            foreach ($rows as $index => $row) {
                $lineNumber = $firstDataLine + $index;
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

                // A dry run must not create anything, so branches are resolved
                // read-only and an unknown one is merely listed as "will be
                // created". The row is still counted and still appears in the
                // sample: it is going to import fine, and reporting it as an issue
                // would tell the operator the opposite.
                $branchId = self::resolveBranch($values['branch'], $branchCache, $defaultBranchId);
                if ($branchId === null) {
                    $label = trim($values['branch']);
                    if ($label === '' || !self::plausibleBranchLabel($label)) {
                        $issues[] = [
                            'row'     => $lineNumber,
                            'account' => $account,
                            'message' => 'No branch in the row and no default branch selected.',
                        ];
                        continue;
                    }
                    if (!in_array($label, $branchesToCreate, true)) {
                        $branchesToCreate[] = $label;
                    }
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
            // What the mapping screen needs to show and let the operator correct.
            'detection'        => self::describeMapping($headings, $detection),
            'samples_by_column' => self::previewColumnSamples($headings, $rows),
            'branches_to_create' => $branchesToCreate,
            'sheet'            => (string) ($parsed['sheet'] ?? ''),
            'header_row'       => (int) ($parsed['header_row'] ?? 0),
            // Held so the confirmed mapping can be applied to this exact upload.
            // Kept server-side only: a path from the client would be a traversal
            // waiting to happen.
            'stored_path'      => $storedPath,
        ];
    }

    /**
     * A few example values per column, so the mapping screen can show what is
     * actually in a column rather than only its heading. Seeing "9876543210"
     * under "Column C" is what makes a wrong guess obvious.
     *
     * @param list<string>       $headings
     * @param list<list<string>> $rows
     *
     * @return array<int,list<string>>
     */
    private static function previewColumnSamples(array $headings, array $rows): array
    {
        $samples = [];
        foreach (ColumnDetector::columnSamples($headings, $rows) as $index => $values) {
            $samples[$index] = array_slice(array_values(array_unique($values)), 0, 3);
        }
        return $samples;
    }

    // -----------------------------------------------------------------------
    // Header mapping
    // -----------------------------------------------------------------------

    /**
     * Maps canonical field => column index in the sheet.
     *
     * @param list<string>       $headings
     * @param list<list<string>> $rows      sample rows, so a column with no usable
     *                                      heading can still be identified by shape
     * @param array<string,int>  $overrides field => column index chosen by the user
     *
     * @return array<string,int>
     */
    public static function mapHeadings(array $headings, array $rows = [], array $overrides = []): array
    {
        return ColumnDetector::detect($headings, $rows, $overrides)['map'];
    }

    /**
     * @param list<string>      $row
     * @param array<string,int> $map
     * @return array<string,string>
     */
    private static function extract(array $row, array $map): array
    {
        $values = [];
        foreach (array_keys(ColumnDetector::fields()) as $field) {
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
            $code = ColumnDetector::normalise((string) $branch['branch_code']);
            $name = ColumnDetector::normalise((string) $branch['name']);
            if ($code !== '') {
                $cache[$code] = $id;
            }
            if ($name !== '' && !isset($cache[$name])) {
                $cache[$name] = $id;
            }
        }
        return $cache;
    }

    /**
     * Resolves the branch for a row, creating it from the sheet when it is new.
     *
     * The branch is taken from the file itself. Previously a branch the database
     * had never heard of meant the row was rejected, so importing a new area's
     * accounts meant typing every branch in by hand first and rows were quietly
     * dropped when a name was spelled differently. Now an unknown branch is
     * created and reported, so the import completes and the operator can see
     * exactly what was added.
     *
     * Creation is limited to uploaders who are not tied to one branch: a branch
     * manager importing a file must not be able to conjure branches outside their
     * own scope, so for them an unknown branch still falls back or is skipped.
     *
     * @param array<string,int>                    $cache   normalised label => branch id
     * @param list<array{code:string,name:string}> $created appended to for the summary
     */
    private static function resolveBranch(
        string $raw,
        array &$cache,
        ?int $fallback,
        bool $mayCreate = false,
        array &$created = [],
    ): ?int {
        $label = trim($raw);
        $key = ColumnDetector::normalise($label);

        if ($key !== '' && isset($cache[$key])) {
            return $cache[$key];
        }

        if ($key === '' || !$mayCreate || !self::plausibleBranchLabel($label)) {
            return $fallback;
        }

        $db = Database::instance();
        $code = self::deriveBranchCode($label);
        $name = mb_substr($label, 0, 150);

        // The unique key is on branch_code; a concurrent import could have just
        // created it, so treat a duplicate as "already there" rather than failing
        // the whole file.
        try {
            $id = $db->insert('branches', [
                'branch_code' => $code,
                'name'        => $name,
                'status'      => 'active',
            ]);
        } catch (\Throwable) {
            $existing = $db->first('SELECT id FROM branches WHERE branch_code = ? LIMIT 1', [$code]);
            if ($existing === null) {
                return $fallback;
            }
            $id = (int) $existing['id'];
        }

        $cache[$key] = $id;
        $cache[ColumnDetector::normalise($code)] = $id;
        $created[] = ['code' => $code, 'name' => $name];

        return $id;
    }

    /**
     * Whether a cell is worth creating a branch from.
     *
     * A guard against turning junk into permanent records: a footer like
     * "Total" or "-", or a stray number, must not become a branch.
     */
    private static function plausibleBranchLabel(string $label): bool
    {
        $length = mb_strlen($label);
        if ($length < 2 || $length > 150) {
            return false;
        }
        // Must contain a letter or digit beyond punctuation, and not be a pure
        // total/summary marker.
        if (ColumnDetector::normalise($label) === '') {
            return false;
        }
        $lower = mb_strtolower($label, 'UTF-8');
        return !in_array($lower, ['total', 'grand total', 'sub total', 'subtotal', 'n/a', 'na', 'nil', '-', '--'], true);
    }

    /**
     * A unique branch_code for a branch created from a sheet.
     *
     * If the cell already looks like a code ("BR001", "HO-12") it is used as-is;
     * otherwise one is derived from the name. branch_code is UNIQUE and only 30
     * characters, so collisions get a numeric suffix.
     */
    private static function deriveBranchCode(string $label): string
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $label));

        if ($compact === '') {
            // Non-Latin name, e.g. Devanagari: derive a stable code from a hash so
            // re-importing the same file does not create a second branch.
            $compact = 'BR' . strtoupper(substr(md5(ColumnDetector::normalise($label)), 0, 8));
        }

        $base = mb_substr($compact, 0, 26);
        $db = Database::instance();
        $candidate = $base;
        $suffix = 1;

        while ($db->first('SELECT id FROM branches WHERE branch_code = ? LIMIT 1', [$candidate]) !== null) {
            $candidate = mb_substr($base, 0, 26) . '-' . $suffix;
            $suffix++;
            if ($suffix > 999) {
                return mb_substr($base, 0, 22) . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
            }
        }

        return $candidate;
    }

    // -----------------------------------------------------------------------
    // Value coercion
    // -----------------------------------------------------------------------

    private static function nullable(string $value, int $maxLength): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }

    /**
     * Reads a Yes/No cell as 1, 0 or null.
     *
     * Returns null for anything it does not recognise, blanks included, because
     * the alternative - defaulting to 0 - would silently record that the branch
     * had refused a settlement it simply had not commented on.
     */
    private static function parseBoolean(string $raw): ?int
    {
        $value = mb_strtolower(trim($raw), 'UTF-8');
        if ($value === '') {
            return null;
        }

        // Tick marks appear in files exported from forms with checkbox columns.
        if (in_array($value, [
            'yes', 'y', '1', 'true', 't', 'eligible', 'ok', 'approved', 'applicable',
            'haan', 'ha', 'हाँ', 'हां', 'पात्र', 'yes ', '✓', '✔', 'x',
        ], true)) {
            return 1;
        }

        if (in_array($value, [
            'no', 'n', '0', 'false', 'f', 'not eligible', 'ineligible', 'na', 'n/a', '-',
            'nahi', 'nahin', 'नहीं', 'अपात्र', 'not applicable',
        ], true)) {
            return 0;
        }

        return null;
    }

    /**
     * An amount, or null when the cell was blank.
     *
     * Distinct from parseAmount(), which returns 0.00 for a blank. That distinction is
     * the whole point: 0.00 in `overdue_amount` says the account is current, while NULL
     * in `security_value` says the file did not tell us what collateral is held. Writing
     * zero for the second would put "no security" on a file that never claimed it.
     */
    private static function optionalAmount(string $raw): ?float
    {
        return trim($raw) === '' ? null : self::parseAmount($raw);
    }

    /**
     * A whole number within bounds, or null.
     *
     * Clamped rather than rejected: a bank's DPD column occasionally carries something
     * absurd from a bad join, and the choice is between storing a nonsense number,
     * dropping the row, or pinning it to the column's limit. Pinning keeps the rest of
     * a genuine row while making no claim it can't support - and the column is
     * SMALLINT UNSIGNED, so an unclamped 90000 would be silently truncated by MySQL
     * anyway, which is the same lie told less visibly.
     */
    private static function optionalInt(string $raw, int $min, int $max): ?int
    {
        $digits = preg_replace('/[^\d-]/', '', trim($raw)) ?? '';
        if ($digits === '' || $digits === '-') {
            return null;
        }

        return max($min, min($max, (int) $digits));
    }

    /**
     * Which renewable facility a loan type describes, or null when it says neither.
     *
     * Read off `loan_type` rather than asking for another column, because the bank's own
     * product / scheme / facility heading already lands there and already says "KCC" or
     * "OD-2". Adding a second column for the same fact would mean two answers that can
     * disagree.
     *
     * Deliberately conservative. "KCC", "Kisan Credit Card", "CKCC" and "OD-2" are
     * unambiguous; a bare "OD" or "Overdraft" is NOT, because a plain overdraft is not the
     * OD-2 facility this queue is about, and a wrong guess would put an account into a
     * worklist somebody works through by hand. Anything unrecognised returns null - "not
     * determined" is a state the panel can show and a person can correct, which is more
     * use than a confident mistake.
     */
    private static function parseFacility(?string $loanType): ?string
    {
        if ($loanType === null || trim($loanType) === '') {
            return null;
        }

        // Collapse to letters and digits so "OD-2", "OD 2" and "od2" are one key, and
        // pad with spaces so word boundaries can be tested on the original text too.
        $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $loanType));

        // OD-2 first: "kcc od2" should be read as the OD-2 facility, since an account
        // only lands in that queue once it has been converted.
        foreach (['od2', 'odii', 'od02', 'overdraft2', 'overdraftii'] as $needle) {
            if (str_contains($key, $needle)) {
                return 'od2';
            }
        }

        foreach (['kcc', 'ckcc', 'kisancreditcard', 'kisancard'] as $needle) {
            if (str_contains($key, $needle)) {
                return 'kcc';
            }
        }

        return null;
    }

    /**
     * The asset classification, normalised where it is recognisable.
     *
     * Banks write this column twenty different ways: "SS", "S/S", "Sub-Standard",
     * "DOUBTFUL-II", "D2", "D-2", "LOSS ASSET", "SMA 2". It is the single most useful
     * prioritisation field in an NPA statement, so the one thing this must not do is
     * throw a spelling away.
     *
     * Recognised spellings collapse to a canonical label so a branch can group by it.
     * Anything else is stored exactly as the file wrote it - a value nobody anticipated
     * is still worth more than a NULL, and it shows up in the panel where somebody can
     * see what the bank is actually sending.
     */
    private static function parseClassification(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        // Collapse to letters and digits so "D-2", "D 2" and "d2" are one key.
        $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));

        $canonical = [
            'standard'      => 'Standard',
            'std'           => 'Standard',
            'stdasset'      => 'Standard',
            'standardasset' => 'Standard',
            'regular'       => 'Standard',
            'performing'    => 'Standard',

            'sma'           => 'SMA-0',
            'sma0'          => 'SMA-0',
            'sma1'          => 'SMA-1',
            'sma2'          => 'SMA-2',

            'ss'            => 'Sub-Standard',
            'substandard'   => 'Sub-Standard',
            'substd'        => 'Sub-Standard',
            'subs'          => 'Sub-Standard',

            'd1'            => 'Doubtful-1',
            'doubtful1'     => 'Doubtful-1',
            'doubtfuli'     => 'Doubtful-1',
            'df1'           => 'Doubtful-1',
            'd2'            => 'Doubtful-2',
            'doubtful2'     => 'Doubtful-2',
            'doubtfulii'    => 'Doubtful-2',
            'df2'           => 'Doubtful-2',
            'd3'            => 'Doubtful-3',
            'doubtful3'     => 'Doubtful-3',
            'doubtfuliii'   => 'Doubtful-3',
            'df3'           => 'Doubtful-3',
            'doubtful'      => 'Doubtful',

            'loss'          => 'Loss',
            'lossasset'     => 'Loss',
            'l'             => 'Loss',
        ][$key] ?? null;

        return $canonical ?? mb_substr($value, 0, 40);
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
