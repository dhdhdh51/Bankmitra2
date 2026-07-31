<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Uploader;
use App\Models\LoanAccount;
use App\Models\Notification;
use App\Models\Promise;
use App\Models\Timeline;
use App\Models\VisitReport;

/**
 * Submits a Digital BC Field Visit Report.
 *
 * INVARIANT: submission is append-only. This service never updates or deletes an
 * existing visit_reports row. Each call inserts:
 *   1. a new visit_reports row (with a borrower/loan snapshot)
 *   2. a `visit` timeline event
 *   3. optionally a promises row + `promise_created` timeline event
 *   4. photo / document / signature rows
 * and then refreshes the derived counters on loan_accounts.
 *
 * All of it happens in one transaction so a half-written visit is impossible.
 */
final class VisitService
{
    /** Photo field name => photo_type enum value. */
    public const PHOTO_FIELDS = [
        'customer_photo'     => 'customer',
        'house_photo'        => 'house',
        'aadhaar_photo'      => 'aadhaar',
        // Evidence the CKCC renewal report asks for.
        'land_photo'         => 'land',
        'passbook_photo'     => 'passbook',
        'renewal_form_photo' => 'renewal_form',
    ];

    /** The three kinds of field report an agent can file. */
    public const REPORT_TYPES = ['recovery', 'ots', 'ckcc_renewal'];

