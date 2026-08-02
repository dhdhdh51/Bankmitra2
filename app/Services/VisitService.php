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
use App\Services\TrackingService;

/**
 * Submits a Digital BC Field Visit Report.
 *
 * INVARIANT: submission is append-only. This service never updates or deletes an
 * existing visit_reports row. Each call inserts:
 *   1. a new visit_reports row (with a borrower/loan snapshot)
 *   2. a `visit` timeline event
 *   3. optionally a promises row + `promise_created` timeline event
 *   4. photo / document rows
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
        // The agent's own photograph, taken at the door. Not the portrait on their
        // user record: that one was uploaded in a branch office and proves only that
        // they have a face. This one carries the fix that says where they were
        // standing, which is the thing a disputed visit turns on.
        'agent_photo'        => 'agent',
    ];

    /**
     * The case types an agent can file, matching the printed form's Case Type row.
     *
     * 'pre_npa' and 'post_npa' are ordinary doorstep verification, not settlement or
     * renewal work - and before they existed here they were filed as plain recovery
     * calls, which made the pre-NPA worklist unbuildable from the reports themselves.
     */
    public const REPORT_TYPES = ['recovery', 'ots', 'ckcc_renewal', 'pre_npa', 'post_npa', 'other'];

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
                    'media'      => ['photos' => 0, 'documents' => 0],
                    'warnings'   => ['This visit was already submitted.'],
                ];
            }
        }

        $customer = $db->first(
            'SELECT id, name, father_husband_name, address, village,
                    mobile_enc, mobile_hash, mobile_masked,
                    alt_mobile_enc, alt_mobile_hash, alt_mobile_masked,
                    aadhaar_enc, aadhaar_hash, aadhaar_masked
               FROM customers WHERE id = ? LIMIT 1',
            [(int) $lead['customer_id']]
        );
        if ($customer === null) {
            throw new \RuntimeException('The borrower record could not be found.');
        }

        // The branch's own place in the hierarchy, for the header of the printed form.
        // Read here rather than added to LoanAccount's projection, which is shared by
        // every list screen in the panel and does not need four more columns per row.
        $branch = $db->first(
            'SELECT name, branch_code, district, state, regional_office, zone
               FROM branches WHERE id = ? LIMIT 1',
            [(int) $lead['branch_id']]
        ) ?? [];

        // The agent's own contact number, printed in the certification block so the
        // branch can ring back whoever filed the report. Their own staff number, not
        // the borrower's - and it is on their user record already, so asking for it
        // again at every doorstep would only be a chance to mistype it.
        $agentRow = $db->first('SELECT mobile_enc FROM users WHERE id = ? LIMIT 1', [(int) $agent['id']]);
        $agentMobile = Crypto::decrypt($agentRow['mobile_enc'] ?? null);

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
        $mediaCounts = ['photos' => 0, 'documents' => 0];

        $db->transaction(static function () use (
            $db,
            $input,
            $agent,
            $lead,
            $customer,
            $branch,
            $agentMobile,
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

                // ---- 1. General information -------------------------------
                'visit_date'  => $visitDate,
                'visit_time'  => $visitTime,
                'bc_code'     => $agent['bc_code'] ?? $lead['bc_code'] ?? null,
                'agent_name'  => (string) $agent['name'],
                'branch_name' => (string) ($lead['branch_name'] ?? ''),
                'village'     => self::str($input['village'] ?? $customer['village'], 150),

                // Defaulted from the branch master and overridable from the form, in
                // that order: the branch is right about its own code and zone, and the
                // agent is the one standing in the district they are standing in.
                'branch_code'     => self::str($input['branch_code'] ?? $branch['branch_code'] ?? null, 40),
                'regional_office' => self::str($input['regional_office'] ?? $branch['regional_office'] ?? null, 150),
                'zone'            => self::str($input['zone'] ?? $branch['zone'] ?? null, 150),
                'linked_branch'   => self::str($input['linked_branch'] ?? $branch['name'] ?? null, 150),
                'district'        => self::str($input['district'] ?? $branch['district'] ?? null, 150),

                'report_type'            => self::reportType($input['report_type'] ?? null),
                'report_type_other_text' => self::str($input['report_type_other_text'] ?? null, 150),
                'sp_cbc_name'            => self::str($input['sp_cbc_name'] ?? null, 150),

                // ---- 2. Borrower information ------------------------------
                // Snapshot - copied so the signed report never changes even if the
                // customer master is later corrected.
                'customer_name'       => (string) $customer['name'],
                'father_husband_name' => $customer['father_husband_name'],
                'gender'              => self::enum($input['gender'] ?? null, ['male', 'female', 'other']),
                'date_of_birth'       => self::nullableDate($input['date_of_birth'] ?? null),
                'address'             => self::str($input['address'] ?? $customer['address'], 500),
                'mobile_enc'          => $customer['mobile_enc'],
                'mobile_hash'         => $customer['mobile_hash'],
                'mobile_masked'       => $customer['mobile_masked'],
                // The second number, taken from the borrower record where the previous
                // release put it. Snapshotted like the first one, so a report still shows
                // the number that was current on the day somebody rang it.
                'alt_mobile_enc'      => $customer['alt_mobile_enc'],
                'alt_mobile_hash'     => $customer['alt_mobile_hash'],
                'alt_mobile_masked'   => $customer['alt_mobile_masked'],
                'aadhaar_enc'         => $customer['aadhaar_enc'],
                'aadhaar_hash'        => $customer['aadhaar_hash'],
                'aadhaar_masked'      => $customer['aadhaar_masked'],
                // Optional on the form and encrypted like the other two identifiers.
                ...self::panColumns($input['pan_number'] ?? null),

                'addr_village'   => self::str($input['addr_village'] ?? null, 150),
                'gram_panchayat' => self::str($input['gram_panchayat'] ?? null, 150),
                'tehsil'         => self::str($input['tehsil'] ?? null, 150),
                'addr_district'  => self::str($input['addr_district'] ?? null, 150),
                'state'          => self::str($input['state'] ?? $branch['state'] ?? null, 100),
                'pin_code'       => self::str($input['pin_code'] ?? null, 10),

                // ---- 3. Loan account details ------------------------------
                'loan_account_number' => (string) $lead['loan_account_number'],
                'cif_number'          => self::str($input['cif_number'] ?? $lead['cif_number'] ?? null, 40),
                'loan_type'           => self::str($input['loan_type'] ?? $lead['loan_type'] ?? null, 80),
                'loan_type_other_text' => self::str($input['loan_type_other_text'] ?? null, 150),
                'sanction_date'       => self::nullableDate($input['sanction_date'] ?? $lead['sanction_date'] ?? null),
                'sanction_limit'      => self::nullableAmount($input['sanction_limit'] ?? $lead['sanction_limit'] ?? null),
                'drawing_power'       => self::nullableAmount($input['drawing_power'] ?? $lead['drawing_power'] ?? null),
                'outstanding_amount'  => (float) $lead['outstanding_amount'],
                'interest_overdue'    => self::nullableAmount($input['interest_overdue'] ?? $lead['interest_overdue'] ?? null),
                'overdue_amount'      => (float) $lead['overdue_amount'],
                'npa_date'            => $lead['npa_date'],
                'asset_classification' => self::assetClassification(
                    $input['asset_classification'] ?? $lead['asset_classification'] ?? null
                ),
                'current_status'      => (string) $lead['current_status'],

                // ---- 6. Physical verification: who was met ----------------
                'customer_met'               => self::flag($input['customer_met'] ?? null),
                'family_member_met'          => self::flag($input['family_member_met'] ?? null),
                'house_locked'               => self::flag($input['house_locked'] ?? null),
                'phone_contact'              => self::flag($input['phone_contact'] ?? null),
                'phone_switched_off'         => self::flag($input['phone_switched_off'] ?? null),
                'family_member_name'         => self::str($input['family_member_name'] ?? null, 150),
                'family_member_relationship' => self::str($input['family_member_relationship'] ?? null, 80),

                // ---- 6. Physical verification: what was seen --------------
                'borrower_alive'        => self::flag($input['borrower_alive'] ?? 1),
                'same_address'          => self::flag($input['same_address'] ?? 1),
                'shifted'               => self::flag($input['shifted'] ?? null),
                // Left null when the form did not answer. "Not confirmed" is an
                // assertion about a check that was run; silence is not.
                'residence_verified'     => self::enum(
                    $input['residence_verified'] ?? null,
                    ['confirmed', 'not_confirmed']
                ),
                'neighbour_verification' => self::enum(
                    $input['neighbour_verification'] ?? null,
                    ['conducted', 'not_conducted']
                ),
                'occupation'            => self::occupation($input['occupation'] ?? null),
                'occupation_other_text' => self::str($input['occupation_other_text'] ?? null, 150),

                // ---- 7. Documents verified --------------------------------
                'doc_aadhaar'            => self::flag($input['doc_aadhaar'] ?? null),
                'doc_pan'                => self::flag($input['doc_pan'] ?? null),
                'doc_passbook'           => self::flag($input['doc_passbook'] ?? null),
                'doc_land_record'        => self::flag($input['doc_land_record'] ?? null),
                'doc_khatauni'           => self::flag($input['doc_khatauni'] ?? null),
                'doc_electricity_bill'   => self::flag($input['doc_electricity_bill'] ?? null),
                'doc_photograph'         => self::flag($input['doc_photograph'] ?? null),
                'doc_mobile_verified'    => self::flag($input['doc_mobile_verified'] ?? null),
                'doc_renewal_form'       => self::flag($input['doc_renewal_form'] ?? null),
                'doc_ots_consent_letter' => self::flag($input['doc_ots_consent_letter'] ?? null),
                'doc_others'             => self::flag($input['doc_others'] ?? null),
                'doc_other_text'         => self::str($input['doc_other_text'] ?? null, 255),

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

                // ---- 9. Recommendation ------------------------------------
                'rec_recovery_possible' => self::flag($input['rec_recovery_possible'] ?? null),
                'rec_regular_followup'  => self::flag($input['rec_regular_followup'] ?? null),
                'rec_legal_action'      => self::flag($input['rec_legal_action'] ?? null),
                'rec_rc'                => self::flag($input['rec_rc'] ?? null),
                'rec_ots'               => self::flag($input['rec_ots'] ?? null),
                'rec_others'            => self::flag($input['rec_others'] ?? null),
                'rec_other_text'        => self::str($input['rec_other_text'] ?? null, 255),
                'general_recommendation' => self::text($input['general_recommendation'] ?? null),

                // ---- 10. Evidence attached --------------------------------
                'ev_borrower_photo' => self::flag($input['ev_borrower_photo'] ?? null),
                'ev_house_photo'    => self::flag($input['ev_house_photo'] ?? null),
                'ev_land_photo'     => self::flag($input['ev_land_photo'] ?? null),
                'ev_aadhaar_copy'   => self::flag($input['ev_aadhaar_copy'] ?? null),
                'ev_passbook_copy'  => self::flag($input['ev_passbook_copy'] ?? null),
                'ev_gps_location'   => self::flag($input['ev_gps_location'] ?? null),
                'ev_renewal_form'   => self::flag($input['ev_renewal_form'] ?? null),
                'ev_ots_consent'    => self::flag($input['ev_ots_consent'] ?? null),
                'ev_others'         => self::flag($input['ev_others'] ?? null),
                'ev_other_text'     => self::str($input['ev_other_text'] ?? null, 255),

                // ---- 8. BC agent / DRA observations -----------------------
                'remarks'     => self::text($input['remarks'] ?? null),

                // ---- 11. Declaration --------------------------------------
                'declaration_accepted' => self::flag($input['declaration_accepted'] ?? null),

                // ---- 12. Certification ------------------------------------
                'agent_mobile'           => self::str($input['agent_mobile'] ?? $agentMobile, 20),
                'supervisor_name'        => self::str($input['supervisor_name'] ?? null, 150),
                'supervisor_designation' => self::str($input['supervisor_designation'] ?? null, 100),
                'supervisor_employee_id' => self::str($input['supervisor_employee_id'] ?? null, 40),
                'supervisor_verified_at' => self::nullableDate($input['supervisor_verified_at'] ?? null),

                // Where the agent was standing, when the app reports it and the
                // agent has consented. See geo() - a report is never rejected over
                // this, because a missing fix is not a reason to lose a doorstep
                // visit that already happened.
                ...self::geo($input, (int) $agent['id']),

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
     * Handles both multipart uploads and base64 payloads (the Android app may use
     * either for photos).
     *
     * @param array<string,mixed> $input
     * @param list<string>        $warnings
     * @return array<string,int>
     */
    private static function attachMedia(int $visitId, int $loanAccountId, int $userId, array $input, array &$warnings): array
    {
        $counts = ['photos' => 0, 'documents' => 0];

        $imageMime = (array) Config::get('uploads.allowed_image_mime', ['image/jpeg', 'image/png', 'image/webp']);
        $docMime = (array) Config::get('uploads.allowed_doc_mime', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
        $maxPhoto = (int) Config::get('uploads.max_photo_bytes', 8 * 1024 * 1024);
        $maxDoc = (int) Config::get('uploads.max_document_bytes', 12 * 1024 * 1024);

        // ---- typed photos ------------------------------------------------
        foreach (self::PHOTO_FIELDS as $field => $photoType) {
            try {
                // Only a camera capture carries a point. A gallery pick deliberately
                // gets null rather than the visit's coordinates.
                $point = self::photoPoint($input, $photoType, $userId);
                $source = self::photoSource($input, $photoType);
                $capturedAt = self::photoCapturedAt($input, $photoType);

                if (Uploader::hasUpload($field)) {
                    foreach (Uploader::normalizeMultiple($field) as $file) {
                        $stored = Uploader::store($file, 'photos', $imageMime, $maxPhoto);
                        self::insertPhoto($visitId, $loanAccountId, $photoType, $stored, $userId, $point, $source, $capturedAt);
                        $counts['photos']++;
                    }
                    continue;
                }

                $base64 = trim((string) ($input[$field . '_base64'] ?? ''));
                if ($base64 !== '') {
                    $stored = Uploader::storeBase64($base64, 'photos', $maxPhoto);
                    self::insertPhoto($visitId, $loanAccountId, $photoType, $stored, $userId, $point, $source, $capturedAt);
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

        return $counts;
    }

    /**
     * @param array{path:string,original_name:string,mime:string,size:int,width:int|null,height:int|null} $stored
     * @param array{latitude:float,longitude:float,accuracy:int|null}|null $point
     */
    private static function insertPhoto(
        int $visitId,
        int $loanAccountId,
        string $photoType,
        array $stored,
        int $userId,
        ?array $point = null,
        string $source = 'unknown',
        ?string $capturedAt = null
    ): void {
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
            'gps_latitude'    => $point['latitude'] ?? null,
            'gps_longitude'   => $point['longitude'] ?? null,
            'gps_accuracy_m'  => $point['accuracy'] ?? null,
            // Also never written until now. The column has always carried the comment
            // "device clock at capture", the caption has always tried to print it, and
            // every row held NULL - so a printed photograph said where it was taken but
            // never when, and two photographs of the same door an hour apart were
            // indistinguishable.
            //
            // Recorded independently of the fix, because the two are independent: a
            // camera photograph taken inside a house has no coordinates and a perfectly
            // good capture time, and dropping the time along with the position would
            // throw away the half we do know. Null when the app sent nothing, rather
            // than the moment the upload reached the server - that is an arrival time
            // dressed up as a capture time.
            'captured_at'     => $capturedAt,
            // Recorded, finally. This column existed with a comment explaining that
            // capture_source = 'camera' is what makes a coordinate mean anything -
            // and nothing ever wrote it, so every photograph in the database claimed
            // 'unknown' and the printed report could not tell a doorstep photograph
            // from something picked out of the gallery.
            'capture_source'  => $source,
            'uploaded_by'     => $userId,
        ]);
    }

    /**
     * The visit's GPS columns, derived from what the app sent.
     *
     * Three rules, and each one exists because the alternative is a record that
     * claims more than it knows:
     *
     *   NO CONSENT, NO COORDINATES. Consent is checked on the server, not trusted
     *   from the client, and an agent who has not acknowledged the notice - or has
     *   withdrawn - gets `denied` with empty coordinates. The app is not the place
     *   this is enforced, because the app is the part an agent could be handed with
     *   the toggle already flipped.
     *
     *   AN IMPLAUSIBLE FIX IS NOT A FIX. (0,0) is what a device reports when it has
     *   nothing, and it is a real point in the Gulf of Guinea. Recording it would
     *   put every agent without a signal in the same fictional place.
     *
     *   A MISSING FIX NEVER BLOCKS A SUBMIT. `unavailable` is a perfectly normal
     *   outcome indoors or in a village with no sky view. Refusing the report would
     *   lose a visit that actually happened, which is worse than not knowing where
     *   it happened.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private static function geo(array $input, int $agentId): array
    {
        $blank = [
            'gps_latitude' => null,
            'gps_longitude' => null,
            'gps_accuracy_m' => null,
            'gps_captured_at' => null,
            'gps_address' => null,
        ];

        // The app tells us it asked and was refused. Recorded as such, because
        // "refused" and "no signal" are different conversations with a supervisor.
        if ((string) ($input['gps_source'] ?? '') === 'denied') {
            return $blank + ['gps_source' => 'denied'];
        }

        $latitude = self::nullableAmount($input['gps_latitude'] ?? null);
        $longitude = self::nullableAmount($input['gps_longitude'] ?? null);

        if ($latitude === null || $longitude === null) {
            return $blank + ['gps_source' => 'unavailable'];
        }

        if (!TrackingService::hasConsented($agentId)) {
            return $blank + ['gps_source' => 'denied'];
        }

        if (!TrackingService::plausible((float) $latitude, (float) $longitude)) {
            return $blank + ['gps_source' => 'unavailable'];
        }

        $accuracy = $input['gps_accuracy_m'] ?? null;
        $capturedAt = self::str($input['gps_captured_at'] ?? null, 19);

        return [
            'gps_latitude' => (float) $latitude,
            'gps_longitude' => (float) $longitude,
            'gps_accuracy_m' => $accuracy === null || $accuracy === '' ? null : max(0, (int) $accuracy),
            // A device clock ahead of the server is replaced rather than trusted; a
            // visit stamped next week sorts wrongly forever.
            'gps_captured_at' => $capturedAt !== null && strtotime($capturedAt) !== false
                && strtotime($capturedAt) <= time() + 300
                    ? date('Y-m-d H:i:s', (int) strtotime($capturedAt))
                    : date('Y-m-d H:i:s'),
            // Left null on purpose. The address is resolved later from the
            // coordinates by cron/geocode-backfill.php and cached; freezing a free
            // service's guess into the report would make it indistinguishable from
            // something the agent asserted.
            'gps_address' => null,
            'gps_source' => 'device',
        ];
    }

    /**
     * Whether this photograph came from the camera or the gallery.
     *
     * Never inferred from the presence of coordinates. A camera photograph taken
     * indoors has no fix, and treating "no coordinates" as "gallery" would label an
     * honest doorstep photograph as something picked off the phone - which on a
     * recovery file is an accusation.
     *
     * `unknown` is the answer for an older app that says nothing, and it is a
     * different thing from either: it means nobody recorded it, not that it was
     * suspect.
     *
     * @param array<string,mixed> $input
     */
    private static function photoSource(array $input, string $slot): string
    {
        $explicit = strtolower(trim((string) ($input[$slot . '_photo_source'] ?? '')));
        if (in_array($explicit, ['camera', 'gallery'], true)) {
            return $explicit;
        }

        // Older apps only ever sent this, and only for camera captures.
        if ((string) ($input[$slot . '_photo_gps_source'] ?? '') === 'camera') {
            return 'camera';
        }

        return 'unknown';
    }

    /**
     * The photo's own coordinates, when the app captured one per photo.
     *
     * Falls back to the visit's fix. A gallery-picked image sends no point of its
     * own and must not inherit one: a photo taken last week at home would then
     * carry today's doorstep coordinates, which is exactly the claim this column
     * would be used to make.
     *
     * @param  array<string,mixed>  $input
     * @return array{latitude:float,longitude:float,accuracy:int|null}|null
     */
    private static function photoPoint(array $input, string $slot, int $agentId): ?array
    {
        if ((string) ($input[$slot . '_photo_gps_source'] ?? '') !== 'camera') {
            return null;
        }

        $latitude = self::nullableAmount($input[$slot . '_photo_latitude'] ?? null);
        $longitude = self::nullableAmount($input[$slot . '_photo_longitude'] ?? null);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        if (!TrackingService::hasConsented($agentId)
            || !TrackingService::plausible((float) $latitude, (float) $longitude)) {
            return null;
        }

        $accuracy = $input[$slot . '_photo_accuracy_m'] ?? null;

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'accuracy' => $accuracy === null || $accuracy === '' ? null : max(0, (int) $accuracy),
        ];
    }

    /**
     * When the photograph was taken, per the device, or null if it did not say.
     *
     * Deliberately not defaulted to "now": the upload can arrive hours after the
     * capture from a phone that was out of signal all afternoon, and writing the
     * arrival time into a column labelled "device clock at capture" would be a
     * fabricated fact rather than a missing one.
     *
     * @param array<string,mixed> $input
     */
    private static function photoCapturedAt(array $input, string $slot): ?string
    {
        return self::deviceClock($input[$slot . '_photo_captured_at'] ?? null, null);
    }

    /**
     * A device timestamp, clamped to the server's clock.
     *
     * The same rule geo() applies to the visit's own stamp: a phone whose clock runs
     * ahead would file a photograph timestamped next week, and it would sort ahead of
     * everything real forever. A clock running behind is left alone - a phone that was
     * off overnight is ordinary, and rewriting it would erase a true capture time.
     *
     * @param string|null $fallback What to return when nothing usable was sent.
     */
    private static function deviceClock(mixed $value, ?string $fallback = 'now'): ?string
    {
        $default = $fallback === 'now' ? date('Y-m-d H:i:s') : $fallback;

        $raw = self::str($value, 19);
        if ($raw === null) {
            return $default;
        }

        $parsed = strtotime($raw);

        return $parsed !== false && $parsed <= time() + 300
            ? date('Y-m-d H:i:s', $parsed)
            : $default;
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
            'scheme'           => self::enum($section['scheme'] ?? null, ['krm_ots', 'general_ots', 'other']),
            'scheme_other_text' => self::str($section['scheme_other_text'] ?? null, 150),

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
            // Why, not just whether. "Asked for time" and "refused outright" both leave
            // borrower_accepted at 0 and lead to completely different next actions.
            'customer_response' => self::enum($section['customer_response'] ?? null, [
                'agreed', 'requested_time', 'financial_difficulty', 'refused', 'not_eligible',
            ]),
            'rejection_reason'  => self::str($section['rejection_reason'] ?? null, 500),
            // When they say they will pay, as against deposit_date, when they did.
            'expected_deposit_date' => self::nullableDate($section['expected_deposit_date'] ?? null),

            'rec_proposal_recommended' => self::flag($section['rec_proposal_recommended'] ?? null),
            'rec_followup_required'    => self::flag($section['rec_followup_required'] ?? null),
            'rec_customer_refused'     => self::flag($section['rec_customer_refused'] ?? null),
            'rec_not_eligible'         => self::flag($section['rec_not_eligible'] ?? null),

            'st_customer_contacted'       => self::flag($section['st_customer_contacted'] ?? null),
            'st_customer_verified'        => self::flag($section['st_customer_verified'] ?? null),
            'st_ots_accepted'             => self::flag($section['st_ots_accepted'] ?? null),
            'st_ots_rejected'             => self::flag($section['st_ots_rejected'] ?? null),
            'st_initial_deposit_received' => self::flag($section['st_initial_deposit_received'] ?? null),
            'st_ots_closed'               => self::flag($section['st_ots_closed'] ?? null),
            'st_followup_required'        => self::flag($section['st_followup_required'] ?? null),
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

            // The document checklist is NOT written here any more: it moved up to
            // visit_reports, where the printed form has it (section 7, asked on every
            // case type). Two copies meant a renewal report carried the same eleven
            // boxes twice and could disagree with itself.

            'willing_to_renew'      => self::flag($section['willing_to_renew'] ?? null),
            'documents_handed_over' => self::flag($section['documents_handed_over'] ?? null),
            'renewal_form_signed'   => self::flag($section['renewal_form_signed'] ?? null),
            'ekyc_completed'        => self::flag($section['ekyc_completed'] ?? null),
            'biometrics_completed'  => self::flag($section['biometrics_completed'] ?? null),

            'agent_observation'         => self::text($section['agent_observation'] ?? null),
            'rec_renew_immediately'     => self::flag($section['rec_renew_immediately'] ?? null),
            'rec_documents_submitted'   => self::flag($section['rec_documents_submitted'] ?? null),
            'rec_pending_documents'     => self::flag($section['rec_pending_documents'] ?? null),
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

        // An app built before the printed form's wording was adopted still sends 'job'.
        // Translated rather than dropped: the two words mean the same thing here, and
        // silently storing NULL would lose an occupation somebody recorded at a door.
        if ($normalised === 'job') {
            $normalised = 'service';
        }

        return in_array($normalised, VisitReport::OCCUPATIONS, true) ? $normalised : null;
    }

    /**
     * The encrypted / hashed / masked triplet for an optional PAN.
     *
     * Hashed with panHash() and not searchHash(): the latter normalises to digits only,
     * so every PAN would reduce to its four-digit block and two unrelated borrowers
     * could collide on it. That bug would only ever show up as a lookup returning the
     * wrong person.
     *
     * Returns all three columns as NULL when nothing was entered, rather than omitting
     * them - the form marks the field optional, and a report filed without one must
     * clear any value a retried submit might otherwise leave behind.
     *
     * @return array<string,string|null>
     */
    private static function panColumns(mixed $value): array
    {
        $pan = Crypto::normalisePan(self::str($value, 20));

        return [
            'pan_enc'    => Crypto::encrypt($pan),
            'pan_hash'   => Crypto::panHash($pan),
            'pan_masked' => Crypto::maskPan($pan),
        ];
    }

    /**
     * Maps an asset classification onto the five boxes the printed form offers.
     *
     * loan_accounts.asset_classification is free text on purpose - it holds whatever
     * the bank's export wrote, which is anything from "SMA-1" to "Doubtful 2". The
     * form is a closed list, so anything outside it becomes NULL rather than being
     * forced into the nearest box: a report that claimed "Standard" because nothing
     * else matched would be worse than one that leaves the row blank.
     */
    private static function assetClassification(mixed $value): ?string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }

        // Collapses "SMA-0", "SMA 0" and "sma0" onto one key.
        $key = preg_replace('/[^a-z0-9]+/', '', $raw) ?? '';

        return match ($key) {
            'standard'         => 'standard',
            'sma0'             => 'sma_0',
            'sma1'             => 'sma_1',
            'sma2'             => 'sma_2',
            'npa'              => 'npa',
            default            => null,
        };
    }
}
