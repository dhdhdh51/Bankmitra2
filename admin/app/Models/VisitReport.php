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
    public const OCCUPATIONS = ['agriculture', 'dairy', 'business', 'job', 'labour', 'others'];

    /** Checkbox groups, kept in one place so form, API and PDF stay in sync. */
    public const CONTACT_FLAGS = [
        'customer_met'       => 'Customer Met',
        'family_member_met'  => 'Family Member Met',
        'house_locked'       => 'House Locked',
        'phone_contact'      => 'Phone Contact',
        'phone_switched_off' => 'Phone Switched Off',
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

    public const REPORT_TYPES = [
        'recovery'      => 'Recovery Visit',
        'ots'           => 'KRM / OTS Settlement',
        'ckcc_renewal'  => 'CKCC OD-2 Renewal',
    ];

    public const OTS_SCHEMES = [
        'krm_ots'     => 'KRM OTS',
        'general_ots' => 'General OTS',
    ];

    public const OTS_APPROVAL_STATUSES = [
        'pending'  => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
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

    /**
     * What the borrower physically had with them.
     *
     * Separate from uploaded documents: an agent can confirm a passbook exists
     * without photographing it, and the branch still needs to know.
     */
    public const CKCC_DOCUMENT_FLAGS = [
        'doc_aadhaar'          => 'Aadhaar Card',
        'doc_pan'              => 'PAN Card',
        'doc_passbook'         => 'Passbook',
        'doc_land_record'      => 'Land Record',
        'doc_khasra_khatauni'  => 'Khasra / Khatauni',
        'doc_photograph'       => 'Photograph',
        'doc_mobile_available' => 'Mobile Available',
        'doc_others'           => 'Other',
    ];

    public const CKCC_CONSENT_FLAGS = [
        'willing_to_renew'      => 'Borrower willing to renew CKCC',
        'documents_handed_over' => 'Documents handed over',
        'renewal_form_signed'   => 'Renewal Form Signed',
        'ekyc_completed'        => 'Aadhaar e-KYC Completed',
        'biometrics_completed'  => 'Biometrics Completed',
    ];

    public const CKCC_RECOMMENDATION_FLAGS = [
        'rec_renew_immediately'     => 'CKCC Renewal should be processed immediately',
        'rec_documents_submitted'   => 'Borrower has submitted all required documents',
        'rec_followup_required'     => 'Follow-up Required',
        'rec_not_interested'        => 'Customer not interested in renewal',
        'rec_branch_contact_urgent' => 'Branch should contact customer urgently',
        'rec_others'                => 'Other',
    ];

    public const CKCC_STATUS_FLAGS = [
        'st_customer_contacted'    => 'Customer Contacted',
        'st_customer_verified'     => 'Customer Verified',
        'st_documents_collected'   => 'Renewal Documents Collected',
        'st_application_submitted' => 'Renewal Application Submitted',
        'st_ckcc_renewed'          => 'CKCC Renewed',
        'st_pending_at_branch'     => 'Pending at Branch',
        'st_followup_required'     => 'Follow-up Required',
        'st_became_npa'            => 'Account Became NPA',
    ];

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
        $row['aadhaar'] = Crypto::decrypt($row['aadhaar_enc'] ?? null);
        unset($row['mobile_enc'], $row['aadhaar_enc']);
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
                    (SELECT COUNT(*) FROM documents d WHERE d.visit_report_id = vr.id) AS document_count,
                    (SELECT COUNT(*) FROM signatures s WHERE s.visit_report_id = vr.id) AS signature_count
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
            "SELECT vr.id, vr.report_type, vr.loan_account_id, vr.loan_account_number, vr.customer_name, vr.village,
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
            $where[] = '(vr.loan_account_number LIKE ? OR vr.customer_name LIKE ? OR vr.village LIKE ?)';
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
        'address'                    => 'Address',
        'village'                    => 'Village',
        'family_member_name'         => 'Family member met',
        'family_member_relationship' => 'Relationship',
        'sp_cbc_name'                => 'SP / CBC name',
        'supervisor_name'            => 'Supervisor name',
    ];

    /**
     * Records an approval or rejection.
     *
     * Purely additive: it writes the approval columns and touches nothing the agent
     * submitted. The approver's own photograph, signature and position are stored
     * alongside their user id, because "I approved it at the branch" and "I approved
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

    /** @return list<array<string,mixed>> */
    public static function signatures(int $visitReportId): array
    {
        return Database::instance()->all(
            'SELECT * FROM signatures WHERE visit_report_id = ? ORDER BY signature_type ASC',
            [$visitReportId]
        );
    }

    /**
     * Attach a signature image uploaded from the panel.
     *
     * Written as `capture_method = 'panel_upload'` with no coordinates at all. The only
     * position available at upload time is wherever the person uploading is sitting,
     * and putting that under a borrower's signature would be a fact about a clerk
     * dressed up as a fact about a doorstep.
     *
     * `captured_at` is likewise left null rather than set to now: the signature was made
     * at some earlier point nobody recorded, and "now" would be the upload time.
     *
     * @param array{file_path:string,signed_name:?string,uploaded_note:?string,uploaded_by:int} $data
     */
    public static function attachUploadedSignature(
        int $visitReportId,
        int $loanAccountId,
        string $type,
        array $data
    ): void {
        // One of each type per report is a unique key, so an upload that replaces a
        // previous upload updates in place rather than failing on the constraint.
        Database::instance()->query(
            'INSERT INTO signatures
                (visit_report_id, loan_account_id, signature_type, file_path, signed_name,
                 captured_at, gps_source, capture_method, uploaded_note, uploaded_by)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                file_path      = VALUES(file_path),
                signed_name    = VALUES(signed_name),
                captured_at    = NULL,
                gps_latitude   = NULL,
                gps_longitude  = NULL,
                gps_accuracy_m = NULL,
                gps_source     = VALUES(gps_source),
                capture_method = VALUES(capture_method),
                uploaded_note  = VALUES(uploaded_note),
                uploaded_by    = VALUES(uploaded_by)',
            [
                $visitReportId,
                $loanAccountId,
                $type,
                $data['file_path'],
                $data['signed_name'],
                'unavailable',
                'panel_upload',
                $data['uploaded_note'],
                $data['uploaded_by'],
            ]
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

    /** @return list<array<string,mixed>> */
    public static function signaturesForLoanAccount(int $loanAccountId): array
    {
        return Database::instance()->all(
            'SELECT s.*, vr.visit_date
               FROM signatures s
               LEFT JOIN visit_reports vr ON vr.id = s.visit_report_id
              WHERE s.loan_account_id = ?
              ORDER BY s.created_at DESC
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
