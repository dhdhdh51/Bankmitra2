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
        'customer_photo' => 'customer',
        'house_photo'    => 'house',
        'aadhaar_photo'  => 'aadhaar',
    ];

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
