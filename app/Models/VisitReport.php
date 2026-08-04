<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Paginator;

/**
 * APPEND ONLY. There is intentionally no update() or delete() method here -
 * a new field visit always inserts a new row, and history is never rewritten.
 */
final class VisitReport
{
    /**
     * The printed form's Current Occupation row, in its order.
     *
     * 'service' replaced 'job': the form says Service, and in this context the two
     * words mean the same thing while only one of them is distinguishable from
     * 'labour' at a glance.
     */
    public const OCCUPATIONS = ['agriculture', 'dairy', 'business', 'labour', 'service', 'others'];

    /** Checkbox groups, kept in one place so form, API and PDF stay in sync. */
    public const CONTACT_FLAGS = [
        'customer_met'       => 'Borrower Met',
        'family_member_met'  => 'Family Member Met',
        'house_locked'       => 'House Locked',
        'phone_contact'      => 'Mobile Contacted',
        'phone_switched_off' => 'Phone Switched Off',
    ];

    public const GENDERS = [
        'male'   => 'Male',
        'female' => 'Female',
        'other'  => 'Other',
    ];

    /**
     * The Loan Type row on the printed form.
     *
     * The column stays a VARCHAR rather than an ENUM because it is also a snapshot of
     * whatever the bank's own export wrote there, and a report filed before this list
     * existed still has to print. Anything not in the list prints as it was stored.
     */
    public const LOAN_TYPES = [
        'ckcc'         => 'CKCC',
        'agri_term'    => 'Agriculture Term Loan',
        'od'           => 'OD',
        'cc'           => 'CC',
        'msme'         => 'MSME',
        'housing'      => 'Housing',
        'other'        => 'Other',
    ];

    public const ASSET_CLASSIFICATIONS = [
        'standard' => 'Standard',
        'sma_0'    => 'SMA-0',
        'sma_1'    => 'SMA-1',
        'sma_2'    => 'SMA-2',
        'npa'      => 'NPA',
    ];

    public const RESIDENCE_VERIFICATION = [
        'confirmed'     => 'Confirmed',
        'not_confirmed' => 'Not Confirmed',
    ];

    public const NEIGHBOUR_VERIFICATION = [
        'conducted'     => 'Conducted',
        'not_conducted' => 'Not Conducted',
    ];

    /**
     * Section 7 of the printed form: what the borrower physically produced.
     *
     * Asked on every case type. This list used to live only on the CKCC renewal row,
     * so a recovery visit had nowhere to record that an Aadhaar card was shown.
     */
    public const DOCUMENT_FLAGS = [
        'doc_aadhaar'            => 'Aadhaar Card',
        'doc_pan'                => 'PAN Card',
        'doc_passbook'           => 'Passbook',
        'doc_land_record'        => 'Land Record',
        'doc_khatauni'           => 'Khatauni',
        'doc_electricity_bill'   => 'Electricity Bill',
        'doc_photograph'         => 'Photograph',
        'doc_mobile_verified'    => 'Mobile Verified',
        'doc_renewal_form'       => 'Renewal Form',
        'doc_ots_consent_letter' => 'OTS Consent Letter',
        'doc_others'             => 'Other',
    ];

    /**
     * Section 10: what the agent says is attached.
     *
     * Recorded separately from the photos and files that actually arrived, because the
     * gap between the two is exactly what a reviewer needs to see.
     */
    public const EVIDENCE_FLAGS = [
        'ev_borrower_photo' => 'Borrower Photograph',
        'ev_house_photo'    => 'House Photograph',
        'ev_land_photo'     => 'Land Photograph',
        'ev_aadhaar_copy'   => 'Aadhaar Copy',
        'ev_passbook_copy'  => 'Passbook Copy',
        'ev_gps_location'   => 'GPS Location',
        'ev_renewal_form'   => 'Renewal Form',
        'ev_ots_consent'    => 'OTS Consent',
        'ev_others'         => 'Other',
    ];

    public const RECOVERY_FLAGS = [
        'ready_to_pay'     => 'Ready To Pay',
        'not_ready'        => 'Not Ready',
        'interest_payment' => 'Interest Payment',
        'ots'              => 'OTS',
    ];