    /**
     * @param array<string,mixed> $input Validated form/API payload.
     * @param array{id:int,name:string,bc_code?:string|null,branch_id?:int|null} $agent
     *
     * @return array{visit_id:int, promise_id:int|null, duplicate:bool, media:array<string,int>, warnings:list<string>}
     */
    public static function submit(array $input, array $agent, string $source = 'android'): array
    {
        $db = Database::instance();

        $loanAccountId = (int) ($input['loan_account_id'] ?? 0);
        $lead = LoanAccount::find($loanAccountId);

        if ($lead === null) {
            throw new \RuntimeException('The loan account could not be found.');
        }

        // Idempotency: a retried submit (flaky mobile network) must not create a
        // second visit for the same client-generated UUID.
        $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
        if ($clientUuid !== '') {
            $existing = VisitReport::findByClientUuid($clientUuid);
            if ($existing !== null) {
                return [
                    'visit_id'   => (int) $existing['id'],
                    'promise_id' => null,
                    'duplicate'  => true,
                    'media'      => ['photos' => 0, 'documents' => 0, 'signatures' => 0],
                    'warnings'   => ['This visit was already submitted.'],
                ];
            }
        }

        $customer = $db->first(
            'SELECT id, name, father_husband_name, address, village,
                    mobile_enc, mobile_hash, mobile_masked,
                    aadhaar_enc, aadhaar_hash, aadhaar_masked
               FROM customers WHERE id = ? LIMIT 1',
            [(int) $lead['customer_id']]
        );
        if ($customer === null) {
            throw new \RuntimeException('The borrower record could not be found.');
        }

        $visitDate = (string) ($input['visit_date'] ?? date('Y-m-d'));
        $visitTime = (string) ($input['visit_time'] ?? date('H:i:s'));
        if (strlen($visitTime) === 5) {
            $visitTime .= ':00';
        }

        $promiseAmount = self::nullableAmount($input['promise_amount'] ?? null);
        $promiseDate = self::nullableDate($input['promise_date'] ?? null);

        $warnings = [];
        $visitId = 0;
        $promiseId = null;
        $mediaCounts = ['photos' => 0, 'documents' => 0, 'signatures' => 0];

        $db->transaction(static function () use (
            $db,
            $input,
            $agent,
            $lead,
            $customer,
            $loanAccountId,
            $visitDate,
            $visitTime,
            $promiseAmount,
            $promiseDate,
            $clientUuid,
            $source,
            &$visitId,
            &$promiseId,
            &$mediaCounts,
            &$warnings
        ): void {
            // ---- 1. the visit report ------------------------------------
            $visitId = VisitReport::insert([
                'loan_account_id' => $loanAccountId,
                'customer_id'     => (int) $customer['id'],
                'agent_id'        => (int) $agent['id'],
                'branch_id'       => (int) $lead['branch_id'],

                'visit_date'  => $visitDate,
                'visit_time'  => $visitTime,
                'bc_code'     => $agent['bc_code'] ?? $lead['bc_code'] ?? null,
                'agent_name'  => (string) $agent['name'],
                'branch_name' => (string) ($lead['branch_name'] ?? ''),
                'village'     => self::str($input['village'] ?? $customer['village'], 150),
                'report_type' => self::reportType($input['report_type'] ?? null),

                // Declaration block, printed at the foot of the report.
                'sp_cbc_name'     => self::str($input['sp_cbc_name'] ?? null, 150),
                'supervisor_name' => self::str($input['supervisor_name'] ?? null, 150),
                'supervisor_verified_at' => self::nullableDate($input['supervisor_verified_at'] ?? null),

                // Borrower snapshot - copied so the signed report never changes
                // even if the customer master is later corrected.
                'customer_name'       => (string) $customer['name'],
                'father_husband_name' => $customer['father_husband_name'],
                'address'             => self::str($input['address'] ?? $customer['address'], 500),
                'mobile_enc'          => $customer['mobile_enc'],
                'mobile_hash'         => $customer['mobile_hash'],
                'mobile_masked'       => $customer['mobile_masked'],
                'aadhaar_enc'         => $customer['aadhaar_enc'],
                'aadhaar_hash'        => $customer['aadhaar_hash'],
                'aadhaar_masked'      => $customer['aadhaar_masked'],

                // Loan snapshot
                'loan_account_number' => (string) $lead['loan_account_number'],
                'loan_type'           => $lead['loan_type'],
                'outstanding_amount'  => (float) $lead['outstanding_amount'],
                'overdue_amount'      => (float) $lead['overdue_amount'],
                'npa_date'            => $lead['npa_date'],
                'current_status'      => (string) $lead['current_status'],

                // Customer contact
                'customer_met'               => self::flag($input['customer_met'] ?? null),
                'family_member_met'          => self::flag($input['family_member_met'] ?? null),
                'house_locked'               => self::flag($input['house_locked'] ?? null),
                'phone_contact'              => self::flag($input['phone_contact'] ?? null),
                'phone_switched_off'         => self::flag($input['phone_switched_off'] ?? null),
                'family_member_name'         => self::str($input['family_member_name'] ?? null, 150),
                'family_member_relationship' => self::str($input['family_member_relationship'] ?? null, 80),

                // Physical verification
                'borrower_alive'        => self::flag($input['borrower_alive'] ?? 1),
                'same_address'          => self::flag($input['same_address'] ?? 1),
                'shifted'               => self::flag($input['shifted'] ?? null),
                'occupation'            => self::occupation($input['occupation'] ?? null),
                'occupation_other_text' => self::str($input['occupation_other_text'] ?? null, 150),

                // Recovery possibility
                'ready_to_pay'     => self::flag($input['ready_to_pay'] ?? null),
                'not_ready'        => self::flag($input['not_ready'] ?? null),
                'interest_payment' => self::flag($input['interest_payment'] ?? null),
                'ots'              => self::flag($input['ots'] ?? null),
                'promise_amount'   => $promiseAmount,
                'promise_date'     => $promiseDate,

                // Non-payment reasons
                'reason_financial_problem' => self::flag($input['reason_financial_problem'] ?? null),
                'reason_crop_loss'         => self::flag($input['reason_crop_loss'] ?? null),
                'reason_animal_loss'       => self::flag($input['reason_animal_loss'] ?? null),
                'reason_illness'           => self::flag($input['reason_illness'] ?? null),
                'reason_unemployment'      => self::flag($input['reason_unemployment'] ?? null),
                'reason_dispute'           => self::flag($input['reason_dispute'] ?? null),
                'reason_other_loan'        => self::flag($input['reason_other_loan'] ?? null),
                'reason_others'            => self::flag($input['reason_others'] ?? null),
                'reason_other_text'        => self::str($input['reason_other_text'] ?? null, 255),

                // Agent recommendation
                'rec_recovery_possible' => self::flag($input['rec_recovery_possible'] ?? null),
                'rec_regular_followup'  => self::flag($input['rec_regular_followup'] ?? null),
                'rec_legal_action'      => self::flag($input['rec_legal_action'] ?? null),
                'rec_rc'                => self::flag($input['rec_rc'] ?? null),
                'rec_ots'               => self::flag($input['rec_ots'] ?? null),
                'rec_others'            => self::flag($input['rec_others'] ?? null),
                'rec_other_text'        => self::str($input['rec_other_text'] ?? null, 255),

                'remarks'     => self::text($input['remarks'] ?? null),
                'source'      => $source === 'web' ? 'web' : 'android',
                'app_version' => self::str($input['app_version'] ?? null, 30),
                'device_info' => self::str($input['device_info'] ?? null, 255),
                'client_uuid' => $clientUuid === '' ? null : $clientUuid,
            ]);

            // ---- 1b. report-type detail sections -------------------------
            self::insertOtsDetails($visitId, $loanAccountId, $lead, $input);
            self::insertCkccDetails($visitId, $loanAccountId, $lead, $input);

            // ---- 2. timeline event --------------------------------------
            Timeline::record(
                $loanAccountId,
                'visit',
                sprintf('Field visit #%d', (int) $lead['visit_count'] + 1),
                self::visitSummary($input, $promiseAmount, $promiseDate),
                (int) $agent['id'],
                (string) $agent['name'],
                $visitId,
                null,
                ['visit_date' => $visitDate, 'visit_time' => $visitTime]
            );

            // ---- 3. promise ---------------------------------------------
            if ($promiseAmount !== null && $promiseAmount > 0 && $promiseDate !== null) {
                $promiseId = Promise::create([
                    'loan_account_id' => $loanAccountId,
                    'customer_id'     => (int) $customer['id'],
                    'visit_report_id' => $visitId,
                    'agent_id'        => (int) $agent['id'],
                    'branch_id'       => (int) $lead['branch_id'],
                    'promise_amount'  => $promiseAmount,
                    'promise_date'    => $promiseDate,
                    'status'          => 'pending',
                    'notes'           => self::str($input['remarks'] ?? null, 500),
                ]);

                Timeline::record(
                    $loanAccountId,
                    'promise_created',
                    'Promise to pay recorded',
                    sprintf('%s promised by %s.', number_format($promiseAmount, 2), $promiseDate),
                    (int) $agent['id'],
                    (string) $agent['name'],
                    $visitId,
                    $promiseId,
                    ['amount' => $promiseAmount, 'date' => $promiseDate]
                );
            } elseif ($promiseAmount !== null && $promiseAmount > 0 && $promiseDate === null) {
                $warnings[] = 'A promise amount was entered without a promise date, so no promise case was created.';
            }

            // ---- 4. media -----------------------------------------------
            $mediaCounts = self::attachMedia($visitId, $loanAccountId, (int) $agent['id'], $input, $warnings);

            // ---- 5. derived state on the lead ---------------------------
            $newStatus = self::deriveStatus((string) $lead['current_status'], $input, $promiseId !== null);

            $update = [
                'current_status' => $newStatus,
                'visit_count'    => (int) $lead['visit_count'] + 1,
                'last_visit_at'  => date('Y-m-d H:i:s'),
            ];

            if ($promiseId !== null) {
                $update['last_promise_id'] = $promiseId;
                $update['next_followup_date'] = $promiseDate;
            } elseif (self::flag($input['rec_regular_followup'] ?? null) === 1) {
                $days = max(1, (int) (\App\Core\Settings::get('followup_reminder_days', '7')));
                $update['next_followup_date'] = date('Y-m-d', strtotime("+{$days} days"));
            }

            $db->update('loan_accounts', $update, ['id' => $loanAccountId]);

            if ($newStatus !== (string) $lead['current_status']) {
                Timeline::record(
                    $loanAccountId,
                    'status_changed',
                    'Status updated after visit',
                    sprintf('%s -> %s', (string) $lead['current_status'], $newStatus),
                    (int) $agent['id'],
                    (string) $agent['name'],
                    $visitId
                );
            }
        });

        // Counters are recomputed from the source of truth outside the write
        // path, so they self-heal even if something was ever inserted directly.
        LoanAccount::refreshVisitCounters($loanAccountId);

        Logger::audit(
            'create',
            'visit_report',
            $visitId,
            null,
            [
                'loan_account_number' => (string) $lead['loan_account_number'],
                'visit_date'          => $visitDate,
                'promise_amount'      => $promiseAmount,
                'promise_date'        => $promiseDate,
            ],
            sprintf('Visit report filed for %s', (string) $lead['loan_account_number'])
        );

        // Let the branch manager know when a promise is made.
        if ($promiseId !== null) {
            self::notifyManagers(
                (int) $lead['branch_id'],
                'Promise recorded',
                sprintf(
                    '%s promised %s by %s for account %s.',
                    (string) $customer['name'],
                    number_format((float) $promiseAmount, 2),
                    (string) $promiseDate,
                    (string) $lead['loan_account_number']
                ),
                $loanAccountId,
                (int) $agent['id']
            );
        }

        return [
            'visit_id'   => $visitId,
            'promise_id' => $promiseId,
            'duplicate'  => false,
            'media'      => $mediaCounts,
            'warnings'   => $warnings,
        ];
    }

