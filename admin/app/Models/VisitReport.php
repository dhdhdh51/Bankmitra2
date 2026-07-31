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
            'SELECT vr.id, vr.visit_date, vr.visit_time, vr.created_at, vr.agent_name, vr.village,
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
            "SELECT vr.id, vr.loan_account_id, vr.loan_account_number, vr.customer_name, vr.village,
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