    public const REASON_FLAGS = [
        'reason_financial_problem' => 'Financial Problem',
        'reason_crop_loss'         => 'Crop Loss',
        'reason_animal_loss'       => 'Animal Loss',
        'reason_illness'           => 'Illness',
        'reason_unemployment'      => 'Unemployment',
        'reason_dispute'           => 'Dispute',
        'reason_other_loan'        => 'Other Loan',
        'reason_others'            => 'Others',
    ];

    public const RECOMMENDATION_FLAGS = [
        'rec_recovery_possible' => 'Recovery Possible',
        'rec_regular_followup'  => 'Regular Follow-up',
        'rec_legal_action'      => 'Legal Action',
        'rec_rc'                => 'RC',
        'rec_ots'               => 'OTS',
        'rec_others'            => 'Others',
    ];

    // -----------------------------------------------------------------------
    // Report types
    // -----------------------------------------------------------------------

    /**
     * The printed form's Case Type row, in its order.
     *
     * 'pre_npa' and 'post_npa' are the same doorstep verification done before an
     * account slips and after it has. They are neither settlement nor renewal work, so
     * before they existed here they were filed as plain recovery calls - which made the
     * pre-NPA worklist, the one that exists to stop an account going bad, unbuildable.
     */
    public const REPORT_TYPES = [
        'ots'           => 'KRM OTS',
        'ckcc_renewal'  => 'CKCC OD-2 Renewal',
        'recovery'      => 'Recovery Follow-up',
        'pre_npa'       => 'Pre-NPA Verification',
        'post_npa'      => 'Post-NPA Verification',
        'other'         => 'Other',
    ];

    public const OTS_SCHEMES = [
        'krm_ots'     => 'KRM OTS',
        'general_ots' => 'General OTS',
        'other'       => 'Other',
    ];