    // -----------------------------------------------------------------------
    // Media attachment
    // -----------------------------------------------------------------------

    /**
     * Handles both multipart uploads and base64 payloads (the Android app uses
     * base64 for signatures and may use either for photos).
     *
     * @param array<string,mixed> $input
     * @param list<string>        $warnings
     * @return array<string,int>
     */
    private static function attachMedia(int $visitId, int $loanAccountId, int $userId, array $input, array &$warnings): array
    {
        $counts = ['photos' => 0, 'documents' => 0, 'signatures' => 0];

        $imageMime = (array) Config::get('uploads.allowed_image_mime', ['image/jpeg', 'image/png', 'image/webp']);
        $docMime = (array) Config::get('uploads.allowed_doc_mime', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
        $maxPhoto = (int) Config::get('uploads.max_photo_bytes', 8 * 1024 * 1024);
        $maxDoc = (int) Config::get('uploads.max_document_bytes', 12 * 1024 * 1024);

        // ---- typed photos ------------------------------------------------
        foreach (self::PHOTO_FIELDS as $field => $photoType) {
            try {
                if (Uploader::hasUpload($field)) {
                    foreach (Uploader::normalizeMultiple($field) as $file) {
                        $stored = Uploader::store($file, 'photos', $imageMime, $maxPhoto);
                        self::insertPhoto($visitId, $loanAccountId, $photoType, $stored, $userId);
                        $counts['photos']++;
                    }
                    continue;
                }

                $base64 = trim((string) ($input[$field . '_base64'] ?? ''));
                if ($base64 !== '') {
                    $stored = Uploader::storeBase64($base64, 'photos', $maxPhoto);
                    self::insertPhoto($visitId, $loanAccountId, $photoType, $stored, $userId);
                    $counts['photos']++;
                }
            } catch (\Throwable $e) {
                // A failed photo must not lose the whole visit report.
                $warnings[] = sprintf('%s could not be saved: %s', str_replace('_', ' ', $field), $e->getMessage());
            }
        }

        // ---- other documents ---------------------------------------------
        try {
            foreach (Uploader::normalizeMultiple('other_documents') as $file) {
                $stored = Uploader::store($file, 'documents', $docMime, $maxDoc);
                Database::instance()->insert('documents', [
                    'visit_report_id' => $visitId,
                    'loan_account_id' => $loanAccountId,
                    'doc_type'        => 'other',
                    'title'           => $stored['original_name'],
                    'file_path'       => $stored['path'],
                    'original_name'   => $stored['original_name'],
                    'mime_type'       => $stored['mime'],
                    'file_size'       => $stored['size'],
                    'uploaded_by'     => $userId,
                ]);
                $counts['documents']++;
            }
        } catch (\Throwable $e) {
            $warnings[] = 'A document could not be saved: ' . $e->getMessage();
        }

        // ---- signatures (one customer + one agent per visit) --------------
        foreach (['customer', 'agent'] as $type) {
            try {
                $field = $type . '_signature';
                $stored = null;

                if (Uploader::hasUpload($field)) {
                    $files = Uploader::normalizeMultiple($field);
                    if ($files !== []) {
                        $stored = Uploader::store($files[0], 'signatures', ['image/png', 'image/jpeg'], $maxPhoto);
                    }
                } else {
                    $base64 = trim((string) ($input[$field . '_base64'] ?? ''));
                    if ($base64 !== '') {
                        $stored = Uploader::storeBase64($base64, 'signatures', $maxPhoto);
                    }
                }

                if ($stored !== null) {
                    Database::instance()->insert('signatures', [
                        'visit_report_id' => $visitId,
                        'loan_account_id' => $loanAccountId,
                        'signature_type'  => $type,
                        'file_path'       => $stored['path'],
                        'signed_name'     => self::str($input[$type . '_signature_name'] ?? null, 150),
                        'file_size'       => $stored['size'],
                        'captured_at'     => date('Y-m-d H:i:s'),
                        'uploaded_by'     => $userId,
                    ]);
                    $counts['signatures']++;
                }
            } catch (\Throwable $e) {
                $warnings[] = sprintf('The %s signature could not be saved: %s', $type, $e->getMessage());
            }
        }

        return $counts;
    }

    /** @param array{path:string,original_name:string,mime:string,size:int,width:int|null,height:int|null} $stored */
    private static function insertPhoto(int $visitId, int $loanAccountId, string $photoType, array $stored, int $userId): void
    {
        Database::instance()->insert('photos', [
            'visit_report_id' => $visitId,
            'loan_account_id' => $loanAccountId,
            'photo_type'      => $photoType,
            'file_path'       => $stored['path'],
            'original_name'   => $stored['original_name'],
            'mime_type'       => $stored['mime'],
            'file_size'       => $stored['size'],
            'width'           => $stored['width'],
            'height'          => $stored['height'],
            'uploaded_by'     => $userId,
        ]);
    }

    // -----------------------------------------------------------------------
    // Derived values
    // -----------------------------------------------------------------------

    /**
     * Maps the report's recommendation flags onto the lead's status.
     * A closed lead is never silently reopened by a visit.
     *
     * @param array<string,mixed> $input
     */
    private static function deriveStatus(string $currentStatus, array $input, bool $hasPromise): string
    {
        if ($currentStatus === 'closed') {
            return 'closed';
        }
        if ($hasPromise) {
            return 'promise';
        }
        if (self::flag($input['rec_legal_action'] ?? null) === 1 || self::flag($input['rec_rc'] ?? null) === 1) {
            return 'legal';
        }
        if (self::flag($input['rec_regular_followup'] ?? null) === 1) {
            return 'followup';
        }
        return 'visited';
    }

    /** @param array<string,mixed> $input */
    private static function visitSummary(array $input, ?float $promiseAmount, ?string $promiseDate): string
    {
        $parts = [];

        if (self::flag($input['customer_met'] ?? null) === 1) {
            $parts[] = 'Customer met';
        } elseif (self::flag($input['family_member_met'] ?? null) === 1) {
            $name = self::str($input['family_member_name'] ?? null, 150);
            $parts[] = 'Family member met' . ($name !== null ? ' (' . $name . ')' : '');
        } elseif (self::flag($input['house_locked'] ?? null) === 1) {
            $parts[] = 'House locked';
        } elseif (self::flag($input['phone_contact'] ?? null) === 1) {
            $parts[] = 'Contacted by phone';
        } elseif (self::flag($input['phone_switched_off'] ?? null) === 1) {
            $parts[] = 'Phone switched off';
        }

        if (self::flag($input['ready_to_pay'] ?? null) === 1) {
            $parts[] = 'Ready to pay';
        }
        if (self::flag($input['not_ready'] ?? null) === 1) {
            $parts[] = 'Not ready to pay';
        }
        if (self::flag($input['ots'] ?? null) === 1) {
            $parts[] = 'OTS discussed';
        }
        if ($promiseAmount !== null && $promiseDate !== null) {
            $parts[] = sprintf('Promised %s by %s', number_format($promiseAmount, 2), $promiseDate);
        }

        $remarks = self::text($input['remarks'] ?? null);
        if ($remarks !== null && $remarks !== '') {
            $parts[] = mb_substr($remarks, 0, 300);
        }

        return $parts === [] ? 'Visit recorded.' : implode(' · ', $parts);
    }

    private static function notifyManagers(int $branchId, string $title, string $body, int $loanAccountId, int $actorId): void
    {
        $managers = Database::instance()->all(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.status = 'active' AND r.slug = 'branch_manager' AND u.branch_id = ?",
            [$branchId]
        );

        foreach ($managers as $manager) {
            Notification::send(
                (int) $manager['id'],
                'promise_reminder',
                $title,
                $body,
                $loanAccountId,
                [],
                $actorId,
                $branchId
            );
        }
    }

    // -----------------------------------------------------------------------
    // Coercion helpers
    // -----------------------------------------------------------------------

    /**
     * KRM / OTS settlement section.
     *
     * Written only when the agent actually filled it in, so a plain recovery
     * visit leaves no empty OTS row behind to confuse a report later.
     *
     * Every money figure is stored exactly as the agent entered it. The app
     * suggests `payable = rlb x payable_percent`, but nothing here recalculates
     * or "corrects" a submitted number: the branch's sanction letter is the
     * authority, and silently rewriting a settlement figure would be far worse
     * than storing an odd one.
     *
     * @param array<string,mixed> $lead
     * @param array<string,mixed> $input
     */
    private static function insertOtsDetails(int $visitId, int $loanAccountId, array $lead, array $input): void
    {
        $section = self::section($input, 'ots_details');
        if ($section === null) {
            return;
        }

        Database::instance()->insert('visit_ots_details', [
            'visit_report_id' => $visitId,
            'loan_account_id' => $loanAccountId,

            'eligible_for_ots' => self::flag($section['eligible_for_ots'] ?? null),
            'scheme'           => self::enum($section['scheme'] ?? null, ['krm_ots', 'general_ots']),

            // Bank data, taken from the account rather than from the form. The
            // agent cannot mistype the classification date of the very account the
            // settlement is being offered against.
            'npa_date'      => $lead['npa_date'],
            'borrower_name' => self::str($lead['customer_name'] ?? null, 150),

            'outstanding_amount'      => self::nullableAmount($section['outstanding_amount'] ?? $lead['outstanding_amount']),
            'relief_waiver_percent'   => self::percent($section['relief_waiver_percent'] ?? null),
            // Defaults to the outstanding balance when the branch has not given a
            // separate figure - which is how the worked example runs: payable is
            // 22.50% of the outstanding amount.
            'rlb_amount'              => self::nullableAmount($section['rlb_amount'] ?? $lead['outstanding_amount']),
            'payable_percent'         => self::percent($section['payable_percent'] ?? null) ?? 22.50,
            'borrower_payable_amount' => self::nullableAmount($section['borrower_payable_amount'] ?? null),
            'total_settlement_amount' => self::nullableAmount($section['total_settlement_amount'] ?? null),

            'initial_deposit_percent' => self::percent($section['initial_deposit_percent'] ?? null) ?? 10.00,
            'required_deposit_amount' => self::nullableAmount($section['required_deposit_amount'] ?? null),
            // Recorded, not collected: the borrower pays the bank and the agent
            // copies down the bank's receipt number as evidence.
            'deposit_received'        => self::flag($section['deposit_received'] ?? null),
            'deposit_amount'          => self::nullableAmount($section['deposit_amount'] ?? null),
            'deposit_date'            => self::nullableDate($section['deposit_date'] ?? null),
            'deposit_reference'       => self::str($section['deposit_reference'] ?? null, 120),
            'balance_payable'         => self::nullableAmount($section['balance_payable'] ?? null),
            'proposed_final_payment_date' => self::nullableDate($section['proposed_final_payment_date'] ?? null),

            'approval_status'       => self::enum($section['approval_status'] ?? null, ['pending', 'approved', 'rejected']) ?? 'pending',
            'validity_from'         => self::nullableDate($section['validity_from'] ?? null),
            'validity_to'           => self::nullableDate($section['validity_to'] ?? null),
            'expected_closure_date' => self::nullableDate($section['expected_closure_date'] ?? null),

            'borrower_accepted' => self::flag($section['borrower_accepted'] ?? null),
            'rejection_reason'  => self::str($section['rejection_reason'] ?? null, 500),
        ]);
    }

    /**
     * CKCC OD-2 renewal section.
     *
     * `expected_npa_date` and `days_remaining` are derived here rather than
     * trusted from the device: a phone with a wrong clock would otherwise write a
     * misleading deadline into a report that a branch acts on. They are stored,
     * not computed on read, so the report still reads the way it did on the day
     * of the visit.
     *
     * @param array<string,mixed> $lead
     * @param array<string,mixed> $input
     */
    private static function insertCkccDetails(int $visitId, int $loanAccountId, array $lead, array $input): void
    {
        $section = self::section($input, 'ckcc_details');
        if ($section === null) {
            return;
        }

        $dueDate = self::nullableDate($section['renewal_due_date'] ?? $lead['ckcc_renewal_due_date'] ?? null);

        $daysRemaining = null;
        $expectedNpa = null;
        $bucket = self::enum(
            $section['renewal_due_bucket'] ?? null,
            ['within_30', 'within_15', 'within_7', 'overdue']
        );

        if ($dueDate !== null) {
            $today = new \DateTimeImmutable(date('Y-m-d'));
            $due = new \DateTimeImmutable($dueDate);
            $daysRemaining = (int) $today->diff($due)->format('%r%a');

            // An unrenewed CKCC OD account is classified NPA once the renewal
            // deadline has passed; the bank's own date governs, so this is the
            // agent-visible expectation, not a decision.
            $expectedNpa = $due->modify('+1 day')->format('Y-m-d');

            if ($bucket === null) {
                $bucket = match (true) {
                    $daysRemaining < 0  => 'overdue',
                    $daysRemaining <= 7 => 'within_7',
                    $daysRemaining <= 15 => 'within_15',
                    default             => 'within_30',
                };
            }
        }

        Database::instance()->insert('visit_ckcc_details', [
            'visit_report_id' => $visitId,
            'loan_account_id' => $loanAccountId,

            'cif_number'         => self::str($section['cif_number'] ?? $lead['cif_number'] ?? null, 40),
            'sanction_date'      => self::nullableDate($section['sanction_date'] ?? $lead['sanction_date'] ?? null),
            'sanction_limit'     => self::nullableAmount($section['sanction_limit'] ?? $lead['sanction_limit'] ?? null),
            'drawing_power'      => self::nullableAmount($section['drawing_power'] ?? $lead['drawing_power'] ?? null),
            'outstanding_amount' => self::nullableAmount($section['outstanding_amount'] ?? $lead['outstanding_amount']),
            'interest_overdue'   => self::nullableAmount($section['interest_overdue'] ?? $lead['interest_overdue'] ?? null),
            'renewal_due_date'   => $dueDate,
            'expected_npa_date'  => $expectedNpa,
            'days_remaining'     => $daysRemaining,

            'eligible_for_renewal'   => self::flag($section['eligible_for_renewal'] ?? null),
            'renewal_due_bucket'     => $bucket,
            'kyc_status'             => self::enum($section['kyc_status'] ?? null, ['complete', 'pending']),
            'aadhaar_seeded'         => self::flag($section['aadhaar_seeded'] ?? null),
            'mobile_linked'          => self::flag($section['mobile_linked'] ?? null),
            'aadhaar_auth_completed' => self::flag($section['aadhaar_auth_completed'] ?? null),

            'doc_aadhaar'          => self::flag($section['doc_aadhaar'] ?? null),
            'doc_pan'              => self::flag($section['doc_pan'] ?? null),
            'doc_passbook'         => self::flag($section['doc_passbook'] ?? null),
            'doc_land_record'      => self::flag($section['doc_land_record'] ?? null),
            'doc_khasra_khatauni'  => self::flag($section['doc_khasra_khatauni'] ?? null),
            'doc_photograph'       => self::flag($section['doc_photograph'] ?? null),
            'doc_mobile_available' => self::flag($section['doc_mobile_available'] ?? null),
            'doc_others'           => self::flag($section['doc_others'] ?? null),
            'doc_other_text'       => self::str($section['doc_other_text'] ?? null, 255),

            'willing_to_renew'      => self::flag($section['willing_to_renew'] ?? null),
            'documents_handed_over' => self::flag($section['documents_handed_over'] ?? null),
            'renewal_form_signed'   => self::flag($section['renewal_form_signed'] ?? null),
            'ekyc_completed'        => self::flag($section['ekyc_completed'] ?? null),
            'biometrics_completed'  => self::flag($section['biometrics_completed'] ?? null),

            'agent_observation'         => self::text($section['agent_observation'] ?? null),
            'rec_renew_immediately'     => self::flag($section['rec_renew_immediately'] ?? null),
            'rec_documents_submitted'   => self::flag($section['rec_documents_submitted'] ?? null),
            'rec_followup_required'     => self::flag($section['rec_followup_required'] ?? null),
            'rec_not_interested'        => self::flag($section['rec_not_interested'] ?? null),
            'rec_branch_contact_urgent' => self::flag($section['rec_branch_contact_urgent'] ?? null),
            'rec_others'                => self::flag($section['rec_others'] ?? null),
            'rec_other_text'            => self::str($section['rec_other_text'] ?? null, 255),

            'st_customer_contacted'    => self::flag($section['st_customer_contacted'] ?? null),
            'st_customer_verified'     => self::flag($section['st_customer_verified'] ?? null),
            'st_documents_collected'   => self::flag($section['st_documents_collected'] ?? null),
            'st_application_submitted' => self::flag($section['st_application_submitted'] ?? null),
            'st_ckcc_renewed'          => self::flag($section['st_ckcc_renewed'] ?? null),
            'st_pending_at_branch'     => self::flag($section['st_pending_at_branch'] ?? null),
            'st_followup_required'     => self::flag($section['st_followup_required'] ?? null),
            'st_became_npa'            => self::flag($section['st_became_npa'] ?? null),
        ]);
    }

    /**
     * Pulls out one detail section.
     *
     * Accepts either a nested array (JSON body) or `ots_details[field]` style
     * flat keys, because the app submits the visit as multipart - it carries
     * photos - and multipart has no nesting. Returns null when the section is
     * absent or entirely empty, so no stray child row is written.
     *
     * @param  array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    private static function section(array $input, string $name): ?array
    {
        $section = [];

        if (isset($input[$name]) && is_array($input[$name])) {
            $section = $input[$name];
        } else {
            $prefix = $name . '[';
            foreach ($input as $key => $value) {
                if (is_string($key) && str_starts_with($key, $prefix) && str_ends_with($key, ']')) {
                    $section[substr($key, strlen($prefix), -1)] = $value;
                }
            }
            // Also accept a flat `ots_` / `ckcc_` prefix, e.g. ots_scheme.
            if ($section === []) {
                $flat = str_replace('_details', '_', $name);
                foreach ($input as $key => $value) {
                    if (is_string($key) && str_starts_with($key, $flat) && $key !== $flat) {
                        $section[substr($key, strlen($flat))] = $value;
                    }
                }
            }
        }

        foreach ($section as $value) {
            if (is_string($value) ? trim($value) !== '' : $value !== null) {
                return $section;
            }
        }
        return null;
    }

    private static function reportType(mixed $value): string
    {
        $type = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($type, self::REPORT_TYPES, true) ? $type : 'recovery';
    }

    /**
     * @param list<string> $allowed
     */
    private static function enum(mixed $value, array $allowed): ?string
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($candidate, $allowed, true) ? $candidate : null;
    }

    /** DECIMAL(5,2), clamped: a percentage outside 0-100 is a typo, not data. */
    private static function percent(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return round(max(0.0, min(100.0, (float) $value)), 2);
    }

    private static function flag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === null) {
            return 0;
        }
        return in_array(strtolower((string) $value), ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }

    private static function str(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : mb_substr($trimmed, 0, 20000);
    }

    private static function nullableAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value) ?? '';
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        return (float) $clean;
    }

    private static function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            [$y, $m, $d] = array_map('intval', explode('-', $raw));
            return checkdate($m, $d, $y) ? $raw : null;
        }
        return ImportService::parseDate($raw);
    }

    /** Occupation must match the ENUM, else store NULL rather than fail the insert. */
    private static function occupation(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalised = strtolower(trim((string) $value));
        return in_array($normalised, VisitReport::OCCUPATIONS, true) ? $normalised : null;
    }
}