    public const OTS_APPROVAL_STATUSES = [
        'pending'  => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /**
     * Section 4's Customer Response row.
     *
     * Not a duplicate of `borrower_accepted`: that is the yes/no the settlement turns
     * on, and this is why. The four ways of saying no lead to four different next
     * actions - another visit, a different scheme, a closed file - and a boolean threw
     * all of that away.
     */
    public const OTS_CUSTOMER_RESPONSES = [
        'agreed'               => 'Agreed for OTS',
        'requested_time'       => 'Requested Time',
        'financial_difficulty' => 'Financial Difficulty',
        'refused'              => 'Refused OTS',
        'not_eligible'         => 'Not Eligible',
    ];

    /** Section 9, the KRM OTS half. */
    public const OTS_RECOMMENDATION_FLAGS = [
        'rec_proposal_recommended' => 'OTS Proposal Recommended',
        'rec_followup_required'    => 'Follow-up Required',
        'rec_customer_refused'     => 'Customer Refused',
        'rec_not_eligible'         => 'Not Eligible',
    ];

    /**
     * Section 13, the KRM OTS half.
     *
     * Distinct from `approval_status`: an offer the branch has approved can still be
     * waiting on the borrower's deposit, and a follow-up list is built from the second
     * fact, not the first.
     */
    public const OTS_STATUS_FLAGS = [
        'st_customer_contacted'       => 'Customer Contacted',
        'st_customer_verified'        => 'Customer Verified',
        'st_ots_accepted'             => 'OTS Accepted',
        'st_ots_rejected'             => 'OTS Rejected',
        'st_initial_deposit_received' => 'Initial Deposit Received',
        'st_ots_closed'               => 'OTS Closed',
        'st_followup_required'        => 'Follow-up Required',
    ];

    // -----------------------------------------------------------------------
    // CKCC renewal
    // -----------------------------------------------------------------------

    public const CKCC_DUE_BUCKETS = [
        'within_30' => 'Within 30 Days',
        'within_15' => 'Within 15 Days',
        'within_7'  => 'Within 7 Days',
        'overdue'   => 'Overdue',
    ];

    public const CKCC_KYC_STATUSES = [
        'complete' => 'Complete',
        'pending'  => 'Pending',
    ];

    /** Renewal readiness checks the branch needs before it can process a renewal. */
    public const CKCC_ELIGIBILITY_FLAGS = [
        'eligible_for_renewal'   => 'Eligible for CKCC Renewal',
        'aadhaar_seeded'         => 'Aadhaar Seeded',
        'mobile_linked'          => 'Mobile Linked',
        'aadhaar_auth_completed' => 'Aadhaar Authentication Completed',
    ];

    public const CKCC_CONSENT_FLAGS = [
        'willing_to_renew'      => 'Borrower Willing to Renew',
        'documents_handed_over' => 'Documents Handed Over',
        'renewal_form_signed'   => 'Renewal Form Signed',
        'ekyc_completed'        => 'Aadhaar e-KYC Completed',
        'biometrics_completed'  => 'Biometrics Completed',
    ];

    /** Section 9, the CKCC Renewal half. */
    public const CKCC_RECOMMENDATION_FLAGS = [
        'rec_renew_immediately'     => 'Renewal Immediately Recommended',
        'rec_documents_submitted'   => 'Documents Complete',
        'rec_pending_documents'     => 'Pending Documents',
        'rec_not_interested'        => 'Customer Not Interested',
        'rec_branch_contact_urgent' => 'Branch Follow-up Required',
        'rec_followup_required'     => 'Follow-up Required',
        'rec_others'                => 'Other',
    ];

    /** Section 13, the CKCC OD-2 Renewal half. */
    public const CKCC_STATUS_FLAGS = [
        'st_customer_contacted'    => 'Customer Contacted',
        'st_customer_verified'     => 'Customer Verified',
        'st_documents_collected'   => 'Documents Collected',
        'st_application_submitted' => 'Renewal Submitted',
        'st_ckcc_renewed'          => 'Renewal Approved',
        'st_pending_at_branch'     => 'Pending at Branch',
        'st_became_npa'            => 'Account Became NPA',
        'st_followup_required'     => 'Follow-up Required',
    ];

    /**
     * Section 11 of the printed form, verbatim.
     *
     * Lives here rather than in the PDF builder because the app shows the same words
     * above the tick box the agent has to accept before submitting, and a declaration
     * that reads one way on the screen and another way on the page is not a
     * declaration - it is two, and only one of them was agreed to.
     *
     * @var list<string>
     */
    public const DECLARATION = [
        'I hereby certify that the information contained in this report has been collected and '
            . 'verified during my personal physical field visit through direct interaction with the '
            . 'borrower and/or other reliable local sources, wherever applicable. The details recorded '
            . 'herein represent the factual position observed and verified during the visit and have '
            . 'been documented fairly, accurately, objectively, and in good faith to the best of my '
            . 'knowledge and belief.',
        'I further certify that no information has been intentionally concealed, altered, or '
            . 'misrepresented. The field verification has been conducted strictly in accordance with '
            . "the applicable Reserve Bank of India (RBI) guidelines, the Bank's extant policies, "
            . 'operational instructions, the Fair Practices Code, and the prescribed Code of Conduct '
            . 'governing field verification, customer interaction, and recovery-related activities.',
        'This report is submitted solely for the purpose of assessment, verification, recovery '
            . 'follow-up, and/or renewal processing, as applicable, and shall be subject to '
            . 'verification and acceptance by the Bank.',
    ];

    /** The closing note the printed form carries under section 13. */
    public const IMPORTANT_NOTE =
        'This report is designed for use in KRM OTS, CKCC OD-2 Renewal, Recovery Follow-up, '
        . 'Pre-NPA Verification, and Post-NPA Verification cases. It is intended to support field '
        . 'verification, customer due diligence, recovery monitoring, renewal processing, and timely '
        . "preventive action in accordance with the applicable RBI guidelines, the Bank's internal "
        . 'policies, and the Fair Practices Code.';

    /**
     * The KRM / OTS settlement section for a visit, or null when the agent did
     * not fill it in.
     *
     * @return array<string,mixed>|null
     */
    public static function otsDetails(int $visitReportId): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM visit_ots_details WHERE visit_report_id = ? LIMIT 1',
            [$visitReportId]
        );
    }

    /**
     * The CKCC OD-2 renewal section for a visit, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function ckccDetails(int $visitReportId): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM visit_ckcc_details WHERE visit_report_id = ? LIMIT 1',
            [$visitReportId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::instance()->first(
            'SELECT vr.*, u.name AS submitted_by_name, u.employee_code AS submitted_by_code,
                    b.name AS branch_display_name
               FROM visit_reports vr
               JOIN users u ON u.id = vr.agent_id
               JOIN branches b ON b.id = vr.branch_id
              WHERE vr.id = ? LIMIT 1',
            [$id]
        );
    }

    /** @return array<string,mixed>|null With decrypted mobile/aadhaar snapshot. */
    public static function findWithPii(int $id): ?array
    {
        $row = self::find($id);
        if ($row === null) {
            return null;
        }
        $row['mobile'] = Crypto::decrypt($row['mobile_enc'] ?? null);
        $row['alt_mobile'] = Crypto::decrypt($row['alt_mobile_enc'] ?? null);
        $row['aadhaar'] = Crypto::decrypt($row['aadhaar_enc'] ?? null);
        $row['pan'] = Crypto::decrypt($row['pan_enc'] ?? null);
        unset($row['mobile_enc'], $row['alt_mobile_enc'], $row['aadhaar_enc'], $row['pan_enc']);
        return $row;
    }

    /**
     * Inserts a visit report. Callers must go through VisitService::submit()
     * so the timeline event, promise and counters stay consistent.
     *
     * @param array<string,mixed> $data
     */
    public static function insert(array $data): int
    {
        return Database::instance()->insert('visit_reports', $data);
    }

    /** Idempotency guard for retried mobile submissions. */
    public static function findByClientUuid(string $uuid): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM visit_reports WHERE client_uuid = ? LIMIT 1',
            [$uuid]
        );
    }

    /**
     * Visit history for one loan account, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function forLoanAccount(int $loanAccountId, int $limit = 100): array
    {
        return Database::instance()->all(
            'SELECT vr.id, vr.report_type, vr.visit_date, vr.visit_time, vr.created_at, vr.agent_name, vr.village,
                    vr.customer_met, vr.family_member_met, vr.house_locked, vr.phone_contact,
                    vr.phone_switched_off, vr.borrower_alive, vr.same_address, vr.shifted, vr.occupation,
                    vr.ready_to_pay, vr.not_ready, vr.interest_payment, vr.ots,
                    vr.promise_amount, vr.promise_date, vr.remarks, vr.current_status,
                    vr.outstanding_amount, vr.overdue_amount,
                    (SELECT COUNT(*) FROM photos p WHERE p.visit_report_id = vr.id) AS photo_count,
                    (SELECT COUNT(*) FROM documents d WHERE d.visit_report_id = vr.id) AS document_count
               FROM visit_reports vr
              WHERE vr.loan_account_id = ?
              ORDER BY vr.created_at DESC, vr.id DESC
              LIMIT ' . max(1, min(500, $limit)),
            [$loanAccountId]
        );
    }

    /**
     * @param array{
     *   branch_id?:int|null, agent_id?:int|null, date_from?:string, date_to?:string,
     *   village?:string, loan_type?:string, search?:string
     * } $filters
     */
    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        [$clause, $params] = self::buildWhere($filters);

        return Paginator::fromQuery(
            "SELECT COUNT(*) FROM visit_reports vr WHERE {$clause}",
            "SELECT vr.id, vr.report_type, vr.loan_account_id, vr.loan_account_number, vr.customer_name, vr.village, vr.address,
                    vr.visit_date, vr.visit_time, vr.agent_name, vr.branch_name, vr.created_at,
                    vr.customer_met, vr.house_locked, vr.ready_to_pay, vr.not_ready,
                    vr.promise_amount, vr.promise_date, vr.outstanding_amount, vr.overdue_amount,
                    vr.loan_type, vr.remarks
               FROM visit_reports vr
              WHERE {$clause}
              ORDER BY vr.visit_date DESC, vr.created_at DESC, vr.id DESC",
            $params,
            $page,
            $perPage
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    public static function buildWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = 'vr.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['agent_id'])) {
            $where[] = 'vr.agent_id = ?';
            $params[] = (int) $filters['agent_id'];
        }

        $from = trim((string) ($filters['date_from'] ?? ''));
        if ($from !== '') {
            $where[] = 'vr.visit_date >= ?';
            $params[] = $from;
        }

        $to = trim((string) ($filters['date_to'] ?? ''));
        if ($to !== '') {
            $where[] = 'vr.visit_date <= ?';
            $params[] = $to;
        }

        $village = trim((string) ($filters['village'] ?? ''));
        if ($village !== '') {
            $where[] = 'vr.village = ?';
            $params[] = $village;
        }

        $loanType = trim((string) ($filters['loan_type'] ?? ''));
        if ($loanType !== '') {
            $where[] = 'vr.loan_type = ?';
            $params[] = $loanType;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(vr.loan_account_number LIKE ? OR vr.customer_name LIKE ? OR vr.address LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * The columns a reviewer is allowed to correct, and how to label them.
     *
     * Deliberately a short list. Everything here is something a reviewer can be
     * confident about from the report itself - a misheard name, a transposed digit, a
     * village spelled wrong. The tick boxes, the recommendation and the remarks are
     * NOT correctable: those are the agent's assertions about what they saw, and a
     * reviewer overwriting them turns the agent's report into the reviewer's.
     *
     * @return array<string,string>
     */
    public const CORRECTABLE = [
        'customer_name'              => 'Borrower name',
        'father_husband_name'        => 'Father / husband name',
        'address'                    => 'Complete residential address',
        'village'                    => 'Village / location',
        'family_member_name'         => 'Family member met',
        'family_member_relationship' => 'Relationship',
        'sp_cbc_name'                => 'SP / CBC name',
        'supervisor_name'            => 'Supervisor name',

        // The same rule as the eight above, applied to the rest of what the printed form
        // asks for in writing: every one of these is something a reviewer can be
        // confident about from the report itself or from the account, and every one of
        // them is somewhere a digit or a spelling gets transposed at a doorstep.
        //
        // Still nothing here is a tick box, a recommendation or an observation. Those
        // are the agent's assertions about what they saw, and a reviewer overwriting
        // them turns the agent's report into the reviewer's.
        'branch_code'                => 'Branch code',
        'regional_office'            => 'Regional office',
        'zone'                       => 'Zone',
        'linked_branch'              => 'Linked branch',
        'district'                   => 'District (visit)',
        'gram_panchayat'             => 'Gram panchayat',
        'tehsil'                     => 'Tehsil',
        'addr_village'               => 'Village (address)',
        'addr_district'              => 'District (address)',
        'state'                      => 'State',
        'pin_code'                   => 'PIN code',
        'cif_number'                 => 'CIF number',
        'agent_mobile'               => 'BC agent / DRA mobile',
        'supervisor_designation'     => 'Supervisor designation',
        'supervisor_employee_id'     => 'Supervisor employee / DRA ID',
    ];

    /**
     * Records an approval or rejection.
     *
     * Purely additive: it writes the approval columns and touches nothing the agent
     * submitted. The approver's own photograph and position are stored alongside their
     * user id, because "I approved it at the branch" and "I approved
     * forty of them from home at midnight" are different claims and only one of them
     * is verification.
     *
     * @param array<string,mixed> $approval
     */
    public static function recordApproval(int $id, array $approval): void
    {
        $approval['updated_at'] = date('Y-m-d H:i:s');

        Database::instance()->update('visit_reports', $approval, ['id' => $id]);
    }

    /**
     * Applies a correction and records what it changed.
     *
     * This is how "editable" and "append-only" coexist. The row is updated so the
     * report reads correctly from now on, and the same transaction writes the before
     * and after of every field that moved into visit_report_revisions - which nothing
     * ever updates or deletes. The submitted original is reconstructible by replaying
     * those backwards, and the printed report states how many times it was corrected
     * so a clean-looking document cannot hide that it differs from what was filed.
     *
     * Returns the revision number, or null when nothing actually changed - a save
     * with no edits must not manufacture an empty revision, or the count on the
     * printed report stops meaning anything.
     *
     * @param  array<string,mixed>  $proposed  column => new value
     * @return int|null
     */
    public static function applyRevision(
        int $id,
        array $proposed,
        ?int $actorId,
        ?string $actorName,
        ?string $reason,
        ?string $ip
    ): ?int {
        $db = Database::instance();

        $current = $db->first('SELECT * FROM visit_reports WHERE id = ? LIMIT 1', [$id]);
        if ($current === null) {
            return null;
        }

        $changes = [];
        $update = [];

        foreach ($proposed as $column => $value) {
            if (!array_key_exists($column, self::CORRECTABLE)) {
                continue;
            }

            $before = $current[$column];

            // Compared as strings so "" and null, or 5 and "5", do not register as a
            // change - otherwise every save would file a revision full of noise and
            // the real corrections would be impossible to find.
            if ((string) ($before ?? '') === (string) ($value ?? '')) {
                continue;
            }

            $changes[$column] = ['from' => $before, 'to' => $value];
            $update[$column] = $value;
        }

        if ($changes === []) {
            return null;
        }

        $revisionNo = ((int) ($current['revision_count'] ?? 0)) + 1;

        $db->transaction(static function () use ($db, $id, $update, $changes, $revisionNo, $actorId, $actorName, $reason, $ip): void {
            $db->insert('visit_report_revisions', [
                'visit_report_id' => $id,
                'revision_no'     => $revisionNo,
                'changed_by'      => $actorId,
                'changed_by_name' => $actorName,
                'changes'         => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'reason'          => $reason,
                'ip'              => $ip,
            ]);

            $db->update('visit_reports', $update + [
                'revision_count' => $revisionNo,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
        });

        return $revisionNo;
    }

    /**
     * Every correction ever made to a report, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function revisions(int $visitReportId): array
    {
        $rows = Database::instance()->all(
            'SELECT * FROM visit_report_revisions WHERE visit_report_id = ? ORDER BY revision_no DESC',
            [$visitReportId]
        );

        foreach ($rows as $index => $row) {
            $decoded = json_decode((string) $row['changes'], true);
            $rows[$index]['changes_decoded'] = is_array($decoded) ? $decoded : [];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public static function photos(int $visitReportId): array
    {
        return Database::instance()->all(
            'SELECT * FROM photos WHERE visit_report_id = ? ORDER BY photo_type ASC, id ASC',
            [$visitReportId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function documents(int $visitReportId): array
    {
        return Database::instance()->all(
            'SELECT * FROM documents WHERE visit_report_id = ? ORDER BY id ASC',
            [$visitReportId]
        );
    }

    /** Every photo ever captured for a loan account (profile gallery). @return list<array<string,mixed>> */
    public static function photosForLoanAccount(int $loanAccountId): array
    {
        return Database::instance()->all(
            'SELECT p.*, vr.visit_date
               FROM photos p
               LEFT JOIN visit_reports vr ON vr.id = p.visit_report_id
              WHERE p.loan_account_id = ?
              ORDER BY p.created_at DESC
              LIMIT 300',
            [$loanAccountId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function documentsForLoanAccount(int $loanAccountId): array
    {
        return Database::instance()->all(
            'SELECT d.*, vr.visit_date
               FROM documents d
               LEFT JOIN visit_reports vr ON vr.id = d.visit_report_id
              WHERE d.loan_account_id = ?
              ORDER BY d.created_at DESC
              LIMIT 300',
            [$loanAccountId]
        );
    }

    /**
     * Human-readable summary of which boxes were ticked, for the timeline.
     *
     * @param array<string,mixed> $report
     * @return list<string>
     */
    public static function tickedLabels(array $report, string $group): array
    {
        $map = match ($group) {
            'contact'        => self::CONTACT_FLAGS,
            'recovery'       => self::RECOVERY_FLAGS,
            'reason'         => self::REASON_FLAGS,
            'recommendation' => self::RECOMMENDATION_FLAGS,
            'documents'      => self::DOCUMENT_FLAGS,
            'evidence'       => self::EVIDENCE_FLAGS,
            default          => [],
        };

        $labels = [];
        foreach ($map as $column => $label) {
            if ((int) ($report[$column] ?? 0) === 1) {
                $labels[] = $label;
            }
        }
        return $labels;
    }
}
